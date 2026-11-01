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
		$this->assertTrue( FFB_Shortcode::same_path( 'https://example.com/termin/', 'http://example.com/termin' ) );
		$this->assertTrue( FFB_Shortcode::same_path( 'https://example.com/termin/', '/termin/?ffb_cancel=abc123' ) );
		$this->assertTrue( FFB_Shortcode::same_path( 'https://example.com/buchen/termin/', 'https://www.example.com/buchen/termin' ) );
	}

	/**
	 * Andere Pfade sind andere Seiten.
	 */
	public function test_anderer_pfad() {
		$this->assertFalse( FFB_Shortcode::same_path( 'https://example.com/termin/', 'https://example.com/' ) );
		$this->assertFalse( FFB_Shortcode::same_path( 'https://example.com/termin/', '/termine/?ffb_cancel=abc123' ) );
		$this->assertFalse( FFB_Shortcode::same_path( 'https://example.com/termin/', '/alt/termin/' ) );
	}

	/**
	 * Adressen mit Parametern oder ohne Pfad lassen sich nicht sicher vergleichen.
	 */
	public function test_unsichere_adressen_ergeben_false() {
		$this->assertFalse( FFB_Shortcode::same_path( 'https://example.com/?page_id=4', '/?page_id=4&ffb_cancel=abc123' ), 'Parameter-Adressen prueft url_to_postid.' );
		$this->assertFalse( FFB_Shortcode::same_path( 'https://example.com/', '/' ), 'Die Startseite wird separat behandelt.' );
		$this->assertFalse( FFB_Shortcode::same_path( '', '/termin/' ) );
	}
}
