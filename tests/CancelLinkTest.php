<?php
/**
 * Tests fuer die Erkennung der Buchungsseite bei Absage-Links.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class CancelLinkTest extends TestCase {

	/**
	 * Schema, Host, Parameter und Schraegstrich am Ende spielen keine Rolle.
	 */
	public function test_gleicher_pfad_trotz_unterschieden_drumherum() {
		$this->assertTrue( FFBooking_Shortcode::same_path( 'https://example.com/termin/', 'http://example.com/termin' ) );
		$this->assertTrue( FFBooking_Shortcode::same_path( 'https://example.com/termin/', '/termin/?ffbooking_cancel=abc123' ) );
		$this->assertTrue( FFBooking_Shortcode::same_path( 'https://example.com/buchen/termin/', 'https://www.example.com/buchen/termin' ) );
	}

	/**
	 * Andere Pfade sind andere Seiten.
	 */
	public function test_anderer_pfad() {
		$this->assertFalse( FFBooking_Shortcode::same_path( 'https://example.com/termin/', 'https://example.com/' ) );
		$this->assertFalse( FFBooking_Shortcode::same_path( 'https://example.com/termin/', '/termine/?ffbooking_cancel=abc123' ) );
		$this->assertFalse( FFBooking_Shortcode::same_path( 'https://example.com/termin/', '/alt/termin/' ) );
	}

	/**
	 * Adressen mit Parametern oder ohne Pfad lassen sich nicht sicher vergleichen.
	 */
	public function test_unsichere_adressen_ergeben_false() {
		$this->assertFalse( FFBooking_Shortcode::same_path( 'https://example.com/?page_id=4', '/?page_id=4&ffbooking_cancel=abc123' ), 'Parameter-Adressen prueft url_to_postid.' );
		$this->assertFalse( FFBooking_Shortcode::same_path( 'https://example.com/', '/' ), 'Die Startseite wird separat behandelt.' );
		$this->assertFalse( FFBooking_Shortcode::same_path( '', '/termin/' ) );
	}

	/**
	 * Der Absage-Parameter heisst seit 1.3.0 ffbooking_cancel. Links aus aelteren Mails tragen ffb_cancel.
	 */
	public function test_absage_token_aus_neuem_und_altem_parameter() {
		$_GET = array( 'ffbooking_cancel' => 'neu123' );
		$this->assertSame( 'neu123', FFBooking_Shortcode::cancel_token() );

		$_GET = array( 'ffb_cancel' => 'alt456' );
		$this->assertSame( 'alt456', FFBooking_Shortcode::cancel_token(), 'Links aus Mails bis Version 1.2.6 gelten weiter.' );

		$_GET = array(
			'ffbooking_cancel' => 'neu',
			'ffb_cancel'       => 'alt',
		);
		$this->assertSame( 'neu', FFBooking_Shortcode::cancel_token(), 'Der aktuelle Parameter hat Vorrang.' );

		$_GET = array(
			'ffbooking_cancel' => '',
			'ffb_cancel'       => 'alt',
		);
		$this->assertSame( 'alt', FFBooking_Shortcode::cancel_token(), 'Ein leerer aktueller Parameter zaehlt nicht.' );

		$_GET = array( 'ffbooking_cancel' => ' <b>abc</b> ' );
		$this->assertSame( 'abc', FFBooking_Shortcode::cancel_token(), 'Tags und Leerzeichen fliegen raus.' );

		$_GET = array( 'ffbooking_cancel' => array( 'x' ) );
		$this->assertSame( '', FFBooking_Shortcode::cancel_token(), 'Ein Array ist kein Token.' );

		$_GET = array();
		$this->assertSame( '', FFBooking_Shortcode::cancel_token() );
	}
}
