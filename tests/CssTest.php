<?php
/**
 * Tests fuer die CSS-sicheren Werte aus den Einstellungen.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class CssTest extends TestCase {

	/**
	 * Gewohnte Eingaben kommen als gueltige Schriftliste heraus.
	 */
	public function test_schriftlisten_werden_zu_gueltigem_css() {
		$this->assertSame( 'inherit', FFBooking_Css::font( '' ) );
		$this->assertSame( 'inherit', FFBooking_Css::font( 'inherit' ) );
		$this->assertSame( 'inherit', FFBooking_Css::font( '  INHERIT ' ) );
		$this->assertSame( 'Arial, sans-serif', FFBooking_Css::font( 'Arial, sans-serif' ) );
		$this->assertSame( '"Space Grotesk", sans-serif', FFBooking_Css::font( "'Space Grotesk', sans-serif" ), 'Einfache Anfuehrungszeichen werden zu doppelten.' );
		$this->assertSame( '"Helvetica Neue", Arial', FFBooking_Css::font( 'Helvetica Neue,Arial' ), 'Namen mit Leerzeichen bekommen Anfuehrungszeichen.' );
		$this->assertSame( '"3270"', FFBooking_Css::font( '3270' ), 'Eine Ziffer am Anfang verlangt Anfuehrungszeichen.' );
		$this->assertSame( '-apple-system, system-ui', FFBooking_Css::font( '-apple-system, system-ui' ) );
		$this->assertSame( '"Äpfel Serif"', FFBooking_Css::font( 'Äpfel Serif' ), 'Umlaute sind Buchstaben.' );
	}

	/**
	 * Eine CSS-Variable des Themes bleibt erlaubt, solange es nur der Name ist.
	 */
	public function test_css_variable_fuer_die_schrift() {
		$this->assertSame( 'var(--ff-font-display), serif', FFBooking_Css::font( 'var(--ff-font-display), serif' ) );
		$this->assertSame( 'var(--a)', FFBooking_Css::font( ' var( --a ) ' ) );
		$this->assertStringNotContainsString( 'var(', FFBooking_Css::font( 'var(--a, url(http://evil.test))' ), 'Mit Rueckfallwert ist es keine einfache Variable mehr.' );
	}

	/**
	 * Was Regeln oder Elemente aufbrechen koennte, faellt weg.
	 */
	public function test_schadcode_in_der_schriftliste_faellt_weg() {
		$eingaben = array(
			'Arial; } body { background: url(http://evil.test/x) } .x {',
			'</style><script>alert(1)</script>',
			'Arial" onload="x',
			'a{b}',
			'url(javascript:alert(1))',
			"Arial\n;color:red",
			'Arial\\',
			'x' . str_repeat( 'a', 400 ),
		);

		foreach ( $eingaben as $eingabe ) {
			$css = FFBooking_Css::font( $eingabe );
			$this->assertSame( false, strpbrk( $css, ';{}<>()\\/@:=!' ), 'Sonderzeichen in: ' . $eingabe );
			$this->assertSame( 0, substr_count( $css, '"' ) % 2, 'Anfuehrungszeichen unpaarig in: ' . $eingabe );
			$this->assertLessThanOrEqual( 220, strlen( $css ) );
		}
	}

	/**
	 * Hoechstens acht Schriften. Ungueltiges UTF-8 kommt nie durch, das Ergebnis ist immer gueltiges UTF-8.
	 */
	public function test_grenzen_der_schriftliste() {
		$this->assertCount( 8, explode( ', ', FFBooking_Css::font( 'a,b,c,d,e,f,g,h,i,j' ) ) );
		$this->assertSame( 'inherit', FFBooking_Css::font( ';;;{}' ) );

		foreach ( array( "\xB1\x31", "Arial\xC3\x28", "\xFF\xFE" ) as $kaputt ) {
			$css = FFBooking_Css::font( $kaputt );

			$this->assertSame( 1, preg_match( '//u', $css ), 'Gueltiges UTF-8 fuer ' . bin2hex( $kaputt ) );
			$this->assertSame( false, strpbrk( $css, ';{}<>()\\/@:=!' ) );
		}
	}

	/**
	 * Farben gelten nur als Hex-Wert.
	 */
	public function test_farben_nur_als_hex_wert() {
		$this->assertSame( '#ff1493', FFBooking_Css::color( '#ff1493', '#000000' ) );
		$this->assertSame( '#FFF', FFBooking_Css::color( '#FFF', '#000000' ) );
		$this->assertSame( '#2563eb', FFBooking_Css::color( 'red', '#2563eb' ) );
		$this->assertSame( '#2563eb', FFBooking_Css::color( 'url(x)', '#2563eb' ) );
		$this->assertSame( '#2563eb', FFBooking_Css::color( '#ff1493; background:red', '#2563eb' ) );
		$this->assertSame( '#2563eb', FFBooking_Css::color( '', '#2563eb' ) );
		$this->assertSame( '#2563eb', FFBooking_Css::color( null, '#2563eb' ) );
	}
}
