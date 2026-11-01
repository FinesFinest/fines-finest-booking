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
			'VORNAME'       => $first,
			'NACHNAME'      => $last,
			'FFB_TERMINTYP' => $event['title'],
			'FFB_TERMIN_AM' => $booking['start_local_date'],
			'FFB_QUELLE'    => ! empty( $booking['source'] ) ? $booking['source'] : 'Website-Buchung',
		);

		$nr = isset( $booking['id'] ) ? '#' . (int) $booking['id'] : '';

		if ( ! empty( $booking['phone'] ) ) {
			$sms = self::normalize_phone( $booking['phone'] );
			if ( '' === $sms ) {
				FFB_Google::log( 'Brevo, Buchung ' . $nr . ': Die Telefonnummer liess sich nicht ins internationale Format bringen und wurde nicht uebergeben.' );
			} else {
				$attributes['SMS'] = $sms;
			}
		}

		$result = self::send_contact( $booking['email'], $attributes );

		// Brevo lehnt die Nummer ab, etwa weil sie schon bei einem anderen Kontakt steht: noch einmal ohne Nummer.
		if ( is_wp_error( $result ) && isset( $attributes['SMS'] ) && 400 === (int) $result->get_error_data() ) {
			FFB_Google::log( 'Brevo, Buchung ' . $nr . ': Telefonnummer abgelehnt (' . $result->get_error_message() . '). Zweiter Versuch ohne Nummer.' );
			unset( $attributes['SMS'] );
			$result = self::send_contact( $booking['email'], $attributes );
		}

		return $result;
	}

	/**
	 * Schickt einen Kontakt an Brevo.
	 *
	 * @param string $email      Mailadresse.
	 * @param array  $attributes Attribute.
	 * @return true|WP_Error Bei einer Ablehnung steht der HTTP-Status in den Fehlerdaten.
	 */
	protected static function send_contact( $email, $attributes ) {
		$payload = array(
			'email'         => $email,
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

		return new WP_Error( 'ffb_brevo_http_' . $code, $message, $code );
	}

	/**
	 * Bringt eine Telefonnummer in das Format, das Brevo fuer das SMS-Attribut
	 * verlangt: Laendervorwahl und Nummer, nur Ziffern, ohne Plus oder 00.
	 *
	 * "0171 1234567" wird auf einer deutschen Website zu "491711234567",
	 * "+41 79 123 45 67" zu "41791234567". Was sich nicht eindeutig umwandeln
	 * laesst, ergibt einen leeren String.
	 *
	 * @param string $raw Eingabe aus dem Formular.
	 * @return string
	 */
	public static function normalize_phone( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		// "(0)" nach der Vorwahl ist eine Schreibkonvention, keine Ziffer.
		$raw    = preg_replace( '/\(\s*0\s*\)/', '', $raw );
		$digits = preg_replace( '/\D+/', '', $raw );

		if ( 0 === strpos( $raw, '+' ) || 0 === strpos( $digits, '00' ) ) {
			$digits = preg_replace( '/^00/', '', $digits );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$country = self::country_code();
			if ( '' === $country ) {
				return '';
			}
			$digits = $country . substr( $digits, 1 );
		}

		// E.164 erlaubt hoechstens 15 Ziffern, unter 8 ist es keine vollstaendige Nummer.
		$length = strlen( $digits );
		if ( $length < 8 || $length > 15 ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Laendervorwahl fuer Nummern ohne Vorwahl, abgeleitet aus der Sprache der Website.
	 *
	 * @return string Nur Ziffern, etwa "49". Leer, wenn die Sprache keine Zuordnung hat.
	 */
	protected static function country_code() {
		$map = array(
			'de_DE'          => '49',
			'de_DE_formal'   => '49',
			'de_AT'          => '43',
			'de_CH'          => '41',
			'de_CH_informal' => '41',
			'en_GB'          => '44',
			'fr_FR'          => '33',
			'it_IT'          => '39',
			'es_ES'          => '34',
			'nl_NL'          => '31',
		);

		$locale = get_locale();
		$code   = isset( $map[ $locale ] ) ? $map[ $locale ] : '';

		/**
		 * Laendervorwahl fuer Telefonnummern, die ohne Vorwahl eingegeben wurden.
		 *
		 * @param string $code   Vorwahl aus der Sprache der Website, nur Ziffern, etwa "49".
		 * @param string $locale Sprache der Website.
		 */
		$code = apply_filters( 'ffb_phone_country_code', $code, $locale );

		return preg_replace( '/\D+/', '', (string) $code );
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
