<?php
/**
 * Tests fuer die Normalisierung von Telefonnummern fuer Brevo.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ffbooking_test_locale'] = 'de_DE';
	}

	/**
	 * Deutsche Schreibweisen landen alle bei derselben internationalen Nummer.
	 */
	public function test_deutsche_schreibweisen() {
		foreach ( array( '0171 1234567', '+49 171 1234567', '0049 171 1234567', '+49 (0) 171 1234567', '+49-171/1234567', '491711234567' ) as $eingabe ) {
			$this->assertSame( '491711234567', FFBooking_Brevo::normalize_phone( $eingabe ), $eingabe );
		}
	}

	/**
	 * Auslaendische Nummern mit Vorwahl bleiben, wie sie sind, nur ohne Plus und Trennzeichen.
	 */
	public function test_auslaendische_nummern_mit_vorwahl() {
		$this->assertSame( '41791234567', FFBooking_Brevo::normalize_phone( '+41 79 123 45 67' ) );
		$this->assertSame( '15551234567', FFBooking_Brevo::normalize_phone( '+1 555 123 4567' ) );
	}

	/**
	 * Die Landesvorwahl kommt aus der Sprache der Website.
	 */
	public function test_vorwahl_aus_der_sprache_der_website() {
		$GLOBALS['ffbooking_test_locale'] = 'de_AT';
		$this->assertSame( '436641234567', FFBooking_Brevo::normalize_phone( '0664 1234567' ) );

		$GLOBALS['ffbooking_test_locale'] = 'en_US';
		$this->assertSame( '', FFBooking_Brevo::normalize_phone( '0171 1234567' ), 'Ohne bekannte Vorwahl wird die Nummer nicht geraten.' );
	}

	/**
	 * Was keine vollstaendige Nummer ist, ergibt einen leeren String.
	 */
	public function test_unbrauchbare_eingaben() {
		foreach ( array( '', 'keine', '12345', '+49 1234 5678 9012 34' ) as $eingabe ) {
			$this->assertSame( '', FFBooking_Brevo::normalize_phone( $eingabe ), var_export( $eingabe, true ) );
		}
	}
}
