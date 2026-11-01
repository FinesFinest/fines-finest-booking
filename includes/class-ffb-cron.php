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
	 * So viele Stunden vor dem Termin oeffnet sich das Erinnerungsfenster.
	 */
	const REMINDER_WINDOW_HOURS = 25;

	/**
	 * Braucht eine Buchung mit diesem Start noch eine Erinnerung?
	 *
	 * Nein, wenn der Termin schon innerhalb des Erinnerungsfensters liegt: Die
	 * Bestaetigung ist dann gerade erst angekommen, eine zweite Mail eine Stunde
	 * spaeter waere nur laestig.
	 *
	 * @param DateTimeInterface $start Start des Termins.
	 * @param int|null          $now   Jetzt als Zeitstempel, nur fuer Tests.
	 * @return bool
	 */
	public static function reminder_needed( $start, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		return ( $start->getTimestamp() - $now ) >= self::REMINDER_WINDOW_HOURS * HOUR_IN_SECONDS;
	}

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
	 * Verschickt Erinnerungen fuer Termine innerhalb der naechsten 25 Stunden.
	 */
	public static function send_reminders() {
		global $wpdb;

		$table = FFB_Install::table_bookings();

		/*
		 * Untere Grenze ist bewusst "jetzt" und nicht "in 24 Stunden": WP-Cron laeuft nur,
		 * wenn die Seite Besuch bekommt, und faellt dadurch regelmaessig aus dem Takt.
		 * Ein festes Fenster wuerde Termine still ueberspringen. Das Feld reminder_sent
		 * verhindert Doppelversand. Buchungen, die erst innerhalb des Fensters entstehen,
		 * markiert FFB_Bookings::create() sofort als erinnert (siehe reminder_needed()).
		 */
		$from = ffb_now_utc();
		$to   = ffb_now_utc()->modify( '+' . self::REMINDER_WINDOW_HOURS . ' hours' );

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
				// Nicht still ueberspringen: einmal melden und die Buchung als erledigt markieren.
				FFB_Google::log(
					sprintf(
						/* translators: %d: booking ID */
						__( 'Reminder for booking #%d not sent: the appointment type no longer exists.', 'fines-finest-booking' ),
						(int) $booking['id']
					)
				);
				self::mark_reminder_sent( (int) $booking['id'] );
				continue;
			}

			FFB_Mailer::send_reminder( $booking, $event );
			self::mark_reminder_sent( (int) $booking['id'] );
		}
	}

	/**
	 * Merkt sich, dass die Erinnerung zu einer Buchung erledigt ist.
	 *
	 * @param int $booking_id Buchungs-ID.
	 */
	protected static function mark_reminder_sent( $booking_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			FFB_Install::table_bookings(),
			array( 'reminder_sent' => 1 ),
			array( 'id' => (int) $booking_id ),
			array( '%d' ),
			array( '%d' )
		);
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

		// Reste der Buchungssperre ueber Optionen, die bis Version 1.1.1 genutzt wurde.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ffb_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB

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
