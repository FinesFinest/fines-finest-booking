<?php
/**
 * Geplante Aufgaben: Erinnerungen und Aufraeumen.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron-Anbindung.
 */
class FFB_Cron {

	const HOOK_REMINDERS = 'ffb_send_reminders';
	const HOOK_CLEANUP   = 'ffb_cleanup';

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( self::HOOK_REMINDERS, array( __CLASS__, 'send_reminders' ) );
		add_action( self::HOOK_CLEANUP, array( __CLASS__, 'cleanup' ) );

		if ( ! wp_next_scheduled( self::HOOK_REMINDERS ) ) {
			self::schedule();
		}
	}

	/**
	 * Aufgaben einplanen.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_REMINDERS ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK_REMINDERS );
		}
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 600, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Aufgaben entfernen.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_REMINDERS );
		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
	}

	/**
	 * Verschickt Erinnerungen fuer Termine in 24 bis 25 Stunden.
	 */
	public static function send_reminders() {
		global $wpdb;

		$table = FFB_Install::table_bookings();

		/*
		 * Untere Grenze ist bewusst "jetzt" und nicht "in 24 Stunden": WP-Cron laeuft nur,
		 * wenn die Seite Besuch bekommt, und faellt dadurch regelmaessig aus dem Takt.
		 * Ein festes Fenster wuerde Termine still ueberspringen. Das Feld reminder_sent
		 * verhindert Doppelversand.
		 */
		$from = ffb_now_utc();
		$to   = ffb_now_utc()->modify( '+25 hours' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'confirmed' AND reminder_sent = 0 AND start_utc > %s AND start_utc < %s",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $booking ) {
			$event = FFB_Event_Types::get( $booking['event_type_id'] );
			if ( ! $event ) {
				continue;
			}

			FFB_Mailer::send_reminder( $booking, $event );

			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array( 'reminder_sent' => 1 ),
				array( 'id' => (int) $booking['id'] ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Loescht alte Buchungen und temporaere Dateien.
	 */
	public static function cleanup() {
		global $wpdb;

		$days = (int) ffb_get_setting( 'retention_days', 730 );

		if ( $days > 0 ) {
			$cutoff = ffb_now_utc()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
			$table  = FFB_Install::table_bookings();

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE start_utc < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
		}

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'ffb-tmp';

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.ics' ) as $file ) {
				if ( is_file( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
					@unlink( $file ); // phpcs:ignore
				}
			}
		}
	}
}
