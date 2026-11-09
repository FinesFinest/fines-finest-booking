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
class FFBooking_Availability {

	/**
	 * Liefert freie Slots, nach Tagen gruppiert.
	 *
	 * Holt belegte Zeiten und Buchungszahlen aus Datenbank und Google und
	 * ueberlaesst die Rechnung compute(), die ohne beides auskommt und darum
	 * in den Tests mit festen Werten laeuft.
	 *
	 * @param array                  $event Termintyp.
	 * @param string                 $from  Startdatum Y-m-d in Website-Zeitzone, leer fuer heute.
	 * @param int                    $days  Anzahl Tage.
	 * @param DateTimeInterface|null $now   Jetzt, nur fuer Tests; sonst die aktuelle Zeit.
	 * @return array|WP_Error Fehler, wenn belegte Zeiten nicht ermittelt werden konnten.
	 */
	public static function get_slots( $event, $from = '', $days = 14, $now = null ) {
		$tz  = ffbooking_timezone();
		$now = $now instanceof DateTimeInterface
			? ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $tz )
			: new DateTimeImmutable( 'now', $tz );

		$range       = self::range( $event, $now, $from, $days );
		$busy        = array();
		$daily_count = array();

		if ( $range['end'] > $range['start'] ) {
			$utc        = new DateTimeZone( 'UTC' );
			$range_from = $range['start']->setTimezone( $utc );
			$range_to   = $range['end']->setTimezone( $utc );

			// Die Puffer eines Slots am Tagesrand reichen ueber den Zeitraum hinaus,
			// darum werden belegte Zeiten um sie erweitert abgefragt.
			$busy_from = new DateTimeImmutable( '@' . ( $range_from->getTimestamp() - max( 0, (int) $event['buffer_before'] ) * 60 ) );
			$busy_to   = new DateTimeImmutable( '@' . ( $range_to->getTimestamp() + max( 0, (int) $event['buffer_after'] ) * 60 ) );

			$busy = self::collect_busy( $busy_from, $busy_to );
			if ( is_wp_error( $busy ) ) {
				// Ohne Wissen ueber belegte Zeiten lieber keine Slots als falsche.
				return $busy;
			}

			$daily_count = self::count_per_day( $range_from, $range_to, $tz, (int) $event['id'] );
		}

