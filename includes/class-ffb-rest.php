<?php
/**
 * REST-Endpunkte fuer Slots, Buchung und Storno.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oeffentliche Schnittstelle des Buchungsformulars.
 */
class FFB_Rest {

	const NS = 'ffb/v1';

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routen registrieren.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/session',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event' => array(
						'required' => true,
						'type'     => 'string',
					),
					'from'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'days'  => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Liefert ein frisches Formular-Token. Wird nie gecacht.
	 *
	 * @return WP_REST_Response
	 */
	public static function session() {
		$issued = time();

		return new WP_REST_Response(
			array(
				'token'  => self::make_token( $issued ),
				'issued' => $issued,
			),
			200
		);
	}

	/**
	 * Erzeugt ein HMAC-Token.
	 *
	 * @param int $issued Zeitstempel.
	 * @return string
	 */
	protected static function make_token( $issued ) {
		return hash_hmac( 'sha256', 'ffb|' . $issued, wp_salt( 'nonce' ) );
	}

	/**
	 * Prueft Token, Alter und Honeypot.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param int             $min_age Mindestalter des Tokens in Sekunden.
	 * @return true|WP_Error
	 */
	protected static function check_guard( $request, $min_age = 3 ) {
		$issued = (int) $request->get_param( 'issued' );
		$token  = (string) $request->get_param( 'token' );
		$trap   = (string) $request->get_param( 'website_url' );

		if ( '' !== trim( $trap ) ) {
			return new WP_Error( 'ffb_bot', __( 'Invalid request.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}

		if ( ! $issued || ! $token || ! hash_equals( self::make_token( $issued ), $token ) ) {
			return new WP_Error( 'ffb_token', __( 'Your session has expired. Please reload the page.', 'fines-finest-booking' ), array( 'status' => 403 ) );
		}

		$age = time() - $issued;
		if ( $min_age > 0 && $age < $min_age ) {
			return new WP_Error( 'ffb_too_fast', __( 'That was too fast. Please try again.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}
		if ( $age > 7200 ) {
			return new WP_Error( 'ffb_expired', __( 'Your session has expired. Please reload the page.', 'fines-finest-booking' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Einfaches Rate-Limit pro IP.
	 *
	 * @return true|WP_Error
	 */
	protected static function check_rate_limit() {
		$limit = (int) ffb_get_setting( 'rate_limit', 5 );
		if ( $limit <= 0 ) {
			return true;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'ffb_rl_' . md5( $ip . wp_salt() );

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'ffb_rate', __( 'Too many booking attempts. Please try again in an hour or send me an email.', 'fines-finest-booking' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Antwort, wenn belegte Zeiten nicht ermittelt werden koennen. Der Grund steht im Fehlerprotokoll.
	 *
	 * @return WP_Error
	 */
	protected static function busy_unavailable_error() {
		return new WP_Error( 'ffb_busy_unavailable', __( 'Available times cannot be loaded right now. Please try again in a few minutes.', 'fines-finest-booking' ), array( 'status' => 503 ) );
	}

	/**
	 * Freie Slots.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function slots( $request ) {
		$event = FFB_Event_Types::get_by_slug( (string) $request->get_param( 'event' ) );

		if ( ! $event || ! $event['active'] ) {
			return new WP_Error( 'ffb_unknown_event', __( 'This appointment type does not exist.', 'fines-finest-booking' ), array( 'status' => 404 ) );
		}

		$from = (string) $request->get_param( 'from' );
		if ( $from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = '';
		}

		$days = (int) $request->get_param( 'days' );
		if ( $days < 1 ) {
			$days = 14;
		}

		$data = FFB_Availability::get_slots( $event, $from, $days );
		if ( is_wp_error( $data ) ) {
			return self::busy_unavailable_error();
		}

		$data['event'] = array(
			'slug'        => $event['slug'],
			'title'       => $event['title'],
			'description' => wpautop( $event['description'] ),
			'duration'    => $event['duration'],
			'location'    => $event['location'],
			'questions'   => $event['questions'],
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Nimmt eine Buchung entgegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function book( $request ) {
		$guard = self::check_guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$limit = self::check_rate_limit();
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$event = FFB_Event_Types::get_by_slug( (string) $request->get_param( 'event' ) );
		if ( ! $event || ! $event['active'] ) {
			return new WP_Error( 'ffb_unknown_event', __( 'This appointment type does not exist.', 'fines-finest-booking' ), array( 'status' => 404 ) );
		}

		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone  = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$source = mb_substr( sanitize_text_field( (string) $request->get_param( 'source' ) ), 0, 150 );

		if ( mb_strlen( $name ) < 2 ) {
			return new WP_Error( 'ffb_name', __( 'Please enter your name.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ffb_email', __( 'This email address does not look right.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}
		if ( ! $request->get_param( 'consent' ) ) {
			return new WP_Error( 'ffb_consent', __( 'Without your consent to data processing I cannot set up the appointment.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}

		$answers = array();
		$raw     = (array) $request->get_param( 'answers' );

		foreach ( $event['questions'] as $question ) {
			$value = isset( $raw[ $question['key'] ] ) ? (string) $raw[ $question['key'] ] : '';
			$value = 'textarea' === $question['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

			if ( $question['required'] && '' === trim( $value ) ) {
				return new WP_Error(
					'ffb_required',
					sprintf(
						/* translators: %s: label of the form field */
						__( 'Please fill in the field "%s".', 'fines-finest-booking' ),
						$question['label']
					),
					array( 'status' => 400 )
				);
			}
			if ( '' !== $value ) {
				$answers[ $question['label'] ] = mb_substr( $value, 0, 2000 );
			}
		}

		$start_raw = (string) $request->get_param( 'start' );
		try {
			$start = new DateTimeImmutable( $start_raw, new DateTimeZone( 'UTC' ) );
			$start = $start->setTimezone( new DateTimeZone( 'UTC' ) );
			$start = $start->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), 0 );
		} catch ( Exception $e ) {
			return new WP_Error( 'ffb_start', __( 'The selected time is invalid.', 'fines-finest-booking' ), array( 'status' => 400 ) );
		}

		$valid = FFB_Availability::validate_slot( $event, $start );
		if ( is_wp_error( $valid ) ) {
			if ( 'ffb_slot_gone' === $valid->get_error_code() ) {
				return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), array( 'status' => 409 ) );
			}

			// Google antwortet nicht: lieber keine Buchung als eine, die mit dem Kalender kollidiert.
			return self::busy_unavailable_error();
		}

		$booking_id = FFB_Bookings::create(
			$event,
			$start,
			array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'source'  => $source,
				'answers' => $answers,
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			return new WP_Error( $booking_id->get_error_code(), $booking_id->get_error_message(), array( 'status' => 409 ) );
		}

		$booking = FFB_Bookings::get( $booking_id );
		$tz      = ffb_timezone();
		$local_s = ( new DateTimeImmutable( $booking['start_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$local_e = ( new DateTimeImmutable( $booking['end_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

		$payload = array(
			'id'               => (int) $booking_id,
			'name'             => $name,
			'email'            => $email,
			'phone'            => $phone,
			'source'           => $source,
			'answers'          => $answers,
			'token'            => $booking['token'],
			'start_utc'        => $booking['start_utc'],
			'end_utc'          => $booking['end_utc'],
			'start_local'      => $local_s->format( 'Y-m-d\TH:i:s' ),
			'end_local'        => $local_e->format( 'Y-m-d\TH:i:s' ),
			'start_local_date' => $local_s->format( 'Y-m-d' ),
			'meet_url'         => '',
		);

		$google_error = '';
		if ( FFB_Google::is_connected() ) {
			$google = FFB_Google::create_event( $payload, $event );
			if ( is_wp_error( $google ) ) {
				$google_error = $google->get_error_message();
			} else {
				$payload['meet_url'] = $google['meet_url'];

				// Sperrzeiten nur, wenn der Termin selbst im Kalender steht. Scheitert eine, bleibt die Buchung gueltig.
				$buffers = ! empty( $event['calendar_buffers'] )
					? FFB_Google::create_buffer_events( $payload, $event )
					: array(
						'before_id' => '',
						'after_id'  => '',
					);

				FFB_Bookings::set_google( $booking_id, $google['id'], $google['meet_url'], $buffers['before_id'], $buffers['after_id'] );
			}
		}

		FFB_Mailer::send_confirmation( $payload, $event );
		FFB_Mailer::send_notification( $payload, $event );

		if ( FFB_Brevo::is_configured() ) {
			FFB_Brevo::upsert_contact( $payload, $event );
		}

		if ( $google_error ) {
			FFB_Google::log(
				sprintf(
					/* translators: 1: booking ID, 2: error message from Google */
					__( 'Booking #%1$d saved without a calendar entry: %2$s', 'fines-finest-booking' ),
					(int) $booking_id,
					$google_error
				)
			);
		}

		/**
		 * Nach erfolgreicher Buchung.
		 *
		 * @param array $payload Buchungsdaten.
		 * @param array $event   Termintyp.
		 */
		do_action( 'ffb_booking_created', $payload, $event );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'when'     => FFB_Mailer::format_datetime( $booking['start_utc'] ),
				'meet_url' => $payload['meet_url'],
				'email'    => $email,
				'location' => $event['location'],
			),
			201
		);
	}

	/**
	 * Storniert eine Buchung per Token.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel( $request ) {
		$guard = self::check_guard( $request, 0 );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$booking = FFB_Bookings::get_by_token( (string) $request->get_param( 'cancel_token' ) );

		if ( ! $booking ) {
			return new WP_Error( 'ffb_not_found', __( 'There is no appointment for this link.', 'fines-finest-booking' ), array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $booking['status'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'This appointment had already been cancelled.', 'fines-finest-booking' ),
				),
				200
			);
		}

		FFB_Bookings::cancel( (int) $booking['id'], true );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'The appointment is cancelled. You will get a confirmation by email shortly.', 'fines-finest-booking' ),
			),
			200
		);
	}
}
