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
	 * @param DateTimeInterface $from          Start (UTC).
	 * @param DateTimeInterface $to            Ende (UTC).
	 * @param int               $event_type_id Nur dieser Termintyp; 0 fuer alle.
	 * @return array
	 */
	public static function in_range( $from, $to, $event_type_id = 0 ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		if ( $event_type_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND event_type_id = %d AND end_utc > %s AND start_utc < %s ORDER BY start_utc ASC", // phpcs:ignore WordPress.DB
				(int) $event_type_id,
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND end_utc > %s AND start_utc < %s ORDER BY start_utc ASC", // phpcs:ignore WordPress.DB
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

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
	 * Eine Datenbanksperre sorgt dafuer, dass zwei gleichzeitige Buchungen nicht denselben Slot bekommen.
	 *
	 * @param array             $event Termintyp.
	 * @param DateTimeImmutable $start Start (UTC).
	 * @param array             $data  Formulardaten.
	 * @return int|WP_Error
	 */
	public static function create( $event, $start, $data ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
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
			self::release_lock();
			return new WP_Error( 'ffb_taken', __( 'This slot has just been taken. Please pick another one.', 'fines-finest-booking' ) );
		}

		$token = bin2hex( random_bytes( 24 ) );

		// Wer kurzfristig bucht, hat die Bestaetigung gerade erst bekommen und braucht keine Erinnerung mehr.
		$reminder_sent = FFB_Cron::reminder_needed( $start ) ? 0 : 1;

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
				'reminder_sent' => $reminder_sent,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		self::release_lock();

		if ( ! $inserted ) {
			return new WP_Error( 'ffb_db', __( 'The booking could not be saved.', 'fines-finest-booking' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Name der Datenbanksperre. Enthaelt Datenbank und Tabellenpraefix, damit sich
	 * mehrere WordPress-Installationen auf demselben Datenbankserver nicht gegenseitig blockieren.
	 *
	 * @return string
	 */
	protected static function lock_name() {
		global $wpdb;

		return 'ffb_booking_' . md5( $wpdb->dbname . '|' . $wpdb->prefix );
	}

	/**
	 * Holt die Sperre fuer das Anlegen einer Buchung.
	 *
	 * Eine benannte MySQL-Sperre (GET_LOCK) ist atomar, gilt ueber alle Termintypen
	 * hinweg und wird von der Datenbank selbst freigegeben, wenn der Request abbricht.
	 * Eine Sperre ueber add_option() reicht dafuer nicht: WordPress schreibt dort mit
	 * INSERT ... ON DUPLICATE KEY UPDATE, zwei gleichzeitige Aufrufe melden also beide Erfolg.
	 *
	 * @return true|WP_Error
	 */
	protected static function acquire_lock() {
		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), 5 ) ); // phpcs:ignore WordPress.DB

		if ( null === $result ) {
			// Die Datenbank liefert keine Sperre. Buchen geht weiter, aber nicht still.
			FFB_Google::log(
				sprintf(
					/* translators: %s: database error message in parentheses, may be empty */
					__( 'Booking lock unavailable%s. The booking was saved without a lock.', 'fines-finest-booking' ),
					$wpdb->last_error ? ' (' . $wpdb->last_error . ')' : ''
				)
			);
			return true;
		}

		if ( 1 === (int) $result ) {
			return true;
		}

		return new WP_Error( 'ffb_locked', __( 'Someone else is booking this slot right now. Please try again in a moment.', 'fines-finest-booking' ) );
	}

	/**
	 * Gibt die Sperre wieder frei.
	 */
	protected static function release_lock() {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB
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
	 * @param bool $notify_client Kund*in benachrichtigen.
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
	 * Zaehlt bestaetigte, kuenftige Buchungen eines Termintyps.
	 *
	 * @param int $event_type_id Termintyp-ID.
	 * @return int
	 */
	public static function count_upcoming( $event_type_id ) {
		global $wpdb;
		$table = FFB_Install::table_bookings();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'confirmed' AND event_type_id = %d AND start_utc > %s",
				(int) $event_type_id,
				ffb_now_utc()->format( 'Y-m-d H:i:s' )
			)
		);
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
