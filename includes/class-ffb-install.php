<?php
/**
 * Installation, Tabellen, Upgrade.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legt Tabellen an, pflegt das Schema und stellt aeltere Installationen um.
 */
class FFBooking_Install {

	/**
	 * Schemaversion-Option bis Version 1.2.6. Wird nur noch gelesen, um eine
	 * bestehende Installation auf das Praefix ffbooking umzustellen.
	 */
	const LEGACY_DB_VERSION_OPTION = 'ffb_db_version';

	/**
	 * Option mit dem Grund, wenn die Umstellung der Tabellen nicht gelang.
	 */
	const MIGRATION_ERROR_OPTION = 'ffbooking_migration_error';

	/**
	 * Option fuer den Hinweis auf die geaenderte Weiterleitungs-URI der Google-Verbindung.
	 */
	const REDIRECT_NOTICE_OPTION = 'ffbooking_notice_redirect_uri';

	/**
	 * Tabellenname der Termintypen.
	 *
	 * @return string
	 */
	public static function table_event_types() {
		global $wpdb;
		return $wpdb->prefix . 'ffbooking_event_types';
	}

	/**
	 * Tabellenname der Buchungen.
	 *
	 * @return string
	 */
	public static function table_bookings() {
		global $wpdb;
		return $wpdb->prefix . 'ffbooking_bookings';
	}

	/**
	 * Aktivierung.
	 */
	public static function activate() {
		// Eine Installation bis 1.2.6 erst umstellen. Sonst entstuenden leere neue Tabellen neben den alten.
		self::maybe_upgrade();
		if ( self::migration_error() ) {
			return; // Der Grund steht im Backend. Der naechste Aufruf versucht es erneut.
		}

		self::create_tables();
		self::seed_defaults();

		$settings = get_option( 'ffbooking_settings' );
		if ( ! is_array( $settings ) ) {
			add_option( 'ffbooking_settings', ffbooking_default_settings(), '', false );
		}

		set_transient( 'ffbooking_just_activated', 1, 60 );
		FFBooking_Cron::schedule();
		update_option( 'ffbooking_db_version', FFBOOKING_DB_VERSION, true );
		flush_rewrite_rules();
	}

	/**
	 * Deaktivierung.
	 */
	public static function deactivate() {
		FFBooking_Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Schema-Upgrade bei Versionssprung. Laeuft auf plugins_loaded, vor jeder Abfrage.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'ffbooking_db_version', 0 );

		if ( 0 === $installed && (int) get_option( self::LEGACY_DB_VERSION_OPTION, 0 ) > 0 ) {
			// Installation bis 1.2.6: Tabellen und Optionen tragen noch das Praefix ffb.
			if ( ! self::migrate_legacy_names() ) {
				return;
			}
			$installed = (int) get_option( 'ffbooking_db_version', 0 );
		}