		return self::compute( $event, $now, $range, $busy, $daily_count, self::global_limits() );
	}

	/**
	 * Bestimmt den Zeitraum, fuer den Slots berechnet werden.
	 *
	 * Beginn ist das gewuenschte Datum, fruehestens heute. Das Ende ist exklusiv
	 * und reicht hoechstens bis zum Buchungshorizont des Termintyps.
	 *
	 * @param array             $event Termintyp mit max_days.
	 * @param DateTimeImmutable $now   Jetzt in Website-Zeitzone.
	 * @param string            $from  Wunsch-Startdatum Y-m-d, leer fuer heute.
	 * @param int               $days  Anzahl Tage, wird auf 1 bis 60 begrenzt.
	 * @return array start, end und horizon als DateTimeImmutable um 0:00 Uhr in Website-Zeitzone.
	 */
	public static function range( $event, $now, $from = '', $days = 14 ) {
		$tz   = $now->getTimezone();
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

		return array(
			'start'   => $start_day,
			'end'     => $end_day,
			'horizon' => $horizon,
		);
	}

	/**
	 * Die eigentliche Slot-Berechnung. Kennt weder Datenbank noch Google.
	 *
	 * @param array             $event       Termintyp: availability, blackouts, duration, slot_step,
	 *                                       buffer_before, buffer_after, min_notice, daily_limit.
	 * @param DateTimeImmutable $now         Jetzt in Website-Zeitzone.
	 * @param array             $range       Ergebnis von range().
	 * @param array             $busy        Belegte Zeitraeume als [start_ts, end_ts], UTC-Zeitstempel. Eigene
	 *                                       Buchungen bereits um ihre Puffer verlaengert, siehe booked_spans().
	 * @param array             $daily_count Bestaetigte Buchungen dieses Termintyps je Tag, Y-m-d => Anzahl.
	 * @param array             $global      Globale Grenzen wie aus global_limits(): enforce, hours, min_notice, blackouts.
	 * @return array days (date, slots), timezone, horizon.
	 */
	public static function compute( $event, $now, $range, $busy, $daily_count, $global ) {
		$tz     = $now->getTimezone();
		$utc    = new DateTimeZone( 'UTC' );
		$result = array();

		if ( $range['end'] > $range['start'] ) {
			// Globale Grenzen gelten fuer alle Termintypen und koennen nur weiter einschraenken.
			$notice   = max( (int) $event['min_notice'], (int) $global['min_notice'] );
			$earliest = $now->modify( '+' . $notice . ' minutes' );
			$cursor   = $range['start'];

			while ( $cursor < $range['end'] ) {
				$date_key = $cursor->format( 'Y-m-d' );
				$weekday  = (string) (int) $cursor->format( 'N' );
				$windows  = isset( $event['availability'][ $weekday ] ) ? $event['availability'][ $weekday ] : array();

				if ( empty( $windows ) || in_array( $date_key, (array) $event['blackouts'], true ) || in_array( $date_key, (array) $global['blackouts'], true ) ) {
					$cursor = $cursor->modify( '+1 day' );
					continue;
				}

				if ( ! empty( $global['enforce'] ) ) {
					$windows = self::intersect_windows( $windows, isset( $global['hours'][ $weekday ] ) ? $global['hours'][ $weekday ] : array() );

					if ( empty( $windows ) ) {
						$cursor = $cursor->modify( '+1 day' );
						continue;
					}
				}

				$booked_today = isset( $daily_count[ $date_key ] ) ? (int) $daily_count[ $date_key ] : 0;
				if ( (int) $event['daily_limit'] > 0 && $booked_today >= (int) $event['daily_limit'] ) {
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
		}

		return array(
			'days'     => $result,
			'timezone' => $tz->getName(),
			'horizon'  => $range['horizon']->format( 'Y-m-d' ),
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
			$hours[ (string) $day ] = FFBooking_Admin::parse_windows( ffbooking_get_setting( 'hours_' . $day, '' ) );
		}

		return array(
			'enforce'    => (int) ffbooking_get_setting( 'hours_enforce', 0 ) ? true : false,
			'hours'      => $hours,
			'min_notice' => max( 0, (int) ffbooking_get_setting( 'global_min_notice', 0 ) ),
			'blackouts'  => FFBooking_Event_Types::clean_blackouts( ffbooking_get_setting( 'global_blackouts', '' ) ),
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
		$seen     = array();
		$duration = (int) $event['duration'];
		$step     = max( 5, (int) $event['slot_step'] );
		$earliest = $earliest->getTimestamp();

		/*
		 * Ein Slot braucht seinen Puffer davor und seinen Puffer danach frei. Eigene
		 * Buchungen kommen aus collect_busy() schon um ihre eigenen Puffer verlaengert,
		 * Google-Eintraege so, wie sie im Kalender stehen. Zwischen zwei Buchungen
		 * zaehlen damit beide Puffer zusammen: Nach einem Termin 8:00 bis 8:30 mit
		 * 15 Minuten danach und 30 Minuten davor ist 9:15 der erste freie Slot.
		 */
		$before = max( 0, (int) $event['buffer_before'] ) * 60;
		$after  = max( 0, (int) $event['buffer_after'] ) * 60;

		foreach ( $windows as $window ) {
			$start_minute = self::minutes_of_day( $window[0] );
			$end_minute   = self::minutes_of_day( $window[1] );

			if ( null === $start_minute || null === $end_minute ) {
				continue;
			}

			/*
			 * Gerechnet wird in Wanduhr-Minuten des Tages, jede Uhrzeit wird einzeln
			 * aufgeloest. So haengt das Ergebnis nicht davon ab, wie PHP "+30 Minuten"
			 * ueber einen Sommerzeitwechsel hinweg deutet. Eine fehlende Stunde springt
			 * vor, eine doppelte Stunde wird einmal angeboten, kein Zeitpunkt doppelt.
			 */
			for ( $minute = $start_minute; $minute + $duration <= $end_minute; $minute += $step ) {
				$cursor        = $day->setTime( intdiv( $minute, 60 ), $minute % 60, 0 );
				$slot_start_ts = $cursor->getTimestamp();
				$slot_end_ts   = $slot_start_ts + $duration * 60;

				if ( isset( $seen[ $slot_start_ts ] ) || $slot_start_ts < $earliest ) {
					continue;
				}
				$seen[ $slot_start_ts ] = true;

				if ( ! self::collides( $slot_start_ts - $before, $slot_end_ts + $after, $busy ) ) {
					$slots[] = array(
						'start' => gmdate( 'Y-m-d\TH:i:s\Z', $slot_start_ts ),
						'label' => $cursor->format( 'H:i' ),
					);
				}
			}
		}

		return $slots;
	}

	/**
	 * Wandelt "HH:MM" in Minuten seit Mitternacht.
	 *
	 * @param string $time HH:MM.
	 * @return int|null
	 */
	protected static function minutes_of_day( $time ) {
		$parts = explode( ':', (string) $time );
		if ( count( $parts ) !== 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}

		return (int) $parts[0] * 60 + (int) $parts[1];
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
	 * @return array|WP_Error Fehler, wenn Google verbunden ist, aber nicht antwortet.
	 */
	protected static function collect_busy( $from, $to ) {
		$busy = self::booked_spans( $from, $to );

		$google = FFBooking_Google::get_busy( $from, $to );
		if ( is_wp_error( $google ) ) {
			return $google;
		}

		foreach ( $google as $block ) {
			$busy[] = $block;
		}

		return $busy;
	}

	/**
	 * Belegte Zeitraeume eigener Buchungen, jede um die Puffer ihres Termintyps verlaengert.
	 *
	 * Die Abfrage reicht um den groessten Puffer aller Termintypen ueber den Zeitraum
	 * hinaus. So zaehlt auch eine Buchung, die selbst davor endet, deren Puffer aber
	 * hineinragt.
	 *
	 * @param DateTimeInterface $from Start (UTC).
	 * @param DateTimeInterface $to   Ende (UTC).
	 * @return array Liste aus [start_ts, end_ts].
	 */
	public static function booked_spans( $from, $to ) {
		$types  = array();
		$margin = 0;

		foreach ( FFBooking_Event_Types::all() as $type ) {
			$types[ (int) $type['id'] ] = $type;
			$margin                     = max( $margin, (int) $type['buffer_before'], (int) $type['buffer_after'] );
		}

		$query_from = new DateTimeImmutable( '@' . ( $from->getTimestamp() - $margin * 60 ) );
		$query_to   = new DateTimeImmutable( '@' . ( $to->getTimestamp() + $margin * 60 ) );

		$spans = array();
		foreach ( FFBooking_Bookings::in_range( $query_from, $query_to ) as $booking ) {
			$type_id = (int) $booking['event_type_id'];
			$spans[] = self::booking_span( $booking, isset( $types[ $type_id ] ) ? $types[ $type_id ] : null );
		}

		return $spans;
	}

	/**
	 * Zeitraum, den eine Buchung samt der Puffer ihres Termintyps belegt.
	 *
	 * @param array      $booking Buchung mit start_utc und end_utc.
	 * @param array|null $type    Termintyp mit buffer_before und buffer_after; null, wenn es ihn nicht mehr gibt.
	 * @return array [start_ts, end_ts].
	 */
	public static function booking_span( $booking, $type ) {
		$before = $type ? max( 0, (int) $type['buffer_before'] ) * 60 : 0;
		$after  = $type ? max( 0, (int) $type['buffer_after'] ) * 60 : 0;

		return array(
			strtotime( $booking['start_utc'] . ' UTC' ) - $before,
			strtotime( $booking['end_utc'] . ' UTC' ) + $after,
		);
	}

	/**
	 * Prueft, ob ein Termin samt Puffer mit einer bestehenden Buchung samt deren Puffer kollidiert.
	 *
	 * Laeuft in FFBooking_Bookings::create() unter der Buchungssperre. Zwei gleichzeitige
	 * Buchungen bestehen sonst beide die Slotpruefung, weil keine die andere sieht.
	 *
	 * @param array             $event Termintyp.
	 * @param DateTimeInterface $start Start (UTC).
	 * @param DateTimeInterface $end   Ende (UTC).
	 * @return bool
	 */
	public static function conflicts_with_bookings( $event, $start, $end ) {
		$from = new DateTimeImmutable( '@' . ( $start->getTimestamp() - max( 0, (int) $event['buffer_before'] ) * 60 ) );
		$to   = new DateTimeImmutable( '@' . ( $end->getTimestamp() + max( 0, (int) $event['buffer_after'] ) * 60 ) );

		return self::collides( $from->getTimestamp(), $to->getTimestamp(), self::booked_spans( $from, $to ) );
	}

	/**
	 * Zaehlt bestaetigte Buchungen eines Termintyps pro Kalendertag.
	 *
	 * Das Tageslimit wird pro Termintyp eingestellt, also zaehlen hier auch nur
	 * Buchungen dieses Typs. Buchungen anderer Typen blocken ueber die belegten Zeiten.
	 *
	 * @param DateTimeInterface $from          Start (UTC).
	 * @param DateTimeInterface $to            Ende (UTC).
	 * @param DateTimeZone      $tz            Website-Zeitzone.
	 * @param int               $event_type_id Termintyp.
	 * @return array
	 */
	protected static function count_per_day( $from, $to, $tz, $event_type_id ) {
		$counts = array();

		foreach ( FFBooking_Bookings::in_range( $from, $to, (int) $event_type_id ) as $booking ) {
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
		$tz    = ffbooking_timezone();
		$local = $start->setTimezone( $tz );
		$data  = self::get_slots( $event, $local->format( 'Y-m-d' ), 1 );
		$want  = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		foreach ( $data['days'] as $day ) {
			foreach ( $day['slots'] as $slot ) {
				if ( $slot['start'] === $want ) {
					return true;
				}
			}
		}

		return new WP_Error( 'ffbooking_slot_gone', __( 'This slot is no longer available. Please pick another one.', 'fines-finest-booking' ) );
	}
}
