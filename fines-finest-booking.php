<?php
/**
 * Plugin Name:       Fines Finest Booking
 * Plugin URI:        https://github.com/FinesFinest/fines-finest-booking
 * Description:       Appointment booking in your own design, with Google Calendar sync, Google Meet, confirmation emails and Brevo. All data stays in your own WordPress database.
 * Version:           1.3.0
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

define( 'FFBOOKING_VERSION', '1.3.0' );
define( 'FFBOOKING_FILE', __FILE__ );
define( 'FFBOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFBOOKING_URL', plugin_dir_url( __FILE__ ) );
define( 'FFBOOKING_DB_VERSION', 5 );

// Kaffeekasse: Adresse fuer ein Dankeschoen an Fine (PayPal.Me). Leer = keine Zeile in den Einstellungen.
// Fuer Tests laesst sie sich in der wp-config.php vorbelegen.
if ( ! defined( 'FFBOOKING_DONATE_URL' ) ) {
	define( 'FFBOOKING_DONATE_URL', 'https://www.paypal.com/paypalme/finesfinest' );
}

require_once FFBOOKING_DIR . 'includes/class-ffb-install.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-event-types.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-bookings.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-availability.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-google.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-brevo.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-mailer.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-rest.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-shortcode.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-cron.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-privacy.php';
require_once FFBOOKING_DIR . 'includes/class-ffb-admin.php';

// Der Updater der selbst gehosteten Fassung fehlt in der Fassung fuer das WordPress-Verzeichnis,
// dort liefert wordpress.org die Updates. Seine Oberflaeche bringt er ueber die ffbooking_-Hooks selbst mit.
if ( file_exists( FFBOOKING_DIR . 'includes/class-ffb-updater.php' ) ) {
	require_once FFBOOKING_DIR . 'includes/class-ffb-updater.php';
}

register_activation_hook( __FILE__, array( 'FFBooking_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FFBooking_Install', 'deactivate' ) );

/**
 * Startet das Plugin.
 */
function ffbooking_init() {
	FFBooking_Install::maybe_upgrade();
	FFBooking_Rest::init();
	FFBooking_Shortcode::init();
	FFBooking_Cron::init();
	FFBooking_Privacy::init();

	if ( class_exists( 'FFBooking_Updater' ) ) {
		FFBooking_Updater::init();
	}

	add_action( 'phpmailer_init', array( 'FFBooking_Mailer', 'add_plain_text' ) );

	if ( is_admin() ) {
		FFBooking_Admin::init();
	}
}
add_action( 'plugins_loaded', 'ffbooking_init' );

/**
 * Laedt die Uebersetzungen aus dem Ordner languages.
 *
 * Nicht frueher als init: Seit WordPress 6.7 gilt frueheres Laden als Fehler.
 * Darum darf vor init auch kein Text uebersetzt werden.
 *
 * Die Fassung fuer das WordPress-Verzeichnis enthaelt keine Sprachdateien, dort
 * liefert translate.wordpress.org die Sprachpakete. Die selbst gehostete Fassung
 * von GitHub bringt die deutsche Uebersetzung im Ordner languages mit und laedt
 * sie nur ueber diesen Aufruf.
 */
function ffbooking_load_textdomain() {
	load_plugin_textdomain( 'fines-finest-booking', false, dirname( plugin_basename( FFBOOKING_FILE ) ) . '/languages' );
	ffbooking_refresh_default_consent_text();
}
add_action( 'init', 'ffbooking_load_textdomain' );

/**
 * Ersetzt einmalig den alten Standard-Einwilligungstext ohne Umlaute durch den uebersetzten.
 *
 * Bis Version 1.1.2 stand der Text fest im Code ("Datenschutzerklaerung") und wurde bei der
 * Aktivierung gespeichert. Nur genau dieser Text wird ersetzt, eigene Formulierungen bleiben.
 */
function ffbooking_refresh_default_consent_text() {
	if ( get_option( 'ffbooking_consent_text_refreshed' ) ) {
		return;
	}

	$settings = get_option( 'ffbooking_settings', array() );
	$old      = 'Ich habe die Datenschutzerklaerung gelesen und bin mit der Verarbeitung meiner Daten zur Terminabwicklung einverstanden.';

	if ( is_array( $settings ) && isset( $settings['consent_text'] ) && $old === $settings['consent_text'] ) {
		$settings['consent_text'] = __( 'I have read the privacy policy and agree to the processing of my data to arrange the appointment.', 'fines-finest-booking' );
		update_option( 'ffbooking_settings', $settings, false );
	}

	update_option( 'ffbooking_consent_text_refreshed', 1, true );
}

/**
 * Deutsche Varianten ohne eigene Sprachdatei (de_AT, de_CH, de_DE_formal) nutzen die deutsche Uebersetzung.
 *
 * @param string $locale Ermittelte Sprache.
 * @param string $domain Textdomain.
 * @return string
 */
function ffbooking_plugin_locale( $locale, $domain ) {
	if ( 'fines-finest-booking' !== $domain || 'de_DE' === $locale || 0 !== strpos( (string) $locale, 'de_' ) ) {
		return $locale;
	}
	if ( file_exists( FFBOOKING_DIR . 'languages/fines-finest-booking-' . $locale . '.mo' ) ) {
		return $locale;
	}

	return 'de_DE';
}
add_filter( 'plugin_locale', 'ffbooking_plugin_locale', 10, 2 );

/**
 * Standardwerte der Plugin-Einstellungen.
 *
 * @return array
 */
function ffbooking_default_settings() {
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
function ffbooking_get_setting( $key, $default = null ) {
	$settings = get_option( 'ffbooking_settings', array() );
	$defaults = ffbooking_default_settings();

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
function ffbooking_from_email() {
	$configured = ffbooking_get_setting( 'from_email' );
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
function ffbooking_timezone() {
	return wp_timezone();
}

/**
 * Aktueller Zeitpunkt in UTC.
 *
 * @return DateTimeImmutable
 */
function ffbooking_now_utc() {
	return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
}
