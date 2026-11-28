<?php
/**
 * Pruefungen fuer die Google-Einrichtung.
 *
 * Sie sagen in einfachen Worten, woran eine Verbindung scheitert: falsche Eintraege,
 * fehlende Weiterleitungs-URI, abgeschaltete Kalender-Schnittstelle, fehlende
 * Berechtigung oder eine App im Testmodus. Die Auswertung der Google-Antworten steht
 * in eigenen Funktionen ohne Netzzugriff, damit sie sich testen lassen.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eintraege pruefen, Google-Antworten und -Fehler verstaendlich machen.
 */
class FFBooking_Google_Check {

	const RESULT_OPTION  = 'ffbooking_google_check';
	const PROBLEM_OPTION = 'ffbooking_google_problem';
	// In Teilen geschrieben: Plugin Check haelt den Namen sonst fuer eine ausgelagerte Datei.
	const CLIENT_SUFFIX  = '.apps.' . 'googleusercontent' . '.com';

	const URL_PROJECT  = 'https://console.cloud.google.com/projectcreate';
	const URL_API      = 'https://console.cloud.google.com/apis/library/calendar-json.googleapis.com';
	const URL_PLATFORM = 'https://console.cloud.google.com/auth/overview';
	const URL_CLIENT   = 'https://console.cloud.google.com/auth/clients/create';
	const URL_AUDIENCE = 'https://console.cloud.google.com/auth/audience';

	/**
	 * Bereinigt einen eingefuegten Wert: Leerzeichen, Anfuehrungszeichen und unsichtbare Zeichen am Rand.
	 *
	 * Beim Kopieren aus der Cloud-Konsole kommen oft ein Leerzeichen oder ein Zeilenumbruch mit.
	 *
	 * @param mixed $value Eingabe.
	 * @return string
	 */
	public static function clean( $value ) {
		$value = (string) $value;
		$ohne  = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $value );
		if ( null !== $ohne ) {
			$value = $ohne;
		}