		if ( $installed === FFBOOKING_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'ffbooking_db_version', FFBOOKING_DB_VERSION, true );
	}

	/**
	 * Stellt eine Installation bis Version 1.2.6 auf das Praefix ffbooking um.
	 *
	 * Reihenfolge: Optionen unter dem neuen Namen speichern und die alten loeschen,
	 * alte Transients und Cron-Eintraege entfernen, Tabellen umbenennen, Schemaversion
	 * uebernehmen. Laesst sich eine Tabelle nicht umbenennen, wird sie kopiert. Scheitert auch das, bleibt alles wie es ist: Der Grund landet
	 * in einer Option, erscheint im Backend, und der naechste Aufruf versucht es
	 * erneut. Geloescht wird dann nichts.
	 *
	 * Keine Uebersetzungen hier: Das laeuft auf plugins_loaded, vor init.
	 *
	 * @return bool Ob die Umstellung abgeschlossen ist.
	 */
	protected static function migrate_legacy_names() {
		global $wpdb;

		// Optionen zuerst. Scheitert danach das Umbenennen einer Tabelle, liest das Plugin seine
		// Einstellungen trotzdem schon unter dem neuen Namen, und beim naechsten Versuch fehlt nichts.
		// Alter Name => neuer Name und Autoload, so wie das Plugin die Option sonst anlegt.
		$options = array(
			'ffb_settings'               => array( 'ffbooking_settings', false ),
			'ffb_google_tokens'          => array( 'ffbooking_google_tokens', false ),
			'ffb_google_log'             => array( 'ffbooking_google_log', false ),
			'ffb_mail_tested'            => array( 'ffbooking_mail_tested', false ),
			'ffb_busy_cache_version'     => array( 'ffbooking_busy_cache_version', false ),
			'ffb_consent_text_refreshed' => array( 'ffbooking_consent_text_refreshed', true ),
			'ffb_reminders_last_run'     => array( 'ffbooking_reminders_last_run', false ),
			'ffb_auto_update_enabled'    => array( 'ffbooking_auto_update_enabled', true ),
		);
		foreach ( $options as $old => $target ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( null === get_option( $target[0], null ) ) {
				update_option( $target[0], $value, $target[1] );
			}
			delete_option( $old );
		}

		// Alte Transients loeschen. In einem externen Objekt-Cache laufen sie von selbst ab, spaetestens nach sechs Stunden.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Aufraeumen, einmalig beim Update.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ffb_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ffb_' ) . '%'
			)
		);

		// Cron-Eintraege mit den alten Hook-Namen entfernen, die neuen einplanen.
		wp_clear_scheduled_hook( 'ffb_send_reminders' );
		wp_clear_scheduled_hook( 'ffb_cleanup' );
		FFBooking_Cron::schedule();

		// Tabellen zuletzt. Hier kann es an fehlenden Rechten scheitern, darum steht alles andere schon.
		foreach ( array( 'event_types', 'bookings' ) as $name ) {
			$result = self::move_table( $wpdb->prefix . 'ffb_' . $name, $wpdb->prefix . 'ffbooking_' . $name );
			if ( is_wp_error( $result ) ) {
				self::remember_migration_error( $result );
				return false;
			}
		}

		update_option( 'ffbooking_db_version', (int) get_option( self::LEGACY_DB_VERSION_OPTION, 0 ), true );
		delete_option( self::LEGACY_DB_VERSION_OPTION );
		delete_option( self::MIGRATION_ERROR_OPTION );

		// Die Weiterleitungs-URI der Google-Verbindung endet jetzt auf page=ffbooking-settings. Die bestehende
		// Verbindung laeuft weiter, erst ein erneutes Verbinden braucht die neue Adresse in der Cloud Console.
		if ( '' !== (string) ffbooking_get_setting( 'google_client_id' ) ) {
			update_option( self::REDIRECT_NOTICE_OPTION, 1, true );
		}

		return true;
	}

	/**
	 * Benennt eine Tabelle um. Geht das nicht, legt sie eine Kopie an und laesst das Original stehen.
	 *
	 * @param string $old Bisheriger Name mit Praefix.
	 * @param string $new Neuer Name mit Praefix.
	 * @return true|WP_Error Fehler mit Code und Daten, ohne uebersetzten Text.
	 */
	protected static function move_table( $old, $new ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Eigene Tabellen, Namen aus $wpdb->prefix und festem Text.
		if ( ! self::table_exists( $old ) ) {
			return true; // Nichts zu uebernehmen, dbDelta legt die neue Tabelle an.
		}

		$suppress = $wpdb->suppress_errors();

		try {
			if ( self::table_exists( $new ) ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new}`" );
				if ( $rows > 0 ) {
					return new WP_Error( 'table_exists', '', compact( 'old', 'new', 'rows' ) );
				}
				// Leere neue Tabelle, etwa aus einem abgebrochenen Versuch: entfernen, dann umbenennen.
				if ( false === $wpdb->query( "DROP TABLE `{$new}`" ) ) {
					return new WP_Error( 'drop_failed', '', array( 'new' => $new, 'error' => $wpdb->last_error ) );
				}
			}

			// RENAME liefert bei Erfolg 0, bei einem Fehler false.
			if ( false !== $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ) ) {
				return true;
			}
			$rename_error = $wpdb->last_error;

			// Umbenennen nicht erlaubt: Kopie anlegen und fuellen.
			$created = $wpdb->query( "CREATE TABLE `{$new}` LIKE `{$old}`" );
			$copied  = false === $created ? false : $wpdb->query( "INSERT INTO `{$new}` SELECT * FROM `{$old}`" );
			if ( false === $created || false === $copied ) {
				$copy_error = $wpdb->last_error;
				if ( false !== $created ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$new}`" );
				}
				return new WP_Error( 'copy_failed', '', compact( 'old', 'new', 'rename_error', 'copy_error' ) );
			}

			$old_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$old}`" );
			$new_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new}`" );
			if ( $old_rows !== $new_rows ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$new}`" );
				return new WP_Error( 'copy_incomplete', '', compact( 'old', 'new', 'old_rows', 'new_rows' ) );
			}

			return true;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		// phpcs:enable
	}

	/**
	 * Gibt es die Tabelle?
	 *
	 * @param string $table Name mit Praefix.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merkt sich, woran die Umstellung gescheitert ist, fuer den Hinweis im Backend.
	 *
	 * @param WP_Error $error Fehler aus move_table().
	 */
	protected static function remember_migration_error( $error ) {
		$stored = array(
			'code' => $error->get_error_code(),
			'data' => (array) $error->get_error_data(),
		);
		if ( get_option( self::MIGRATION_ERROR_OPTION ) !== $stored ) {
			update_option( self::MIGRATION_ERROR_OPTION, $stored, true );
		}
	}

	/**
	 * Gescheiterte Umstellung, falls eine ansteht.
	 *
	 * @return array|null code und data, sonst null.
	 */
	public static function migration_error() {
		$error = get_option( self::MIGRATION_ERROR_OPTION );
		return is_array( $error ) && ! empty( $error['code'] ) ? $error : null;
	}

	/**
	 * Hinweise im Backend: gescheiterte Umstellung, geaenderte Weiterleitungs-URI.
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = self::migration_error();
		if ( $error ) {
			global $wpdb;
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Fines Finest Booking: the database tables could not be renamed for version 1.3.0.', 'fines-finest-booking' ) . '</strong><br>' . esc_html( self::describe_migration_error( $error ) ) . '</p><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: old table name, 2: new table name, 3: old table name, 4: new table name */
					__( 'Nothing was deleted. The plugin tries again on every page load. Until it succeeds, bookings are not available. If your database user may not rename tables, rename them yourself: %1$s to %2$s and %3$s to %4$s.', 'fines-finest-booking' ),
					$wpdb->prefix . 'ffb_event_types',
					self::table_event_types(),
					$wpdb->prefix . 'ffb_bookings',
					self::table_bookings()
				)
			);
			echo '</p></div>';
		}

		if ( get_option( self::REDIRECT_NOTICE_OPTION ) ) {
			$dismiss = wp_nonce_url( admin_url( 'admin.php?page=ffbooking-settings&ffbooking_do=dismiss_redirect_notice' ), 'ffbooking_dismiss_redirect_notice' );
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s: redirect URI in code formatting */
					__( 'Fines Finest Booking 1.3.0 changed the redirect URI of the Google connection to %s. The current connection keeps working. Before you connect Google again, add this address in the Google Cloud Console under the OAuth client.', 'fines-finest-booking' ),
					'<code>' . esc_html( FFBooking_Google::redirect_uri() ) . '</code>'
				),
				array( 'code' => array() )
			);
			echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Understood, hide this notice', 'fines-finest-booking' ) . '</a></p></div>';
		}
	}

	/**
	 * Uebersetzt den gespeicherten Fehler in einen Satz.
	 *
	 * @param array $error code und data.
	 * @return string
	 */
	protected static function describe_migration_error( $error ) {
		$d = array_merge(
			array(
				'old'          => '',
				'new'          => '',
				'rows'         => 0,
				'error'        => '',
				'rename_error' => '',
				'copy_error'   => '',
				'old_rows'     => 0,
				'new_rows'     => 0,
			),
			$error['data']
		);

		switch ( $error['code'] ) {
			case 'table_exists':
				/* translators: 1: new table name, 2: number of rows, 3: old table name */
				return sprintf( __( 'The table %1$s already exists and holds %2$d rows, so %3$s cannot be renamed. Remove or rename %1$s by hand.', 'fines-finest-booking' ), $d['new'], $d['rows'], $d['old'] );
			case 'drop_failed':
				/* translators: 1: table name, 2: database error */
				return sprintf( __( 'The empty table %1$s could not be removed: %2$s', 'fines-finest-booking' ), $d['new'], $d['error'] );
			case 'copy_failed':
				/* translators: 1: old table name, 2: new table name, 3: error when renaming, 4: error when copying */
				return sprintf( __( 'The table %1$s could not be renamed to %2$s (%3$s) and could not be copied either (%4$s).', 'fines-finest-booking' ), $d['old'], $d['new'], $d['rename_error'], $d['copy_error'] );
			case 'copy_incomplete':
				/* translators: 1: old table name, 2: new table name, 3: rows copied, 4: rows expected */
				return sprintf( __( 'Copying %1$s to %2$s was incomplete: %3$d rows instead of %4$d. The copy was removed, the original is untouched.', 'fines-finest-booking' ), $d['old'], $d['new'], $d['new_rows'], $d['old_rows'] );
		}

		return (string) $error['code'];
	}

	/**
	 * Legt die Tabellen an bzw. aktualisiert sie.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$types   = self::table_event_types();
		$books   = self::table_bookings();

		$sql_types = "CREATE TABLE {$types} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL DEFAULT '',
			title varchar(200) NOT NULL DEFAULT '',
			description text NULL,
			duration smallint(5) unsigned NOT NULL DEFAULT 30,
			slot_step smallint(5) unsigned NOT NULL DEFAULT 30,
			buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
			buffer_after smallint(5) unsigned NOT NULL DEFAULT 15,
			min_notice int(10) unsigned NOT NULL DEFAULT 720,
			max_days smallint(5) unsigned NOT NULL DEFAULT 45,
			daily_limit smallint(5) unsigned NOT NULL DEFAULT 0,
			location varchar(20) NOT NULL DEFAULT 'meet',
			location_note varchar(255) NOT NULL DEFAULT '',
			calendar_title varchar(255) NOT NULL DEFAULT '',
			calendar_buffers tinyint(1) NOT NULL DEFAULT 0,
			availability longtext NULL,
			blackouts text NULL,
			questions longtext NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort_order smallint(5) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY active (active)
		) {$charset};";

		$sql_books = "CREATE TABLE {$books} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_utc datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			end_utc datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			name varchar(200) NOT NULL DEFAULT '',
			email varchar(200) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			answers longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			google_event_id varchar(255) NOT NULL DEFAULT '',
			google_before_id varchar(255) NOT NULL DEFAULT '',
			google_after_id varchar(255) NOT NULL DEFAULT '',
			meet_url varchar(255) NOT NULL DEFAULT '',
			token varchar(64) NOT NULL DEFAULT '',
			source varchar(190) NOT NULL DEFAULT '',
			consent_at datetime NULL,
			reminder_sent tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY start_utc (start_utc),
			KEY status (status),
			KEY email (email),
			KEY token (token),
			KEY event_type_id (event_type_id)
		) {$charset};";

		dbDelta( $sql_types );
		dbDelta( $sql_books );
	}

	/**
	 * Legt beim ersten Aktivieren einen neutralen Beispiel-Termintyp an.
	 */
	public static function seed_defaults() {
		global $wpdb;

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ffbooking_event_types" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- eigene Tabelle, laeuft nur bei der Aktivierung.

		if ( $count > 0 ) {
			return;
		}

		$availability = array(
			'1' => array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) ),
			'2' => array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) ),
			'3' => array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) ),
			'4' => array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) ),
			'5' => array( array( '09:00', '13:00' ) ),
			'6' => array(),
			'7' => array(),
		);

		$questions = array(
			array(
				'key'      => 'anliegen',
				'label'    => __( 'What would you like to talk about?', 'fines-finest-booking' ),
				'type'     => 'textarea',
				'required' => 0,
			),
		);

		$title = __( 'Intro call', 'fines-finest-booking' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_event_types(),
			array(
				'slug'          => sanitize_title( $title ),
				'title'         => $title,
				'description'   => '',
				'duration'      => 30,
				'slot_step'     => 30,
				'buffer_before' => 0,
				'buffer_after'  => 15,
				'min_notice'    => 720,
				'max_days'      => 45,
				'daily_limit'   => 0,
				'location'      => 'meet',
				'location_note' => '',
				'availability'  => wp_json_encode( $availability ),
				'blackouts'     => wp_json_encode( array() ),
				'questions'     => wp_json_encode( $questions ),
				'active'        => 1,
				'sort_order'    => 0,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
}
