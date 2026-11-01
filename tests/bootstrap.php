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
$GLOBALS['ffb_test_locale'] = 'de_DE';

/**
 * Zeitzone der Website in den Tests.
 *
 * @return DateTimeZone
 */
function ffb_timezone() {
	return new DateTimeZone( 'Europe/Berlin' );
}

function get_locale() {
	return $GLOBALS['ffb_test_locale'];
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
	return trim( strip_tags( (string) $text ) );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}

function wp_kses_post( $text ) {
	return $text;
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

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once ABSPATH . 'includes/class-ffb-event-types.php';
require_once ABSPATH . 'includes/class-ffb-availability.php';
require_once ABSPATH . 'includes/class-ffb-cron.php';
require_once ABSPATH . 'includes/class-ffb-mailer.php';
require_once ABSPATH . 'includes/class-ffb-brevo.php';
