<?php
/**
 * Google-Kalender-Anbindung ueber OAuth 2.0.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth, FreeBusy, Termine anlegen und loeschen.
 */
class FFB_Google {

	const OPTION      = 'ffb_google_tokens';
	const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
	const API_BASE    = 'https://www.googleapis.com/calendar/v3';
	const SCOPE       = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

	/**
	 * Redirect-URI fuer die Google-Cloud-Konsole.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=ffb-settings' );
	}

	/**
	 * Ist eine Verbindung eingerichtet?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$tokens = get_option( self::OPTION, array() );

		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * Mailadresse des verbundenen Kontos.
	 *
	 * @return string
	 */
	public static function connected_account() {
		$tokens = get_option( self::OPTION, array() );

		return isset( $tokens['account'] ) ? (string) $tokens['account'] : '';
	}

	/**
	 * Link zum Verbinden.
	 *
	 * @return string
	 */
	public static function auth_url() {
		$client_id = ffb_get_setting( 'google_client_id' );
		if ( ! $client_id ) {
			return '';
		}

		$state = wp_create_nonce( 'ffb_google_oauth' );
		set_transient( 'ffb_oauth_state', $state, 900 );

		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $client_id ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Tauscht den Code gegen Tokens.
	 *
	 * @param string $code Autorisierungscode.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => ffb_get_setting( 'google_client_id' ),
					'client_secret' => ffb_get_setting( 'google_client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$body = self::parse( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'ffb_no_refresh', 'Google hat kein Refresh-Token geschickt. Trenne die App unter myaccount.google.com/permissions und verbinde sie neu.' );
		}

		$tokens = array(
			'refresh_token' => $body['refresh_token'],
			'access_token'  => isset( $body['access_token'] ) ? $body['access_token'] : '',
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3500 ) - 60,
			'account'       => '',
		);

		update_option( self::OPTION, $tokens, false );

		$profile = self::request( 'GET', '/calendars/primary' );
		if ( ! is_wp_error( $profile ) && ! empty( $profile['id'] ) ) {
			$tokens['account'] = $profile['id'];
			update_option( self::OPTION, $tokens, false );
		}

		update_option( 'ffb_busy_cache_version', (int) get_option( 'ffb_busy_cache_version', 1 ) + 1, false );

