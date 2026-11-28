<?php
/**
 * Testumgebung ohne WordPress.
 *
 * Die geprueften Klassen rechnen mit reinem PHP. Hier stehen nur die wenigen
 * WordPress-Funktionen und -Konstanten, die sie beim Laden oder in den
 * getesteten Pfaden brauchen, in bewusst schlichter Form.
 *
 * @package FinesFinestBooking
 */

date_default_timezone_set( 'UTC' ); // WordPress rechnet intern in UTC.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * Sprache der Website in den Tests, umschaltbar pro Fall.
 *
 * @var string
 */
$GLOBALS['ffbooking_test_locale'] = 'de_DE';

/**
 * Zeitzone der Website in den Tests.
 *
 * @return DateTimeZone
 */
function ffbooking_timezone() {
	return new DateTimeZone( 'Europe/Berlin' );
}

function get_locale() {
	return $GLOBALS['ffbooking_test_locale'];
}

function apply_filters( $hook, $value ) {
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	if ( is_array( $text ) || is_object( $text ) ) {
		return ''; // Wie WordPress: kein Text, kein Ergebnis.
	}
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}

function wp_kses_post( $text ) {
	return $text;
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\\r\\n\\t ]+/', ' ', $text );
	}

	return trim( $text );
}

/**
 * Optionen der Tests, als Feld statt Datenbank. Wer einen gleichzeitigen Aufruf nachstellen will,
 * legt unter $GLOBALS['ffbooking_test_race'][ Name ] den Wert ab, den der "andere" Aufruf schon gespeichert hat.
 */
$GLOBALS['ffbooking_test_options'] = array();
$GLOBALS['ffbooking_test_race']    = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ffbooking_test_options'] ) ? $GLOBALS['ffbooking_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ffbooking_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['ffbooking_test_options'][ $name ] );
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( isset( $GLOBALS['ffbooking_test_race'][ $name ] ) ) {
		$GLOBALS['ffbooking_test_options'][ $name ] = $GLOBALS['ffbooking_test_race'][ $name ];
		return false;
	}
	if ( array_key_exists( $name, $GLOBALS['ffbooking_test_options'] ) ) {
		return false;
	}
	$GLOBALS['ffbooking_test_options'][ $name ] = $value;
	return true;
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}
	if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ) {
		return $color;
	}
	return null;
}

/**
 * wp_mail() der Tests: nimmt die Mail an, laesst den Hook phpmailer_init laufen (hier nur die Textfassung)
 * und legt das Ergebnis in $GLOBALS['ffbooking_test_mail'] ab.
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$mailer              = new stdClass();
	$mailer->Body        = $message;
	$mailer->AltBody     = '';
	$mailer->ContentType = 'text/html';

	FFBooking_Mailer::add_plain_text( $mailer );

	$GLOBALS['ffbooking_test_mail'] = $mailer;

	return true;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}

/**
 * Minimaler Ersatz fuer WP_Error.
 */
class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

/**
 * Antworten von wp_remote_*() sind hier einfache Arrays: response.code, body und headers.
 */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once ABSPATH . 'includes/class-ffb-event-types.php';
require_once ABSPATH . 'includes/class-ffb-availability.php';
require_once ABSPATH . 'includes/class-ffb-google.php';
require_once ABSPATH . 'includes/class-ffb-google-check.php';
require_once ABSPATH . 'includes/class-ffb-cron.php';
require_once ABSPATH . 'includes/class-ffb-mailer.php';
require_once ABSPATH . 'includes/class-ffb-brevo.php';
require_once ABSPATH . 'includes/class-ffb-shortcode.php';
require_once ABSPATH . 'includes/class-ffb-css.php';
require_once ABSPATH . 'includes/class-ffb-rest.php';
