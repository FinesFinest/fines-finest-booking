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
 * Legt Tabellen an und pflegt das Schema.
 */
class FFB_Install {

	/**
	 * Tabellenname der Termintypen.
	 *
	 * @return string
	 */
	public static function table_event_types() {
		global $wpdb;
		return $wpdb->prefix . 'ffb_event_types';
	}

	/**
	 * Tabellenname der Buchungen.
	 *
	 * @return string
	 */
	public static function table_bookings() {
		global $wpdb;
		return $wpdb->prefix . 'ffb_bookings';
	}

	/**
	 * Aktivierung.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_defaults();

		$settings = get_option( 'ffb_settings' );
		if ( ! is_array( $settings ) ) {
			add_option( 'ffb_settings', ffb_default_settings(), '', false );
		}

		set_transient( 'ffb_just_activated', 1, 60 );
		FFB_Cron::schedule();
		update_option( 'ffb_db_version', FFB_DB_VERSION, false );
		flush_rewrite_rules();
	}

	/**
	 * Deaktivierung.
	 */
	public static function deactivate() {
		FFB_Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Schema-Upgrade bei Versionssprung.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'ffb_db_version', 0 );
		if ( $installed === FFB_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'ffb_db_version', FFB_DB_VERSION, false );
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

		$table = self::table_event_types();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

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
				'label'    => 'Worum geht es?',
				'type'     => 'textarea',
				'required' => 0,
			),
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'slug'          => 'erstgespraech',
				'title'         => 'Erstgespraech',
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