		return true;
	}

	/**
	 * Trennt die Verbindung.
	 */
	public static function disconnect() {
		$tokens = get_option( self::OPTION, array() );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $tokens['refresh_token'] ),
				)
			);
		}

		delete_option( self::OPTION );
		update_option( 'ffb_busy_cache_version', (int) get_option( 'ffb_busy_cache_version', 1 ) + 1, false );
	}

	/**
	 * Gueltiges Access-Token, bei Bedarf erneuert.
	 *
	 * @return string|WP_Error
	 */
	protected static function access_token() {
		$tokens = get_option( self::OPTION, array() );

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'ffb_not_connected', 'Google-Kalender ist nicht verbunden.' );
		}

		if ( ! empty( $tokens['access_token'] ) && ! empty( $tokens['expires_at'] ) && $tokens['expires_at'] > time() ) {
			return $tokens['access_token'];
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'refresh_token' => $tokens['refresh_token'],
					'client_id'     => ffb_get_setting( 'google_client_id' ),
					'client_secret' => ffb_get_setting( 'google_client_secret' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$body = self::parse( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'ffb_refresh_failed', 'Das Google-Token konnte nicht erneuert werden.' );
		}

		$tokens['access_token'] = $body['access_token'];
		$tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3500 ) - 60;
		update_option( self::OPTION, $tokens, false );

		return $tokens['access_token'];
	}

	/**
	 * Fuehrt einen API-Aufruf aus.
	 *
	 * @param string $method HTTP-Methode.
	 * @param string $path   Pfad ab API_BASE.
	 * @param array  $body   Request-Body.
	 * @param array  $query  Query-Parameter.
	 * @return array|WP_Error
	 */
	protected static function request( $method, $path, $body = null, $query = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::API_BASE . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		return self::parse( wp_remote_request( $url, $args ) );
	}

	/**
	 * Wertet eine HTTP-Antwort aus.
	 *
	 * @param array|WP_Error $response Antwort.
	 * @return array|WP_Error
	 */
	protected static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$message = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['error_description'] ) ? $data['error_description'] : $raw );

		return new WP_Error( 'ffb_google_http_' . $code, 'Google-API (' . $code . '): ' . $message );
	}

	/**
	 * Kalender-IDs, die auf Belegung geprueft werden.
	 *
	 * @return array
	 */
	protected static function busy_calendar_ids() {
		$ids = array( ffb_get_setting( 'google_calendar_id', 'primary' ) );

		$extra = ffb_get_setting( 'google_busy_calendars' );
		if ( $extra ) {
			foreach ( preg_split( '/[\s,;]+/', $extra ) as $id ) {
				$id = trim( $id );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Listet die Kalender, die das verbundene Konto sehen darf.
	 *
	 * Genau diese koennen abgefragt werden. Ein Kalender aus einem anderen
	 * Google-Konto taucht hier nur auf, wenn er mit diesem Konto geteilt wurde.
	 *
	 * @param bool $force Cache uebergehen.
	 * @return array|WP_Error Liste aus id => array( name, schreiben ).
	 */
	public static function get_calendars( $force = false ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'ffb_not_connected', 'Google-Kalender ist nicht verbunden.' );
		}

		$cache_key = 'ffb_calendar_list_' . (int) get_option( 'ffb_busy_cache_version', 1 );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = self::request( 'GET', '/users/me/calendarList', null, array( 'maxResults' => 250, 'minAccessRole' => 'freeBusyReader' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$calendars = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$rolle = isset( $item['accessRole'] ) ? $item['accessRole'] : '';

			$calendars[ $item['id'] ] = array(
				'name'      => isset( $item['summary'] ) ? $item['summary'] : $item['id'],
				'schreiben' => in_array( $rolle, array( 'owner', 'writer' ), true ),
				'primaer'   => ! empty( $item['primary'] ),
			);
		}

		set_transient( $cache_key, $calendars, 10 * MINUTE_IN_SECONDS );

		return $calendars;
	}

	/**
	 * Kalender, die eingetragen sind, aber von Google nicht geliefert werden.
	 *
	 * @return array Liste der IDs.
	 */
	public static function unreachable_busy_calendars() {
		$kalender = self::get_calendars();
		if ( is_wp_error( $kalender ) ) {
			return array();
		}

		$fehlend = array();
		foreach ( self::busy_calendar_ids() as $id ) {
			if ( 'primary' === $id ) {
				continue;
			}
			if ( ! isset( $kalender[ $id ] ) ) {
				$fehlend[] = $id;
			}
		}

		return $fehlend;
	}

	/**
	 * Holt belegte Zeiten aus Google.
	 *
	 * @param DateTimeInterface $from Start (UTC).
	 * @param DateTimeInterface $to   Ende (UTC).
	 * @return array Liste aus [start_ts, end_ts].
	 */
	public static function get_busy( $from, $to ) {
		if ( ! self::is_connected() ) {
			return array();
		}

		$version   = (int) get_option( 'ffb_busy_cache_version', 1 );
		$cache_key = 'ffb_busy_' . md5( $version . $from->format( 'c' ) . $to->format( 'c' ) . implode( ',', self::busy_calendar_ids() ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$items = array();
		foreach ( self::busy_calendar_ids() as $id ) {
			$items[] = array( 'id' => $id );
		}

		$result = self::request(
			'POST',
			'/freeBusy',
			array(
				'timeMin' => gmdate( 'Y-m-d\TH:i:s\Z', $from->getTimestamp() ),
				'timeMax' => gmdate( 'Y-m-d\TH:i:s\Z', $to->getTimestamp() ),
				'items'   => $items,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::log( 'freeBusy: ' . $result->get_error_message() );
			return array();
		}

		$busy = array();
		if ( ! empty( $result['calendars'] ) && is_array( $result['calendars'] ) ) {
			foreach ( $result['calendars'] as $kalender_id => $calendar ) {
				// Google meldet Probleme pro Kalender, nicht als HTTP-Fehler.
				if ( ! empty( $calendar['errors'] ) ) {
					$gruende = array();
					foreach ( (array) $calendar['errors'] as $fehler ) {
						$gruende[] = isset( $fehler['reason'] ) ? $fehler['reason'] : 'unbekannt';
					}
					self::log( 'Kalender "' . $kalender_id . '" wurde nicht geprueft (' . implode( ', ', $gruende ) . '). Er blockt also keine Termine.' );
					continue;
				}

				if ( empty( $calendar['busy'] ) ) {
					continue;
				}
				foreach ( $calendar['busy'] as $block ) {
					$start = strtotime( $block['start'] );
					$end   = strtotime( $block['end'] );
					if ( $start && $end ) {
						$busy[] = array( $start, $end );
					}
				}
			}
		}

		set_transient( $cache_key, $busy, 120 );

		return $busy;
	}

	/**
	 * Legt einen Termin im Kalender an.
	 *
	 * @param array $booking Buchungsdaten.
	 * @param array $event   Termintyp.
	 * @return array|WP_Error Array mit id und meet_url.
	 */
	public static function create_event( $booking, $event ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'ffb_not_connected', 'Google-Kalender ist nicht verbunden.' );
		}

		$calendar_id = ffb_get_setting( 'google_calendar_id', 'primary' );
		$tz          = ffb_timezone()->getName();

		$lines = array();
		if ( ! empty( $booking['email'] ) ) {
			$lines[] = 'E-Mail: ' . $booking['email'];
		}
		if ( ! empty( $booking['phone'] ) ) {
			$lines[] = 'Telefon: ' . $booking['phone'];
		}
		foreach ( (array) $booking['answers'] as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		if ( ! empty( $booking['source'] ) ) {
			$lines[] = 'Gebucht auf: ' . $booking['source'];
		}
		$lines[] = '';
		$lines[] = 'Gebucht ueber ' . home_url();

		$payload = array(
			'summary'     => self::build_summary( $booking, $event ),
			'description' => implode( "\n", $lines ),
			'start'       => array(
				'dateTime' => $booking['start_local'],
				'timeZone' => $tz,
			),
			'end'         => array(
				'dateTime' => $booking['end_local'],
				'timeZone' => $tz,
			),
			'attendees'   => array(
				array(
					'email'       => $booking['email'],
					'displayName' => $booking['name'],
				),
			),
			'reminders'   => array(
				'useDefault' => false,
				'overrides'  => array(
					array(
						'method'  => 'popup',
						'minutes' => 30,
					),
				),
			),
		);

		$query = array( 'sendUpdates' => 'none' );

		if ( 'meet' === $event['location'] ) {
			$payload['conferenceData'] = array(
				'createRequest' => array(
					'requestId'             => 'ffb-' . wp_generate_uuid4(),
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			);
			$query['conferenceDataVersion'] = 1;
		} elseif ( 'phone' === $event['location'] ) {
			$payload['location'] = $booking['phone'] ? 'Telefon: ' . $booking['phone'] : 'Telefon';
		} elseif ( $event['location_note'] ) {
			$payload['location'] = $event['location_note'];
		}

		$result = self::request( 'POST', '/calendars/' . rawurlencode( $calendar_id ) . '/events', $payload, $query );

		if ( is_wp_error( $result ) ) {
			self::log( 'events.insert: ' . $result->get_error_message() );
			return $result;
		}

		$meet = '';
		if ( ! empty( $result['hangoutLink'] ) ) {
			$meet = $result['hangoutLink'];
		} elseif ( ! empty( $result['conferenceData']['entryPoints'] ) ) {
			foreach ( $result['conferenceData']['entryPoints'] as $entry ) {
				if ( isset( $entry['entryPointType'] ) && 'video' === $entry['entryPointType'] && ! empty( $entry['uri'] ) ) {
					$meet = $entry['uri'];
					break;
				}
			}
		}

		return array(
			'id'       => isset( $result['id'] ) ? $result['id'] : '',
			'meet_url' => $meet,
		);
	}

	/**
	 * Baut den Titel des Kalendereintrags aus der Vorlage des Termintyps.
	 *
	 * Platzhalter: {name}, {termintyp}, {quelle}, {datum}, {uhrzeit}, {email}
	 *
	 * @param array $booking Buchungsdaten.
	 * @param array $event   Termintyp.
	 * @return string
	 */
	public static function build_summary( $booking, $event ) {
		$vorlage = isset( $event['calendar_title'] ) ? trim( (string) $event['calendar_title'] ) : '';
		if ( '' === $vorlage ) {
			$vorlage = '{termintyp} mit {name}';
		}

		$start = new DateTimeImmutable( $booking['start_utc'], new DateTimeZone( 'UTC' ) );
		$start = $start->setTimezone( ffb_timezone() );

		$ersetzungen = array(
			'{name}'      => $booking['name'],
			'{termintyp}' => $event['title'],
			'{quelle}'    => isset( $booking['source'] ) ? $booking['source'] : '',
			'{datum}'     => $start->format( 'd.m.Y' ),
			'{uhrzeit}'   => $start->format( 'H:i' ),
			'{email}'     => $booking['email'],
		);

		$titel = strtr( $vorlage, $ersetzungen );

		// Leere Platzhalter hinterlassen gern doppelte Trenner.
		$titel = preg_replace( '/\s*[-–|]\s*([-–|]\s*)+/u', ' - ', $titel );
		$titel = trim( preg_replace( '/\s{2,}/u', ' ', $titel ), " \t-–|" );

		return '' === $titel ? $event['title'] : $titel;
	}

	/**
	 * Loescht einen Termin.
	 *
	 * @param string $event_id Google-Event-ID.
	 * @return bool
	 */
	public static function delete_event( $event_id ) {
		if ( ! self::is_connected() || ! $event_id ) {
			return false;
		}

		$calendar_id = ffb_get_setting( 'google_calendar_id', 'primary' );
		$result      = self::request( 'DELETE', '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ), null, array( 'sendUpdates' => 'none' ) );

		if ( is_wp_error( $result ) ) {
			self::log( 'events.delete: ' . $result->get_error_message() );
			return false;
		}

		return true;
	}

	/**
	 * Schreibt eine Fehlermeldung ins Log-Fenster des Plugins.
	 *
	 * @param string $message Meldung.
	 */
	public static function log( $message ) {
		$log = get_option( 'ffb_google_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time' => current_time( 'mysql' ),
				'text' => (string) $message,
			)
		);

		update_option( 'ffb_google_log', array_slice( $log, 0, 20 ), false );
	}
}
