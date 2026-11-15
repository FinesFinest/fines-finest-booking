<?php
/**
 * Tests fuer das eigene Geheimnis des Plugins und das Formular-Token.
 *
 * Die Testumgebung kennt wp_salt() nicht. Ein Aufruf wuerde den Test mit einem Fehler beenden.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class RestSecretTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ffbooking_test_options'] = array();
		$GLOBALS['ffbooking_test_race']    = array();
	}

	/**
	 * Ruft eine geschuetzte Methode der REST-Klasse auf.
	 *
	 * @param string $name Methode.
	 * @param array  $args Argumente.
	 * @return mixed
	 */
	protected function call( $name, $args = array() ) {
		$method = new ReflectionMethod( 'FFBooking_Rest', $name );
		$method->setAccessible( true );

		return $method->invokeArgs( null, $args );
	}

	/**
	 * Das Geheimnis entsteht beim ersten Gebrauch und bleibt danach gleich.
	 */
	public function test_geheimnis_wird_einmal_erzeugt_und_wiederverwendet() {
		$this->assertArrayNotHasKey( 'ffbooking_secret', $GLOBALS['ffbooking_test_options'] );

		$erstes = $this->call( 'secret' );

		$this->assertGreaterThanOrEqual( 32, strlen( $erstes ) );
		$this->assertSame( $erstes, $GLOBALS['ffbooking_test_options']['ffbooking_secret'], 'Es liegt in der Option.' );
		$this->assertSame( $erstes, $this->call( 'secret' ), 'Der zweite Aufruf liest dasselbe.' );
	}

	/**
	 * Zwei Websites haben nie dasselbe Geheimnis.
	 */
	public function test_geheimnis_ist_zufaellig() {
		$a = $this->call( 'secret' );

		$GLOBALS['ffbooking_test_options'] = array();
		$b                                 = $this->call( 'secret' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * Eine zu kurze oder falsche Option gilt nicht, es wird ein neues Geheimnis angelegt.
	 */
	public function test_unbrauchbare_option_wird_ersetzt() {
		foreach ( array( 'kurz', '', 123, array( 'x' ) ) as $kaputt ) {
			$GLOBALS['ffbooking_test_options'] = array( 'ffbooking_secret' => $kaputt );

			// add_option() meldet "gibt es schon", also gilt der gespeicherte Wert nur, wenn er brauchbar ist.
			$secret = $this->call( 'secret' );

			$this->assertIsString( $secret );
			$this->assertGreaterThanOrEqual( 32, strlen( $secret ), 'Eingabe: ' . var_export( $kaputt, true ) );
		}
	}

	/**
	 * Waren zwei Aufrufe gleichzeitig dran, gilt der Wert, der zuerst gespeichert wurde.
	 */
	public function test_gleichzeitiger_aufruf_uebernimmt_den_gespeicherten_wert() {
		$vorher = str_repeat( 'v', 64 );

		$GLOBALS['ffbooking_test_race']['ffbooking_secret'] = $vorher;

		$this->assertSame( $vorher, $this->call( 'secret' ) );
	}

	/**
	 * Das Token haengt am Zeitstempel und am Geheimnis.
	 */
	public function test_token_haengt_an_zeitstempel_und_geheimnis() {
		$token = $this->call( 'make_token', array( 1800000000 ) );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertSame( $token, $this->call( 'make_token', array( 1800000000 ) ), 'Gleicher Zeitstempel, gleiches Token.' );
		$this->assertNotSame( $token, $this->call( 'make_token', array( 1800000001 ) ) );

		$GLOBALS['ffbooking_test_options'] = array();

		$this->assertNotSame( $token, $this->call( 'make_token', array( 1800000000 ) ), 'Mit anderem Geheimnis ein anderes Token.' );
	}

	/**
	 * Das Token ist ein HMAC ueber "ffb|" und den Zeitstempel mit dem Geheimnis des Plugins.
	 */
	public function test_token_ist_ein_hmac_mit_dem_eigenen_geheimnis() {
		$secret = $this->call( 'secret' );

		$this->assertSame( hash_hmac( 'sha256', 'ffb|1800000000', $secret ), $this->call( 'make_token', array( 1800000000 ) ) );
	}
}
