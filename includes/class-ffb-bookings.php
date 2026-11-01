<?php
/**
 * Buchungen: lesen, anlegen, stornieren.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datenzugriff fuer Buchungen.
 */
class FFB_Bookings {

	/**
	 * Bestaetigte Buchungen in einem Zeitraum.
	 *
	 * @param DateTimeInterface $from Start (UTC).
	 * @param DateTimeInterface $to   Ende (UTC).
	 * @return array
	 */
	public static function in_range( $from, $to ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND end_utc > %s AND start_utc < %s ORDER BY start_utc ASC",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Buchung per ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Buchung per Storno-Token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();
		$token = preg_replace( '/[^a-f0-9]/i', '', (string) $token );

		if ( strlen( $token ) < 20 ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Prueft, ob ein Slot noch frei ist, und legt die Buchung an.
	 * Nutzt einen Lock, damit zwei gleichzeitige Buchungen nicht denselben Slot bekommen.
	 *
	 * @param array             $event Termintyp.
	 * @param DateTimeImmutable $start Start (UTC).
	 * @param array             $data  Formulardaten.
	 * @return int|WP_Error
	 */
	public static function create( $event, $start, $data ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		/*
		 * Sperre ueber add_option. Der Optionsname hat einen eindeutigen Index, deshalb
		 * scheitert der zweite gleichzeitige Aufruf zuverlaessig. Eine liegengebliebene
		 * Sperre aus einem abgebrochenen Request wird nach 30 Sekunden uebernommen.
		 */
		$lock_key = 'ffb_lock_' . md5( $event['id'] . $start->format( 'c' ) );
		$now      = time();

		if ( false === add_option( $lock_key, $now, '', 'no' ) ) {
			$held = (int) get_option( $lock_key );

			if ( $held > $now - 30 ) {
				return new WP_Error( 'ffb_locked', 'Der Termin wird gerade von jemand anderem gebucht. Bitte waehle einen anderen Slot.' );
			}

			update_option( $lock_key, $now, false );
		}

		$end = $start->modify( '+' . (int) $event['duration'] . ' minutes' );

		$conflict = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND start_utc < %s AND end_utc > %s",
				$end->format( 'Y-m-d H:i:s' ),
				$start->format( 'Y-m-d H:i:s' )
			)
		);

		if ( $conflict > 0 ) {
			delete_option( $lock_key );
			return new WP_Error( 'ffb_taken', 'Dieser Termin ist inzwischen vergeben. Bitte waehle einen anderen.' );
		}

		$token = bin2hex( random_bytes( 24 ) );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'event_type_id' => (int) $event['id'],
				'start_utc'     => $start->format( 'Y-m-d H:i:s' ),
				'end_utc'       => $end->format( 'Y-m-d H:i:s' ),
				'name'          => $data['name'],
				'email'         => $data['email'],
				'phone'         => $data['phone'],
				'answers'       => wp_json_encode( $data['answers'] ),
				'status'        => 'confirmed',
				'token'         => $token,
				'source'        => isset( $data['source'] ) ? $data['source'] : '',
				'consent_at'    => current_time( 'mysql', true ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		delete_option( $lock_key );

		if ( ! $inserted ) {
			return new WP_Error( 'ffb_db', 'Die Buchung konnte nicht gespeichert werden.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Schreibt Google-Daten an eine Buchung.
	 *
	 * @param int    $id       Buchungs-ID.
	 * @param string $event_id Google-Event-ID.
	 * @param string $meet_url Meet-Link.
	 */
	public static function set_google( $id, $event_id, $meet_url ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			FFB_Install::table_bookings(),
			array(
				'google_event_id' => (string) $event_id,
				'meet_url'        => esc_url_raw( (string) $meet_url ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Storniert eine Buchung inklusive Google-Termin.
	 *
	 * @param int  $id            Buchungs-ID.
	 * @param bool $notify_client Kundin benachrichtigen.
	 * @return bool
	 */
	public static function cancel( $id, $notify_client = true ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking || 'cancelled' === $booking['status'] ) {
			return false;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB
			FFB_Install::table_bookings(),
			array( 'status' => 'cancelled' ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( ! empty( $booking['google_event_id'] ) ) {
			FFB_Google::delete_event( $booking['google_event_id'] );
		}

		if ( $notify_client ) {
			FFB_Mailer::send_cancellation( $booking );
		}

		return true;
	}

	/**
	 * Buchungen fuer die Adminliste.
	 *
	 * @param string $scope    upcoming, past oder cancelled.
	 * @param int    $per_page Eintraege pro Seite.
	 * @param int    $page     Seite.
	 * @return array
	 */
	public static function query( $scope = 'upcoming', $per_page = 30, $page = 1 ) {
		global $wpdb;
		$table    = FFB_Install::table_bookings();
		$now      = ffb_now_utc()->format( 'Y-m-d H:i:s' );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = max( 0, ( (int) $page - 1 ) * $per_page );

		if ( 'past' === $scope ) {
			$where = $wpdb->prepare( "status = 'confirmed' AND start_utc < %s", $now );
			$order = 'start_utc DESC';
		} elseif ( 'cancelled' === $scope ) {
			$where = "status = 'cancelled'";
			$order = 'start_utc DESC';
		} else {
			$where = $wpdb->prepare( "status = 'confirmed' AND start_utc >= %s", $now );
			$order = 'start_utc ASC';
		}

		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT {$per_page} OFFSET {$offset}", ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}
}
