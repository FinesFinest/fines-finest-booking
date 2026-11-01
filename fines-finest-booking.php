<?php
/**
 * Plugin Name:       Fines Finest Booking
 * Plugin URI:        https://fines-finest.com
 * Description:       Appointment booking in your own design, with Google Calendar sync, Google Meet, confirmation emails and Brevo. All data stays in your own WordPress database.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fines Finest
 * Author URI:        https://fines-finest.com
 * License:           GPL-2.0-or-later
 * Text Domain:       fines-finest-booking
 * Domain Path:       /languages
 * Update URI:        https://github.com/FinesFinest/fines-finest-booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FFB_VERSION', '1.2.0' );
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

// Der GitHub-Updater fehlt in der Fassung fuer das WordPress-Verzeichnis, dort liefert wordpress.org die Updates.
if ( file_exists( FFB_DIR . 'includes/class-ffb-updater.php' ) ) {
	require_once FFB_DIR . 'includes/class-ffb-updater.php';
}

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

	if ( class_exists( 'FFB_Updater' ) ) {
		FFB_Updater::init();
	}

	add_action( 'phpmailer_init', array( 'FFB_Mailer', 'add_plain_text' ) );

	if ( is_admin() ) {
		FFB_Admin::init();
	}
}
add_action( 'plugins_loaded', 'ffb_init' );

/**
 * Laedt die Uebersetzungen aus dem Ordner languages.
 *
 * Nicht frueher als init: Seit WordPress 6.7 gilt frueheres Laden als Fehler.
 * Darum darf vor init auch kein Text uebersetzt werden.
 *
 * Plugin Check raet von load_plugin_textdomain() ab, weil wordpress.org eigene
 * Sprachpakete ausliefert. Die gibt es fuer dieses Plugin nicht: Die deutsche
 * Uebersetzung liegt im Ordner languages bei und wird nur so geladen.
 */
function ffb_load_textdomain() {
	load_plugin_textdomain( 'fines-finest-booking', false, dirname( plugin_basename( FFB_FILE ) ) . '/languages' );
	ffb_refresh_default_consent_text();
}
add_action( 'init', 'ffb_load_textdomain' );

/**
 * Ersetzt einmalig den alten Standard-Einwilligungstext ohne Umlaute durch den uebersetzten.
 *
 * Bis Version 1.1.2 stand der Text fest im Code ("Datenschutzerklaerung") und wurde bei der
 * Aktivierung gespeichert. Nur genau dieser Text wird ersetzt, eigene Formulierungen bleiben.
 */
function ffb_refresh_default_consent_text() {
	if ( get_option( 'ffb_consent_text_refreshed' ) ) {
		return;
	}

	$settings = get_option( 'ffb_settings', array() );
	$old      = 'Ich habe die Datenschutzerklaerung gelesen und bin mit der Verarbeitung meiner Daten zur Terminabwicklung einverstanden.';

	if ( is_array( $settings ) && isset( $settings['consent_text'] ) && $old === $settings['consent_text'] ) {
		$settings['consent_text'] = __( 'I have read the privacy policy and agree to the processing of my data to arrange the appointment.', 'fines-finest-booking' );
		update_option( 'ffb_settings', $settings, false );
	}

	update_option( 'ffb_consent_text_refreshed', 1, true );
}

/**
 * Deutsche Varianten ohne eigene Sprachdatei (de_AT, de_CH, de_DE_formal) nutzen die deutsche Uebersetzung.
 *
 * @param string $locale Ermittelte Sprache.
 * @param string $domain Textdomain.
 * @return string
 */
function ffb_plugin_locale( $locale, $domain ) {
	if ( 'fines-finest-booking' !== $domain || 'de_DE' === $locale || 0 !== strpos( (string) $locale, 'de_' ) ) {
		return $locale;
	}
	if ( file_exists( FFB_DIR . 'languages/fines-finest-booking-' . $locale . '.mo' ) ) {
		return $locale;
	}

	return 'de_DE';
}
add_filter( 'plugin_locale', 'ffb_plugin_locale', 10, 2 );

/**
 * Standardwerte der Plugin-Einstellungen.
 *
 * @return array
 */
function ffb_default_settings() {
	return array(
		'google_client_id'      => '',
		'google_client_secret'  => '',
		'google_calendar_id'    => 'primary',
		'google_busy_calendars' => '',
		'brevo_api_key'         => '',
		'brevo_list_id'         => '',
		'hours_enforce'         => 0,
		'hours_1'               => '',
		'hours_2'               => '',
		'hours_3'               => '',
		'hours_4'               => '',
		'hours_5'               => '',
		'hours_6'               => '',
		'hours_7'               => '',
		'global_min_notice'     => 0,
		'global_blackouts'      => '',
		'notify_email'          => get_option( 'admin_email' ),
		'from_name'             => get_bloginfo( 'name' ),
		'from_email'            => '',
		'booking_page_url'      => '',
		'privacy_url'           => get_privacy_policy_url(),
		'consent_text'          => __( 'I have read the privacy policy and agree to the processing of my data to arrange the appointment.', 'fines-finest-booking' ),
		'retention_days'        => 730,
		'rate_limit'            => 5,
		'color_accent'          => '#2563eb',
		'color_accent_soft'     => '#3b82f6',
		'color_secondary'       => '#0e7490',
		'color_success'         => '#15803d',
		'color_bg'              => '#ffffff',
		'color_bg_deep'         => '#f6f7f8',
		'color_surface'         => '#f1f2f4',
		'color_text'            => '#1a1d21',
		'color_muted'           => '#5a6068',
		'font_display'          => 'inherit',
		'font_body'             => 'inherit',
		'load_css'              => 1,
		'delete_on_uninstall'   => 0,
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
