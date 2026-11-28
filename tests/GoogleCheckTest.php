<?php
/**
 * Tests fuer die Pruefungen der Google-Einrichtung.
 *
 * Alles hier arbeitet ohne Netzzugriff: Eintraege pruefen, Antworten von Google auswerten,
 * Fehler in einfache Worte uebersetzen.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class GoogleCheckTest extends TestCase {

	// Erfundene Werte, in Teilen geschrieben, damit kein Scanner sie fuer echte Zugangsdaten haelt.
	const ID     = '123456789012-' . 'erfundenertestclient' . '.apps.' . 'googleusercontent' . '.com';
	const SECRET = 'GOCSPX' . '-' . 'erfundenes-test-secret';

	protected function setUp(): void {
		$GLOBALS['ffbooking_test_options'] = array();
	}

	/**
	 * Baut einen authError-Wert wie Google: zwei Textfelder, base64url ohne Auffuellung.
	 *
	 * @param string $code        Fehlercode.
	 * @param string $description Beschreibung.
	 * @return string
	 */
	protected function auth_error( $code, $description ) {
		$bytes = "\x0a" . chr( strlen( $code ) ) . $code . "\x12" . chr( strlen( $description ) ) . $description . "\x20\x91\x03";

		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Zugangsdaten bereinigen: Rand, Anfuehrungszeichen, unsichtbare Zeichen.
	 */
	public function test_clean_entfernt_leerraum_anfuehrungszeichen_und_unsichtbare_zeichen() {
		$this->assertSame( self::ID, FFBooking_Google_Check::clean( "  \"" . self::ID . "\"\n" ) );
		$this->assertSame( self::SECRET, FFBooking_Google_Check::clean( "\xEF\xBB\xBF" . self::SECRET . "\xE2\x80\x8B" ) );
		$this->assertSame( 'a b', FFBooking_Google_Check::clean( ' a b ' ), 'Leerzeichen in der Mitte bleiben, sie werden gemeldet, nicht still entfernt.' );
		$this->assertSame( '', FFBooking_Google_Check::clean( null ) );
	}

	/**
	 * Eintraege pruefen.
	 */
	public function test_format_leer_gut_und_fehler() {
		$this->assertSame( 'empty', FFBooking_Google_Check::format_check( '', '' )['state'] );
		$this->assertSame( 'ok', FFBooking_Google_Check::format_check( self::ID, self::SECRET )['state'] );

		$ohne_secret = FFBooking_Google_Check::format_check( self::ID, '' );
		$this->assertSame( 'problem', $ohne_secret['state'] );
		$this->assertCount( 1, $ohne_secret['problems'] );

		$ohne_id = FFBooking_Google_Check::format_check( '', self::SECRET );
		$this->assertSame( 'problem', $ohne_id['state'] );

		$this->assertSame( 'problem', FFBooking_Google_Check::format_check( '123456789012-abc', self::SECRET )['state'], 'Eine ID ohne Endung ist unvollstaendig.' );
		$this->assertSame( 'problem', FFBooking_Google_Check::format_check( self::ID, 'GOCSPX-abc def' )['state'], 'Leerzeichen im Secret.' );
		$this->assertSame( 'problem', FFBooking_Google_Check::format_check( "123456789012-abc\n.apps.googleusercontent.com", self::SECRET )['state'], 'Zeilenumbruch in der ID.' );
	}

	/**
	 * Vertauschte Felder sind der haeufigste Fehler und bekommen genau eine Meldung.
	 */
	public function test_format_erkennt_vertauschte_felder() {
		$check = FFBooking_Google_Check::format_check( self::SECRET, self::ID );

		$this->assertSame( 'problem', $check['state'] );
		$this->assertCount( 1, $check['problems'] );
		$this->assertStringContainsString( 'swapped', $check['problems'][0] );
	}

	/**
	 * Token-Antwort: invalid_grant heisst, die Zugangsdaten stimmen.
	 */
	public function test_token_antwort_wird_richtig_gelesen() {
		$this->assertSame( 'ok', FFBooking_Google_Check::interpret_token_response( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Malformed auth code.' ) ) );
		$this->assertSame( 'wrong_id', FFBooking_Google_Check::interpret_token_response( 401, array( 'error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.' ) ) );
		$this->assertSame( 'wrong_secret', FFBooking_Google_Check::interpret_token_response( 401, array( 'error' => 'invalid_client', 'error_description' => 'Unauthorized' ) ) );
		$this->assertSame( 'unknown', FFBooking_Google_Check::interpret_token_response( 500, array( 'error' => 'backend_error' ) ) );
		$this->assertSame( 'unknown', FFBooking_Google_Check::interpret_token_response( 200, null ), 'Keine lesbare Antwort ist nie ein Fehler.' );
	}

	/**
	 * Der Fehlerparameter in Googles Fehlerseite laesst sich lesen, auch das echte Beispiel.
	 */
	public function test_auth_error_wird_dekodiert() {
		$echt = FFBooking_Google_Check::decode_auth_error( 'Cg5pbnZhbGlkX2NsaWVudBIfVGhlIE9BdXRoIGNsaWVudCB3YXMgbm90IGZvdW5kLiCRAw' );
		$this->assertSame( 'invalid_client', $echt['code'] );
		$this->assertSame( 'The OAuth client was not found.', $echt['description'] );

		$mismatch = FFBooking_Google_Check::decode_auth_error( $this->auth_error( 'redirect_uri_mismatch', 'Mismatch.' ) );
		$this->assertSame( 'redirect_uri_mismatch', $mismatch['code'] );

		$this->assertNull( FFBooking_Google_Check::decode_auth_error( '' ) );
		$this->assertNull( FFBooking_Google_Check::decode_auth_error( '!!!' ) );
		$this->assertNull( FFBooking_Google_Check::decode_auth_error( rtrim( strtr( base64_encode( 'kein protobuf, nur Text' ), '+/', '-_' ), '=' ) ), 'Unlesbares wird nie als Fehlercode gemeldet.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Anmeldeseite: Fehlerseite lesen, normale Anmeldung als gut werten, alles Unklare als unklar.
	 */
	public function test_anmeldeantwort_wird_richtig_gelesen() {
		$fehler = 'https://accounts.google.com/signin/oauth/error?authError=%s&flowName=GeneralOAuthFlow';

		$this->assertSame(
			'redirect_mismatch',
			FFBooking_Google_Check::interpret_auth_response( 302, sprintf( $fehler, $this->auth_error( 'redirect_uri_mismatch', 'x' ) ) )
		);
		$this->assertSame( 'wrong_id', FFBooking_Google_Check::interpret_auth_response( 302, sprintf( $fehler, 'Cg5pbnZhbGlkX2NsaWVudBIfVGhlIE9BdXRoIGNsaWVudCB3YXMgbm90IGZvdW5kLiCRAw' ) ) );
		$this->assertSame( 'error:access_denied', FFBooking_Google_Check::interpret_auth_response( 302, sprintf( $fehler, $this->auth_error( 'access_denied', 'x' ) ) ) );
		$this->assertSame( 'ok', FFBooking_Google_Check::interpret_auth_response( 302, 'https://accounts.google.com/v3/signin/identifier?opparams=%253F&dsh=S1' ) );
		$this->assertSame( 'unknown', FFBooking_Google_Check::interpret_auth_response( 302, sprintf( $fehler, 'kaputt' ) ), 'Ein nicht lesbarer Fehler wird nicht geraten.' );
		$this->assertSame( 'unknown', FFBooking_Google_Check::interpret_auth_response( 200, '' ) );
		$this->assertSame( 'unknown', FFBooking_Google_Check::interpret_auth_response( 503, '' ) );
		$this->assertSame( 'redirect_mismatch', FFBooking_Google_Check::interpret_auth_response( 400, '', '<html>Error 400: redirect_uri_mismatch</html>' ) );
	}

	/**
	 * Berechtigungen: Fehlt ein Haken, fehlt die Berechtigung. Fehlt die Angabe, gilt nichts als fehlend.
	 */
	public function test_fehlende_berechtigungen() {
		$beide = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

		$this->assertSame( array(), FFBooking_Google_Check::missing_scopes( $beide ) );
		$this->assertSame( array(), FFBooking_Google_Check::missing_scopes( 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events openid' ), 'Reihenfolge und Zusatzrechte sind egal.' );
		$this->assertSame( array( 'https://www.googleapis.com/auth/calendar.events' ), FFBooking_Google_Check::missing_scopes( 'https://www.googleapis.com/auth/calendar.readonly' ) );
		$this->assertCount( 2, FFBooking_Google_Check::missing_scopes( 'openid email' ) );
		$this->assertSame( array(), FFBooking_Google_Check::missing_scopes( null ) );
		$this->assertSame( array(), FFBooking_Google_Check::missing_scopes( '' ) );
	}

	/**
	 * Fehler der Schnittstelle werden einer Ursache zugeordnet.
	 */
	public function test_fehler_werden_erklaert() {
		$this->assertSame( 'expired', FFBooking_Google_Check::explain_error( array( 'status' => 400, 'oauth' => 'invalid_grant', 'message' => 'Token has been expired or revoked.' ) )['kind'] );
		$this->assertSame( 'client', FFBooking_Google_Check::explain_error( array( 'status' => 401, 'oauth' => 'invalid_client' ) )['kind'] );
		$this->assertSame( 'scope', FFBooking_Google_Check::explain_error( array( 'status' => 403, 'reason' => 'insufficientPermissions' ) )['kind'] );
		$this->assertSame( 'scope', FFBooking_Google_Check::explain_error( array( 'status' => 403, 'message' => 'Request had insufficient authentication scopes.' ) )['kind'] );
		$this->assertSame( 'unauthorized', FFBooking_Google_Check::explain_error( array( 'status' => 401, 'message' => 'Invalid Credentials' ) )['kind'] );

		$api = FFBooking_Google_Check::explain_error(
			array(
				'status'  => 403,
				'reason'  => 'accessNotConfigured',
				'message' => 'Google Calendar API has not been used in project 123456789012 before or it is disabled.',
			)
		);
		$this->assertSame( 'api_disabled', $api['kind'] );
		$this->assertStringContainsString( 'project=123456789012', $api['link'], 'Der Link fuehrt direkt zum richtigen Projekt.' );

		$sonst = FFBooking_Google_Check::explain_error( array( 'status' => 500, 'message' => 'Backend Error' ) );
		$this->assertSame( 'other', $sonst['kind'] );
		$this->assertSame( 'Backend Error', $sonst['text'], 'Unbekannte Fehler bleiben im Wortlaut von Google.' );
		$this->assertSame( 'other', FFBooking_Google_Check::explain_error( null )['kind'] );
	}

	/**
	 * Nur eine beendete Verbindung und ein ungueltiger Client loesen den dauerhaften Hinweis aus.
	 */
	public function test_nur_echte_probleme_werden_gemerkt() {
		FFBooking_Google_Check::remember_problem( array( 'status' => 500, 'message' => 'Backend Error' ) );
		$this->assertNull( FFBooking_Google_Check::problem(), 'Eine kurze Stoerung bei Google ist kein Alarm.' );

		FFBooking_Google_Check::remember_problem( array( 'status' => 403, 'reason' => 'accessNotConfigured' ) );
		$this->assertNull( FFBooking_Google_Check::problem() );

		FFBooking_Google_Check::remember_problem( array( 'status' => 400, 'oauth' => 'invalid_grant' ) );
		$this->assertNotNull( FFBooking_Google_Check::problem() );
		$this->assertStringContainsString( 'seven days', FFBooking_Google_Check::problem()['text'] );

		FFBooking_Google_Check::forget();
		$this->assertNull( FFBooking_Google_Check::problem(), 'Nach dem Neuverbinden ist der Hinweis weg.' );
	}

	/**
	 * Der Fehler der Schnittstelle bleibt mit allen Angaben am WP_Error.
	 */
	public function test_google_parse_haengt_angaben_an_den_fehler() {
		$method = new ReflectionMethod( 'FFBooking_Google', 'parse' );
		$method->setAccessible( true );

		$antwort = static function ( $code, $body ) {
			return array( 'response' => array( 'code' => $code ), 'body' => $body );
		};

		$fehler = $method->invoke( null, $antwort( 403, '{"error":{"code":403,"message":"Not enabled","errors":[{"reason":"accessNotConfigured"}]}}' ) );
		$this->assertTrue( is_wp_error( $fehler ) );
		$this->assertSame( 'accessNotConfigured', $fehler->get_error_data()['reason'] );
		$this->assertSame( 403, $fehler->get_error_data()['status'] );

		$oauth = $method->invoke( null, $antwort( 400, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}' ) );
		$this->assertSame( 'invalid_grant', $oauth->get_error_data()['oauth'] );

		$text = $method->invoke( null, $antwort( 502, '<html>Bad Gateway</html>' ) );
		$this->assertSame( 502, $text->get_error_data()['status'] );
		$this->assertSame( '', $text->get_error_data()['oauth'] );
	}
}
