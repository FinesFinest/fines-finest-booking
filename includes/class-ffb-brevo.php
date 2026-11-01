<?php
/**
 * Brevo-Anbindung: legt Kontakte an oder aktualisiert sie.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schlanker Client fuer die Brevo-Kontakt-API.
 */
class FFB_Brevo {

	const ENDPOINT = 'https://api.brevo.com/v3/contacts';

	/**
	 * Ist Brevo eingerichtet?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return (bool) ffb_get_setting( 'brevo_api_key' );
	}

	/**
	 * Legt einen Kontakt an bzw. aktualisiert ihn.
	 *
	 * @param array $booking Buchungsdaten.
	 * @param array $event   Termintyp.
	 * @return true|WP_Error
	 */
	public static function upsert_contact( $booking, $event ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'ffb_brevo_off', 'Brevo ist nicht eingerichtet.' );
		}

		$name  = trim( (string) $booking['name'] );
		$parts = preg_split( '/\s+/', $name );
		$first = array_shift( $parts );
		$last  = $parts ? implode( ' ', $parts ) : '';

		$attributes = array(
			'VORNAME'         => $first,
			'NACHNAME'        => $last,
			'FFB_TERMINTYP'   => $event['title'],
			'FFB_TERMIN_AM'   => $booking['start_local_date'],
			'FFB_QUELLE'      => ! empty( $booking['source'] ) ? $booking['source'] : 'Website-Buchung',
		);

		if ( ! empty( $booking['phone'] ) ) {
			$attributes['SMS'] = $booking['phone'];
		}

		$payload = array(
			'email'         => $booking['email'],
			'attributes'    => $attributes,
			'updateEnabled' => true,
		);

		$list_id = (int) ffb_get_setting( 'brevo_list_id' );
		if ( $list_id > 0 ) {
			$payload['listIds'] = array( $list_id );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array(
					'api-key'      => ffb_get_setting( 'brevo_api_key' ),
					'Content-Type' => 'application/json',
					'accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			FFB_Google::log( 'Brevo: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$message = isset( $decoded['message'] ) ? $decoded['message'] : $body;

		FFB_Google::log( 'Brevo (' . $code . '): ' . $message );

		return new WP_Error( 'ffb_brevo_http_' . $code, $message );
	}

	/**
	 * Holt die Kontaktlisten des Kontos, damit im Backend ausgewaehlt statt
	 * eine ID abgetippt werden kann. Ergebnis wird kurz zwischengespeichert.
	 *
	 * @param bool $force Cache uebergehen.
	 * @return array|WP_Error Liste aus id => name.
	 */
	public static function get_lists( $force = false ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'ffb_brevo_off', 'Kein API-Key hinterlegt.' );
		}

		$cache_key = 'ffb_brevo_lists_' . substr( md5( (string) ffb_get_setting( 'brevo_api_key' ) ), 0, 12 );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.brevo.com/v3/contacts/lists?limit=50&sort=desc',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key' => ffb_get_setting( 'brevo_api_key' ),
					'accept'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['message'] ) ? $body['message'] : 'Brevo antwortet mit Status ' . $code . '.';
			return new WP_Error( 'ffb_brevo_http_' . $code, $message );
		}

		$lists = array();
		foreach ( (array) ( $body['lists'] ?? array() ) as $list ) {
			if ( isset( $list['id'], $list['name'] ) ) {
				$lists[ (int) $list['id'] ] = (string) $list['name'];
			}
		}

		set_transient( $cache_key, $lists, 10 * MINUTE_IN_SECONDS );

		return $lists;
	}

	/**
	 * Testet den API-Key.
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'ffb_brevo_off', 'Kein API-Key hinterlegt.' );
		}

		$response = wp_remote_get(
			'https://api.brevo.com/v3/account',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key' => ffb_get_setting( 'brevo_api_key' ),
					'accept'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error( 'ffb_brevo_http_' . $code, 'Brevo antwortet mit Status ' . $code . '.' );
	}
}
