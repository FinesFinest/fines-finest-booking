<?php
/**
 * Plugin Name:       Fines Finest Booking
 * Plugin URI:        https://fines-finest.com
 * Description:       Terminbuchung im eigenen Corporate Design, mit Google-Kalender-Sync, Google Meet, Bestaetigungsmails und Brevo-Anbindung. Alle Daten bleiben in der eigenen WordPress-Datenbank.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fines Finest
 * Author URI:        https://fines-finest.com
 * License:           GPL-2.0-or-later
 * Text Domain:       ff-booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FFB_VERSION', '1.1.2' );
define( 'FFB_FILE', __FILE__ );
define( 'FFB_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFB_URL', plugin_dir_url( __FILE__ ) );
define( 'FFB_DB_VERSION', 3 );

require_once FFB_DIR . 'includes/class-ffb-install.php';
require_once FFB_DIR . 'includes/class-ffb-event-types.php';
require_once FFB_DIR . 'includes/class-ffb-bookings.php';
require_once FFB_DIR . 'includes/class-ffb-availability.php';
require_once FFB_DIR . 'includes/class-ffb-google.php';
require_once FFB_DIR . 'includes/class-ffb-brevo.php';
require_once FFB_DIR . 'includes/class-ffb-mailer.php';
require_once FFB_DIR . 'includes/class-ffb-rest.php';
require_once FFB_DIR . 'includes/class-ffb-shortcode.php';
require_once FFB_DIR . 'includes/class-ffb-cron.php';
require_once FFB_DIR . 'includes/class-ffb-privacy.php';
require_once FFB_DIR . 'includes/class-ffb-admin.php';

register_activation_hook( __FILE__, array( 'FFB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FFB_Install', 'deactivate' ) );

/**
 * Startet das Plugin.
 */
function ffb_init() {
	FFB_Install::maybe_upgrade();
	FFB_Rest::init();
	FFB_Shortcode::init();
	FFB_Cron::init();
	FFB_Privacy::init();

	add_action( 'phpmailer_init', array( 'FFB_Mailer', 'add_plain_text' ) );

	if ( is_admin() ) {
		FFB_Admin::init();
	}
}
add_action( 'plugins_loaded', 'ffb_init' );

/**
 * Standardwerte der Plugin-Einstellungen.
 *
 * @return array
 */
function ffb_default_settings() {
	return array(
		'google_client_id'     => '',
		'google_client_secret' => '',
		'google_calendar_id'   => 'primary',
		'google_busy_calendars' => '',
		'brevo_api_key'        => '',
		'brevo_list_id'        => '',
		'hours_enforce'        => 0,
		'hours_1'              => '',
		'hours_2'              => '',
		'hours_3'              => '',
		'hours_4'              => '',
		'hours_5'              => '',
		'hours_6'              => '',
		'hours_7'              => '',
		'global_min_notice'    => 0,
		'global_blackouts'     => '',
		'notify_email'         => get_option( 'admin_email' ),
		'from_name'            => get_bloginfo( 'name' ),
		'from_email'           => '',
		'booking_page_url'     => '',
		'privacy_url'          => get_privacy_policy_url(),
		'consent_text'         => 'Ich habe die Datenschutzerklaerung gelesen und bin mit der Verarbeitung meiner Daten zur Terminabwicklung einverstanden.',
		'retention_days'       => 730,
		'rate_limit'           => 5,
		'color_accent'         => '#2563eb',
		'color_accent_soft'    => '#3b82f6',
		'color_secondary'      => '#0e7490',
		'color_success'        => '#15803d',
		'color_bg'             => '#ffffff',
		'color_bg_deep'        => '#f6f7f8',
		'color_surface'        => '#f1f2f4',
		'color_text'           => '#1a1d21',
		'color_muted'          => '#5a6068',
		'font_display'         => 'inherit',
		'font_body'            => 'inherit',
		'load_css'             => 1,
		'delete_on_uninstall'  => 0,
	);
}

/**
 * Liest eine einzelne Einstellung.
 *
 * @param string $key     Schluessel.
 * @param mixed  $default Rueckfallwert.
 * @return mixed
 */
function ffb_get_setting( $key, $default = null ) {
	$settings = get_option( 'ffb_settings', array() );
	$defaults = ffb_default_settings();

	if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
		return $settings[ $key ];
	}
	if ( null !== $default ) {
		return $default;
	}
	return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
}

/**
 * Absenderadresse fuer ausgehende Mails.
 *
 * @return string
 */
function ffb_from_email() {
	$configured = ffb_get_setting( 'from_email' );
	if ( $configured && is_email( $configured ) ) {
		return $configured;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = preg_replace( '/^www\./i', '', (string) $host );

	return 'no-reply@' . $host;
}

/**
 * Zeitzone der Website.
 *
 * @return DateTimeZone
 */
function ffb_timezone() {
	return wp_timezone();
}

/**
 * Aktueller Zeitpunkt in UTC.
 *
 * @return DateTimeImmutable
 */
function ffb_now_utc() {
	return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
}
