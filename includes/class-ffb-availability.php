<?php
/**
 * Berechnet freie Termine.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slot-Logik: Wochenzeiten minus Buchungen minus Google-Kalender.
 */
class FFB_Availability {

	/**
	 * Liefert freie Slots, nach Tagen gruppiert.
	 *
	 * @param array  $event Termintyp.
	 * @param string $from  Startdatum Y-m-d in Website-Zeitzone.
	 * @param int    $days  Anzahl Tage.
	 * @return array
	 */
	public static function get_slots( $event, $from = '', $days = 14 ) {
		$tz  = ffb_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$days = min( 60, max( 1, (int) $days ) );

		try {
			$start_day = $from ? new DateTimeImmutable( $from . ' 00:00:00', $tz ) : $now->setTime( 0, 0 );
		} catch ( Exception $e ) {
			$start_day = $now->setTime( 0, 0 );
		}

		$today = $now->setTime( 0, 0 );
		if ( $start_day < $today ) {
			$start_day = $today;
		}

		$horizon = $today->modify( '+' . (int) $event['max_days'] . ' days' );
		$end_day = $start_day->modify( '+' . $days . ' days' );
		if ( $end_day > $horizon ) {
			$end_day = $horizon;
		}

		if ( $end_day <= $start_day ) {
			return array(
				'days'     => array(),
				'timezone' => $tz->getName(),
				'horizon'  => $horizon->format( 'Y-m-d' ),
			);
		}

		$utc        = new DateTimeZone( 'UTC' );
		$range_from = $start_day->setTimezone( $utc );
		$range_to   = $end_day->setTimezone( $utc );

		$busy = self::collect_busy( $range_from, $range_to );

		// Globale Grenzen gelten fuer alle Termintypen und koennen nur weiter einschraenken.
		$global          = self::global_limits();
		$notice          = max( (int) $event['min_notice'], $global['min_notice'] );
		$earliest        = $now->modify( '+' . $notice . ' minutes' );
		$daily_count     = self::count_per_day( $range_from, $range_to, $tz );

		$result = array();
		$cursor = $start_day;

		while ( $cursor < $end_day ) {
			$date_key = $cursor->format( 'Y-m-d' );
			$weekday  = (string) (int) $cursor->format( 'N' );
			$windows  = isset( $event['availability'][ $weekday ] ) ? $event['availability'][ $weekday ] : array();

			if ( empty( $windows ) || in_array( $date_key, $event['blackouts'], true ) ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			if ( in_array( $date_key, $global['blackouts'], true ) ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			if ( $global['enforce'] ) {
				$windows = self::intersect_windows( $windows, $global['hours'][ $weekday ] );

				if ( empty( $windows ) ) {
					$cursor = $cursor->modify( '+1 day' );
					continue;
				}
			}

			$booked_today = isset( $daily_count[ $date_key ] ) ? (int) $daily_count[ $date_key ] : 0;
			if ( $event['daily_limit'] > 0 && $booked_today >= $event['daily_limit'] ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			$open = self::slots_for_day( $event, $cursor, $windows, $busy, $earliest, $utc );

			if ( ! empty( $open ) ) {
				$result[] = array(
					'date'  => $date_key,
					'slots' => $open,
				);
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'days'     => $result,
			'timezone' => $tz->getName(),
			'horizon'  => $horizon->format( 'Y-m-d' ),
		);
	}

	/**
	 * Liest die globalen Grenzen aus den Einstellungen.
	 *
	 * @return array
	 */
	public static function global_limits() {
		$hours = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			$hours[ (string) $day ] = FFB_Admin::parse_windows( ffb_get_setting( 'hours_' . $day, '' ) );
		}

		return array(
			'enforce'    => (int) ffb_get_setting( 'hours_enforce', 0 ) ? true : false,
			'hours'      => $hours,
			'min_notice' => max( 0, (int) ffb_get_setting( 'global_min_notice', 0 ) ),
			'blackouts'  => FFB_Event_Types::clean_blackouts( ffb_get_setting( 'global_blackouts', '' ) ),
		);
	}

	/**
	 * Schneidet zwei Mengen von Zeitfenstern. Ergebnis ist die Schnittmenge,
	 * also nie mehr Zeit als in beiden Mengen vorhanden.
	 *
	 * @param array $a Fenster des Termintyps.
	 * @param array $b Globale Geschaeftszeiten.
	 * @return array
	 */
	public static function intersect_windows( $a, $b ) {
		$out = array();

		foreach ( (array) $a as $eins ) {
			foreach ( (array) $b as $zwei ) {
				$start = max( $eins[0], $zwei[0] );
				$ende  = min( $eins[1], $zwei[1] );

				if ( $start < $ende ) {
					$out[] = array( $start, $ende );
				}
			}
		}

		usort(
			$out,
			static function ( $x, $y ) {
				return strcmp( $x[0], $y[0] );
			}
		);

		return $out;
	}

	/**
	 * Slots eines einzelnen Tages.
	 *
	 * @param array             $event    Termintyp.
	 * @param DateTimeImmutable $day      Tag in Website-Zeitzone.
	 * @param array             $windows  Zeitfenster.
	 * @param array             $busy     Belegte Zeitraeume (UTC-Timestamps).
	 * @param DateTimeImmutable $earliest Fruehestmoeglicher Start.
	 * @param DateTimeZone      $utc      UTC.
	 * @return array
	 */
	protected static function slots_for_day( $event, $day, $windows, $busy, $earliest, $utc ) {
		$slots    = array();
		$duration = (int) $event['duration'];
		$step     = max( 5, (int) $event['slot_step'] );

		/*
		 * Der noetige Abstand zu einem belegten Zeitraum ergibt sich aus beiden Puffern:
		 * der Puffer nach dem vorherigen Termin und der Puffer vor diesem hier. Da alle
		 * Termine eines Typs dieselben Werte haben, ist der groessere Wert der richtige.
		 */
		$pad = max( (int) $event['buffer_before'], (int) $event['buffer_after'] ) * 60;

		foreach ( $windows as $window ) {
			list( $win_start, $win_end ) = $window;

			$start = self::apply_time( $day, $win_start );
			$stop  = self::apply_time( $day, $win_end );

			if ( ! $start || ! $stop ) {
				continue;
			}

			$cursor = $start;

			while ( true ) {
				$slot_end = $cursor->modify( '+' . $duration . ' minutes' );
				if ( $slot_end > $stop ) {
					break;
				}

				if ( $cursor >= $earliest ) {
					$slot_start_ts = $cursor->setTimezone( $utc )->getTimestamp();
					$slot_end_ts   = $slot_end->setTimezone( $utc )->getTimestamp();

					if ( ! self::collides( $slot_start_ts - $pad, $slot_end_ts + $pad, $busy ) ) {
						$slots[] = array(
							'start' => gmdate( 'Y-m-d\TH:i:s\Z', $slot_start_ts ),
							'label' => $cursor->format( 'H:i' ),
						);
					}
				}

				$cursor = $cursor->modify( '+' . $step . ' minutes' );
			}
		}

		return $slots;
	}

	/**
	 * Setzt eine Uhrzeit auf einen Tag. Beachtet Sommerzeitspruenge.
	 *
	 * @param DateTimeImmutable $day  Tag.
	 * @param string            $time HH:MM.
	 * @return DateTimeImmutable|null
	 */
	protected static function apply_time( $day, $time ) {
		$parts = explode( ':', $time );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		return $day->setTime( (int) $parts[0], (int) $parts[1], 0 );
	}

	/**
	 * Prueft Ueberschneidung mit belegten Zeitraeumen.
	 *
	 * @param int   $start Start-Timestamp.
	 * @param int   $end   End-Timestamp.
	 * @param array $busy  Belegungen.
	 * @return bool
	 */
	protected static function collides( $start, $end, $busy ) {
		foreach ( $busy as $block ) {
			if ( $start < $block[1] && $end > $block[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sammelt belegte Zeitraeume aus eigenen Buchungen und aus Google.
	 *
	 * @param DateTimeInterface $from Start (UTC).
	 * @param DateTimeInterface $to   Ende (UTC).
	 * @return array
	 */
	protected static function collect_busy( $from, $to ) {
		$busy = array();

		foreach ( FFB_Bookings::in_range( $from, $to ) as $booking ) {
			$busy[] = array(
				strtotime( $booking['start_utc'] . ' UTC' ),
				strtotime( $booking['end_utc'] . ' UTC' ),
			);
		}

		foreach ( FFB_Google::get_busy( $from, $to ) as $block ) {
			$busy[] = $block;
		}

		return $busy;
	}

	/**
	 * Zaehlt bestaetigte Buchungen pro Kalendertag.
	 *
	 * @param DateTimeInterface $from Start (UTC).
	 * @param DateTimeInterface $to   Ende (UTC).
	 * @param DateTimeZone      $tz   Website-Zeitzone.
	 * @return array
	 */
	protected static function count_per_day( $from, $to, $tz ) {
		$counts = array();

		foreach ( FFB_Bookings::in_range( $from, $to ) as $booking ) {
			$local = ( new DateTimeImmutable( $booking['start_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$key   = $local->format( 'Y-m-d' );

			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}

		return $counts;
	}

	/**
	 * Prueft, ob ein konkreter Startzeitpunkt buchbar ist.
	 *
	 * @param array             $event Termintyp.
	 * @param DateTimeImmutable $start Start (UTC).
	 * @return true|WP_Error
	 */
	public static function validate_slot( $event, $start ) {
		$tz    = ffb_timezone();
		$local = $start->setTimezone( $tz );
		$data  = self::get_slots( $event, $local->format( 'Y-m-d' ), 1 );
		$want  = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		foreach ( $data['days'] as $day ) {
			foreach ( $day['slots'] as $slot ) {
				if ( $slot['start'] === $want ) {
					return true;
				}
			}
		}

		return new WP_Error( 'ffb_slot_gone', 'Dieser Termin ist nicht mehr verfuegbar. Bitte waehle einen anderen.' );
	}
}