		return trim( $value, " \t\n\r\0\x0B\"'" );
	}

	/**
	 * Prueft Client-ID und Client-Secret auf offensichtliche Fehler.
	 *
	 * @param string $client_id Client-ID.
	 * @param string $secret    Client-Secret.
	 * @return array state: empty, ok oder problem; problems: Liste von Meldungen.
	 */
	public static function format_check( $client_id, $secret ) {
		$client_id = (string) $client_id;
		$secret    = (string) $secret;

		if ( '' === $client_id && '' === $secret ) {
			return array(
				'state'    => 'empty',
				'problems' => array(),
			);
		}

		$problems = array();
		$suffix   = self::CLIENT_SUFFIX;

		$id_ist_secret = 0 === strpos( $client_id, 'GOCSPX-' );
		$secret_ist_id = '' !== $secret && substr( $secret, -strlen( $suffix ) ) === $suffix;

		if ( '' === $client_id ) {
			$problems[] = __( 'The client ID is missing.', 'fines-finest-booking' );
		} elseif ( $id_ist_secret || $secret_ist_id ) {
			/* translators: %s: the ending of every Google client ID */
			$problems[] = sprintf( __( 'Client ID and client secret look swapped. The client ID ends with %s, the secret starts with GOCSPX-.', 'fines-finest-booking' ), $suffix );
		} elseif ( substr( $client_id, -strlen( $suffix ) ) !== $suffix ) {
			/* translators: %s: the ending of every Google client ID */
			$problems[] = sprintf( __( 'The client ID does not end with %s. Copy it again in full from the Google Cloud Console.', 'fines-finest-booking' ), $suffix );
		} elseif ( preg_match( '/\s/', $client_id ) ) {
			$problems[] = __( 'The client ID contains a space or line break. Copy it again.', 'fines-finest-booking' );
		}

		if ( '' === $secret ) {
			$problems[] = __( 'The client secret is missing.', 'fines-finest-booking' );
		} elseif ( preg_match( '/\s/', $secret ) ) {
			$problems[] = __( 'The client secret contains a space or line break. Copy it again.', 'fines-finest-booking' );
		}

		return array(
			'state'    => $problems ? 'problem' : 'ok',
			'problems' => $problems,
		);
	}

	/**
	 * Wertet die Antwort des Token-Endpunkts auf einen absichtlich ungueltigen Code aus.
	 *
	 * Bei gueltigen Zugangsdaten antwortet Google mit invalid_grant (der Code ist falsch),
	 * bei ungueltigen mit invalid_client.
	 *
	 * @param int   $status HTTP-Status.
	 * @param array $data   Dekodierte Antwort.
	 * @return string ok, wrong_id, wrong_secret oder unknown.
	 */
	public static function interpret_token_response( $status, $data ) {
		$error = ( is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ) ? $data['error'] : '';

		if ( 'invalid_grant' === $error ) {
			return 'ok';
		}

		if ( 'invalid_client' === $error ) {
			$text = ( is_array( $data ) && isset( $data['error_description'] ) ) ? strtolower( (string) $data['error_description'] ) : '';

			return false !== strpos( $text, 'not found' ) ? 'wrong_id' : 'wrong_secret';
		}

		return 'unknown';
	}

	/**
	 * Dekodiert base64url von Hand.
	 *
	 * Die Funktion aus PHP gilt bei WordPress als verdaechtig und wird hier fuer einen
	 * harmlosen Zweck gebraucht: einen Fehlercode aus Googles Adresse zu lesen.
	 *
	 * @param string $encoded Text im Alphabet A-Z, a-z, 0-9, Minus und Unterstrich, ohne Auffuellung.
	 * @return string Bytes, oder leer bei ungueltigen Zeichen.
	 */
	protected static function decode_base64url( $encoded ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
		$encoded  = rtrim( $encoded, '=' );
		$bits     = 0;
		$count    = 0;
		$out      = '';

		for ( $i = 0, $len = strlen( $encoded ); $i < $len; $i++ ) {
			$value = strpos( $alphabet, $encoded[ $i ] );
			if ( false === $value ) {
				return '';
			}
			$bits   = ( $bits << 6 ) | $value;
			$count += 6;
			if ( $count >= 8 ) {
				$count -= 8;
				$out   .= chr( ( $bits >> $count ) & 0xFF );
				$bits  &= ( 1 << $count ) - 1;
			}
		}

		return $out;
	}

	/**
	 * Liest den Fehlercode aus dem Parameter authError, den Googles Fehlerseite in der Adresse mitfuehrt.
	 *
	 * Der Wert ist base64url-kodiert und enthaelt zwei Textfelder: Code und Beschreibung.
	 *
	 * @param string $encoded Wert von authError.
	 * @return array|null code und description, oder null, wenn er sich nicht lesen laesst.
	 */
	public static function decode_auth_error( $encoded ) {
		$bytes = self::decode_base64url( (string) $encoded );
		if ( '' === $bytes ) {
			return null;
		}

		$fields = array();
		$pos    = 0;
		$len    = strlen( $bytes );

		while ( $pos < $len ) {
			$tag  = ord( $bytes[ $pos ] );
			$pos += 1;

			if ( 2 !== ( $tag & 7 ) ) {
				break; // Nur Textfelder sind von Interesse, danach hoert die Auswertung auf.
			}

			$size  = 0;
			$shift = 0;
			while ( $pos < $len ) {
				$byte  = ord( $bytes[ $pos ] );
				$pos  += 1;
				$size |= ( $byte & 0x7F ) << $shift;
				$shift += 7;
				if ( ! ( $byte & 0x80 ) ) {
					break;
				}
			}

			if ( $pos + $size > $len ) {
				break;
			}

			$fields[ $tag >> 3 ] = substr( $bytes, $pos, $size );
			$pos                += $size;
		}

		if ( empty( $fields[1] ) || ! preg_match( '/^[a-z_]{3,60}$/', $fields[1] ) ) {
			return null;
		}

		return array(
			'code'        => $fields[1],
			'description' => isset( $fields[2] ) ? $fields[2] : '',
		);
	}

	/**
	 * Wertet die Antwort der Anmeldeseite von Google aus.
	 *
	 * Bei einem Fehler leitet Google auf eine Fehlerseite um, deren Adresse den Grund enthaelt.
	 * Fuehrt die Umleitung zur normalen Anmeldung, sind Client-ID und Weiterleitungs-URI in Ordnung.
	 * Alles, was sich nicht sicher lesen laesst, ist "unknown" und wird nie als Fehler gemeldet.
	 *
	 * @param int    $status   HTTP-Status.
	 * @param string $location Wert des Location-Headers.
	 * @param string $body     Antworttext.
	 * @return string ok, redirect_mismatch, wrong_id, error:<code> oder unknown.
	 */
	public static function interpret_auth_response( $status, $location, $body = '' ) {
		$status   = (int) $status;
		$location = (string) $location;

		if ( $status >= 300 && $status < 400 && '' !== $location ) {
			if ( false === strpos( $location, '/signin/oauth/error' ) ) {
				return 'ok';
			}

			$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
			parse_str( $query, $params );
			$error = isset( $params['authError'] ) ? self::decode_auth_error( $params['authError'] ) : null;

			if ( ! $error ) {
				return 'unknown';
			}
			if ( 'redirect_uri_mismatch' === $error['code'] ) {
				return 'redirect_mismatch';
			}
			if ( 'invalid_client' === $error['code'] ) {
				return 'wrong_id';
			}

			return 'error:' . $error['code'];
		}

		if ( 400 === $status && false !== strpos( (string) $body, 'redirect_uri_mismatch' ) ) {
			return 'redirect_mismatch';
		}

		return 'unknown';
	}

	/**
	 * Fehlende Berechtigungen aus der Token-Antwort.
	 *
	 * Auf Googles Zustimmungsseite lassen sich einzelne Haken abwaehlen. Dann kommt zwar ein
	 * Token, aber ohne Zugriff auf den Kalender. Fehlt die Angabe ganz, gilt nichts als fehlend.
	 *
	 * @param string|null $granted Wert von scope aus der Antwort.
	 * @return array Fehlende Berechtigungen.
	 */
	public static function missing_scopes( $granted ) {
		if ( null === $granted || '' === trim( (string) $granted ) ) {
			return array();
		}

		$required = preg_split( '/\s+/', trim( FFBooking_Google::SCOPE ) );
		$have     = preg_split( '/\s+/', trim( (string) $granted ) );

		return array_values( array_diff( $required, $have ) );
	}

	/**
	 * Macht einen Fehler der Google-Schnittstelle verstaendlich.
	 *
	 * @param array $info status, oauth, reason, message wie aus FFBooking_Google::parse().
	 * @return array kind, text, link, link_text. kind ist ein stabiler Schluessel fuer Tests und Anzeige.
	 */
	public static function explain_error( $info ) {
		$info    = is_array( $info ) ? $info : array();
		$status  = isset( $info['status'] ) ? (int) $info['status'] : 0;
		$oauth   = isset( $info['oauth'] ) ? (string) $info['oauth'] : '';
		$reason  = isset( $info['reason'] ) ? (string) $info['reason'] : '';
		$message = isset( $info['message'] ) ? (string) $info['message'] : '';

		if ( 'invalid_grant' === $oauth ) {
			return array(
				'kind'      => 'expired',
				'text'      => __( 'Google ended the connection. The most common cause is an app that is still in testing mode, because Google then ends the connection after seven days. It also happens when you removed access in your Google account. Publish the app, then connect again.', 'fines-finest-booking' ),
				'link'      => self::URL_AUDIENCE,
				'link_text' => __( 'Open Audience in Google Cloud', 'fines-finest-booking' ),
			);
		}

		if ( 'invalid_client' === $oauth ) {
			return array(
				'kind'      => 'client',
				'text'      => __( 'Google no longer accepts the client ID and client secret. The client was deleted or the secret was replaced. Enter the current values from the Google Cloud Console and connect again.', 'fines-finest-booking' ),
				'link'      => '',
				'link_text' => '',
			);
		}

		if ( 'accessNotConfigured' === $reason || 'SERVICE_DISABLED' === $reason || false !== stripos( $message, 'has not been used in project' ) ) {
			$link = 'https://console.cloud.google.com/apis/library/calendar-json.googleapis.com';
			if ( preg_match( '/project[= ](\d{6,})/', $message, $m ) ) {
				$link .= '?project=' . rawurlencode( $m[1] );
			}

			return array(
				'kind'      => 'api_disabled',
				'text'      => __( 'The Google Calendar API is switched off in your Google project. Click Enable there, wait a minute, then check again.', 'fines-finest-booking' ),
				'link'      => $link,
				'link_text' => __( 'Open Calendar API in Google Cloud', 'fines-finest-booking' ),
			);
		}

		if ( 'insufficientPermissions' === $reason || 'ACCESS_TOKEN_SCOPE_INSUFFICIENT' === $reason || false !== stripos( $message, 'insufficient authentication scopes' ) ) {
			return array(
				'kind'      => 'scope',
				'text'      => __( 'Google did not give the plugin access to the calendar. On the Google consent screen, tick every checkbox for the calendar. Disconnect, then connect again.', 'fines-finest-booking' ),
				'link'      => '',
				'link_text' => '',
			);
		}

		if ( 401 === $status ) {
			return array(
				'kind'      => 'unauthorized',
				'text'      => __( 'Google rejected the access. Disconnect, then connect again.', 'fines-finest-booking' ),
				'link'      => '',
				'link_text' => '',
			);
		}

		return array(
			'kind'      => 'other',
			'text'      => $message,
			'link'      => '',
			'link_text' => '',
		);
	}

	/**
	 * Merkt sich ein Problem der Verbindung, das nur der Mensch beheben kann.
	 *
	 * Nur eine beendete Verbindung und ein ungueltiger Client zaehlen. Ein Netzfehler oder eine
	 * kurze Stoerung bei Google soll keinen Alarm ausloesen.
	 *
	 * @param array $info Fehlerangaben wie bei explain_error().
	 */
	public static function remember_problem( $info ) {
		$erklaerung = self::explain_error( $info );

		if ( in_array( $erklaerung['kind'], array( 'expired', 'client' ), true ) ) {
			update_option( self::PROBLEM_OPTION, $erklaerung['kind'], false );
		}
	}

	/**
	 * Vergisst ein gemerktes Problem und die letzten Pruefergebnisse.
	 */
	public static function forget() {
		delete_option( self::PROBLEM_OPTION );
		delete_option( self::RESULT_OPTION );
	}

	/**
	 * Das gemerkte Problem, aufbereitet fuer die Anzeige.
	 *
	 * @return array|null text, link, link_text oder null.
	 */
	public static function problem() {
		$kind = (string) get_option( self::PROBLEM_OPTION, '' );

		if ( 'expired' === $kind ) {
			return self::explain_error( array( 'oauth' => 'invalid_grant' ) );
		}
		if ( 'client' === $kind ) {
			return self::explain_error( array( 'oauth' => 'invalid_client' ) );
		}

		return null;
	}

	/**
	 * Fingerabdruck der Eintraege, damit ein altes Pruefergebnis nach einer Aenderung verfaellt.
	 *
	 * @return string
	 */
	protected static function fingerprint() {
		return hash( 'sha256', ffbooking_get_setting( 'google_client_id' ) . '|' . ffbooking_get_setting( 'google_client_secret' ) . '|' . FFBooking_Google::redirect_uri() );
	}

	/**
	 * Letztes Pruefergebnis, sofern es zu den heutigen Eintraegen passt.
	 *
	 * @return array|null time, mode, items oder null.
	 */
	public static function last_result() {
		$result = get_option( self::RESULT_OPTION );
		if ( ! is_array( $result ) || empty( $result['items'] ) ) {
			return null;
		}

		if ( 'credentials' === $result['mode'] && ( empty( $result['fingerprint'] ) || ! hash_equals( $result['fingerprint'], self::fingerprint() ) ) ) {
			return null;
		}

		return $result;
	}

	/**
	 * Gibt es ein gutes Pruefergebnis fuer die Zugangsdaten?
	 *
	 * @return bool
	 */
	public static function credentials_verified() {
		$result = self::last_result();
		if ( ! $result || 'credentials' !== $result['mode'] ) {
			return false;
		}

		foreach ( $result['items'] as $item ) {
			if ( 'error' === $item['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ein Pruefpunkt fuer die Anzeige.
	 *
	 * @param string $key       Kennung.
	 * @param string $status    ok, error oder unknown.
	 * @param string $text      Text.
	 * @param string $link      Optionaler Link.
	 * @param string $link_text Text des Links.
	 * @return array
	 */
	protected static function item( $key, $status, $text, $link = '', $link_text = '' ) {
		return array(
			'key'       => $key,
			'status'    => $status,
			'text'      => $text,
			'link'      => $link,
			'link_text' => $link_text,
		);
	}

	/**
	 * Speichert ein Ergebnis und gibt es zurueck.
	 *
	 * @param string $mode  credentials oder connection.
	 * @param array  $items Pruefpunkte.
	 * @return array
	 */
	protected static function store( $mode, $items ) {
		$result = array(
			'time'        => time(),
			'mode'        => $mode,
			'fingerprint' => 'credentials' === $mode ? self::fingerprint() : '',
			'items'       => $items,
		);
		update_option( self::RESULT_OPTION, $result, false );

		return $result;
	}

	/**
	 * Prueft Client-ID, Client-Secret und Weiterleitungs-URI gegen Google, ohne dass sich jemand anmeldet.
	 *
	 * @return array Gespeichertes Ergebnis.
	 */
	public static function run_credentials_check() {
		$client_id = (string) ffbooking_get_setting( 'google_client_id' );
		$secret    = (string) ffbooking_get_setting( 'google_client_secret' );
		$format    = self::format_check( $client_id, $secret );

		if ( 'ok' !== $format['state'] ) {
			$items = array();
			if ( 'empty' === $format['state'] ) {
				$items[] = self::item( 'entries', 'error', __( 'Enter the client ID and the client secret first and save.', 'fines-finest-booking' ) );
			}
			foreach ( $format['problems'] as $problem ) {
				$items[] = self::item( 'entries', 'error', $problem );
			}

			return self::store( 'credentials', $items );
		}

		$items = array();

		// 1. Zugangsdaten: Token-Endpunkt mit einem absichtlich ungueltigen Code.
		$response = wp_remote_post(
			FFBooking_Google::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => 'ffbooking-check',
					'client_id'     => $client_id,
					'client_secret' => $secret,
					'redirect_uri'  => FFBooking_Google::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$items[] = self::item(
				'credentials',
				'unknown',
				sprintf(
					/* translators: %s: error message */
					__( 'Google could not be reached: %s', 'fines-finest-booking' ),
					$response->get_error_message()
				)
			);

			return self::store( 'credentials', $items );
		}

		$token = self::interpret_token_response(
			(int) wp_remote_retrieve_response_code( $response ),
			json_decode( wp_remote_retrieve_body( $response ), true )
		);

		if ( 'wrong_id' === $token ) {
			$items[] = self::item( 'credentials', 'error', __( 'Google does not know this client ID. It is wrong or the client was deleted. Check it in the Google Cloud Console under Clients.', 'fines-finest-booking' ) );

			return self::store( 'credentials', $items );
		}

		if ( 'wrong_secret' === $token ) {
			$items[] = self::item( 'credentials', 'error', __( 'The client ID is known, but Google rejects the client secret. Copy the secret again from the Google Cloud Console.', 'fines-finest-booking' ) );

			return self::store( 'credentials', $items );
		}

		if ( 'ok' === $token ) {
			$items[] = self::item( 'credentials', 'ok', __( 'Google accepts the client ID and the client secret.', 'fines-finest-booking' ) );
		} else {
			$items[] = self::item( 'credentials', 'unknown', __( 'Google gave an answer the plugin cannot read. The check is inconclusive. You can still try to connect.', 'fines-finest-booking' ) );
		}

		// 2. Weiterleitungs-URI: Anmeldeseite anfragen und die Umleitung ansehen.
		$auth = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => rawurlencode( $client_id ),
					'redirect_uri'  => rawurlencode( FFBooking_Google::redirect_uri() ),
					'response_type' => 'code',
					'scope'         => rawurlencode( FFBooking_Google::SCOPE ),
				),
				FFBooking_Google::AUTH_URL
			),
			array(
				'timeout'     => 20,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $auth ) ) {
			$redirect = 'unknown';
		} else {
			$redirect = self::interpret_auth_response(
				(int) wp_remote_retrieve_response_code( $auth ),
				(string) wp_remote_retrieve_header( $auth, 'location' ),
				wp_remote_retrieve_body( $auth )
			);
		}

		if ( 'ok' === $redirect ) {
			$items[] = self::item( 'redirect', 'ok', __( 'Google accepts the redirect URI.', 'fines-finest-booking' ) );
		} elseif ( 'redirect_mismatch' === $redirect ) {
			$items[] = self::item(
				'redirect',
				'error',
				__( 'The redirect URI is missing in your Google client. Open the client, add the address from step 4 under authorised redirect URIs exactly as shown, save and check again. Google can take a few minutes to apply it.', 'fines-finest-booking' ),
				'https://console.cloud.google.com/auth/clients',
				__( 'Open Clients in Google Cloud', 'fines-finest-booking' )
			);
		} elseif ( 0 === strpos( $redirect, 'error:' ) ) {
			$items[] = self::item(
				'redirect',
				'error',
				sprintf(
					/* translators: %s: error code from Google */
					__( 'Google rejects the sign-in and reports: %s. Check the settings of the client in the Google Cloud Console.', 'fines-finest-booking' ),
					substr( $redirect, 6 )
				)
			);
		} else {
			$items[] = self::item( 'redirect', 'unknown', __( 'The redirect URI could not be checked from here. Connecting shows whether it fits.', 'fines-finest-booking' ) );
		}

		return self::store( 'credentials', $items );
	}

	/**
	 * Prueft die bestehende Verbindung: Token, Kalender-Schnittstelle, Zielkalender.
	 *
	 * @return array Gespeichertes Ergebnis.
	 */
	public static function run_connection_check() {
		$items = array();

		$calendars = FFBooking_Google::get_calendars( true );

		if ( is_wp_error( $calendars ) ) {
			$info    = $calendars->get_error_data();
			$erklaer = self::explain_error( is_array( $info ) ? $info : array( 'message' => $calendars->get_error_message() ) );
			$items[] = self::item( 'connection', 'error', '' !== $erklaer['text'] ? $erklaer['text'] : $calendars->get_error_message(), $erklaer['link'], $erklaer['link_text'] );

			return self::store( 'connection', $items );
		}

		$items[] = self::item(
			'connection',
			'ok',
			sprintf(
				/* translators: %d: number of calendars */
				_n( 'The connection works. Google lists %d calendar.', 'The connection works. Google lists %d calendars.', count( $calendars ), 'fines-finest-booking' ),
				count( $calendars )
			)
		);

		$ziel     = (string) ffbooking_get_setting( 'google_calendar_id', 'primary' );
		$schreibt = false;
		foreach ( $calendars as $id => $cal ) {
			if ( ( 'primary' === $ziel && $cal['primaer'] ) || (string) $id === $ziel ) {
				$schreibt = ! empty( $cal['schreiben'] );
				break;
			}
		}

		if ( $schreibt ) {
			$items[] = self::item( 'target', 'ok', __( 'The target calendar accepts new appointments.', 'fines-finest-booking' ) );
		} else {
			$items[] = self::item( 'target', 'error', __( 'The plugin cannot write to the target calendar. Choose a calendar of your own under Target calendar and save.', 'fines-finest-booking' ) );
		}

		return self::store( 'connection', $items );
	}
}
