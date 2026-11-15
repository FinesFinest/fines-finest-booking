<?php
/**
 * Tests fuer die Textfassung der Plugin-Mails.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class MailTextTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['ffbooking_test_mail'] );
		$this->set_own_mail( false );
	}

	protected function tearDown(): void {
		$this->set_own_mail( false );
	}

	/**
	 * Schaltet die Markierung "eigene Mail" von aussen, ohne dispatch().
	 *
	 * @param bool $value Wert.
	 */
	protected function set_own_mail( $value ) {
		$property = new ReflectionProperty( 'FFBooking_Mailer', 'own_mail' );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * Ein Mailer-Objekt wie PHPMailer es nach wp_mail() liefert.
	 *
	 * @param string $body HTML.
	 * @return stdClass
	 */
	protected function mailer( $body = '<p>Hallo</p>' ) {
		$mailer              = new stdClass();
		$mailer->Body        = $body;
		$mailer->AltBody     = '';
		$mailer->ContentType = 'text/html';

		return $mailer;
	}

	/**
	 * Mails anderer Plugins fasst der Hook nicht an.
	 */
	public function test_fremde_mails_bleiben_unberuehrt() {
		$mailer = $this->mailer();

		FFBooking_Mailer::add_plain_text( $mailer );

		$this->assertSame( '', $mailer->AltBody );
		$this->assertSame( 'text/html', $mailer->ContentType );
	}

	/**
	 * Eine eigene Mail bekommt Textfassung und Typ multipart/alternative.
	 */
	public function test_eigene_mail_bekommt_textfassung_und_typ() {
		FFBooking_Mailer::dispatch( 'a@example.com', 'Betreff', '<p>Hallo Fine,</p><p>Dein Termin steht.</p>', array(), array() );

		$mailer = $GLOBALS['ffbooking_test_mail'];

		$this->assertSame( "Hallo Fine,\n\nDein Termin steht.", $mailer->AltBody );
		$this->assertSame( 'multipart/alternative', $mailer->ContentType );
		$this->assertSame( '<p>Hallo Fine,</p><p>Dein Termin steht.</p>', $mailer->Body, 'Das HTML bleibt wie es ist.' );
	}

	/**
	 * Mail-Plugins mit eigener Schnittstelle zum Anbieter lesen den Typ vor dem Senden.
	 *
	 * Fluent SMTP schickt bei Brevo die Textfassung nur mit, wenn der Typ ohne Zusaetze
	 * multipart/alternative lautet (BaseHandler::setAttributes und SendInBlue\Handler::postSend).
	 * Bis 1.3.0 stand dort text/html, und die Textfassung ging verloren.
	 */
	public function test_mail_plugins_sehen_multipart_alternative() {
		FFBooking_Mailer::dispatch( 'a@example.com', 'Betreff', '<p>Hallo</p>', array(), array() );

		$mailer = $GLOBALS['ffbooking_test_mail'];
		$typ    = trim( strtok( (string) $mailer->ContentType, ';' ) );

		$this->assertSame( 'multipart/alternative', $typ );
		$this->assertNotSame( '', $mailer->AltBody, 'Mit dem Typ muss auch eine Textfassung da sein.' );
	}

	/**
	 * Nach dem Versand ist die Markierung weg, die naechste fremde Mail bleibt unberuehrt.
	 */
	public function test_markierung_wird_zurueckgesetzt() {
		FFBooking_Mailer::dispatch( 'a@example.com', 'Betreff', '<p>Hallo</p>', array(), array() );

		$fremd = $this->mailer( '<p>Fremde Mail</p>' );
		FFBooking_Mailer::add_plain_text( $fremd );

		$this->assertSame( '', $fremd->AltBody );
		$this->assertSame( 'text/html', $fremd->ContentType );
	}

	/**
	 * Eine vorhandene Textfassung bleibt, reiner Text bleibt reiner Text.
	 */
	public function test_vorhandenes_und_reiner_text_bleiben() {
		$this->set_own_mail( true );

		$mit_text          = $this->mailer();
		$mit_text->AltBody = 'Schon da';
		FFBooking_Mailer::add_plain_text( $mit_text );
		$this->assertSame( 'Schon da', $mit_text->AltBody );
		$this->assertSame( 'text/html', $mit_text->ContentType );

		$text              = $this->mailer( 'Nur Text' );
		$text->ContentType = 'text/plain';
		FFBooking_Mailer::add_plain_text( $text );
		$this->assertSame( '', $text->AltBody );
		$this->assertSame( 'text/plain', $text->ContentType );

		$leer = $this->mailer( '<p></p>' );
		FFBooking_Mailer::add_plain_text( $leer );
		$this->assertSame( '', $leer->AltBody, 'Ohne Text keine leere Textfassung.' );
		$this->assertSame( 'text/html', $leer->ContentType, 'Und der Typ bleibt, wie er war.' );
	}

	/**
	 * Absaetze sind durch eine Leerzeile getrennt, Zeilen einer Tabelle durch einen Umbruch.
	 */
	public function test_absaetze_und_tabellenzeilen() {
		$this->assertSame( "Hallo\n\nZwei", FFBooking_Mailer::html_to_text( '<p>Hallo</p><p>Zwei</p>' ) );
		$this->assertSame( "Eins\nZwei", FFBooking_Mailer::html_to_text( 'Eins<br>Zwei' ) );
		$this->assertSame( "Eins\nZwei", FFBooking_Mailer::html_to_text( 'Eins<br />Zwei' ) );

		$html = '<table><tr><td>Termin</td><td>Kostenlose Erstanalyse</td></tr><tr><td>Wann</td><td>Montag, 2. November 2026</td></tr></table>';
		$this->assertSame( "Termin: Kostenlose Erstanalyse\nWann: Montag, 2. November 2026", FFBooking_Mailer::html_to_text( $html ) );
	}

	/**
	 * Links erscheinen mit ihrer Adresse, sonst fehlt der Absage-Link.
	 */
	public function test_links_erscheinen_mit_adresse() {
		$this->assertSame(
			'Termin absagen (https://example.com/x?a=1&b=2)',
			FFBooking_Mailer::html_to_text( '<a href="https://example.com/x?a=1&amp;b=2" style="color:red;">Termin absagen</a>' )
		);
		$this->assertSame( 'https://example.com', FFBooking_Mailer::html_to_text( '<a href="https://example.com">https://example.com</a>' ), 'Gleicher Text und Adresse: nur einmal.' );
		$this->assertSame( 'a@example.com', FFBooking_Mailer::html_to_text( '<a href="mailto:a@example.com">a@example.com</a>' ) );
		$this->assertSame( 'Schreib mir (a@example.com)', FFBooking_Mailer::html_to_text( '<a href="mailto:a@example.com">Schreib mir</a>' ) );
		$this->assertSame( 'https://x.test/y', FFBooking_Mailer::html_to_text( "<a class='b' href='https://x.test/y'></a>" ), 'Ohne Beschriftung zaehlt die Adresse.' );
		$this->assertSame( 'Zeile eins zwei (https://x.test)', FFBooking_Mailer::html_to_text( "<a href=\"https://x.test\">Zeile\n eins <b>zwei</b></a>" ) );
	}

	/**
	 * Kopfbereich, Stile und Skripte tragen keinen Text, Entitaeten werden aufgeloest.
	 */
	public function test_kopf_stile_und_entitaeten() {
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>p{color:red}</style></head><body><script>x()</script><p>Fines &amp; Finest &middot; Berlin</p></body></html>';

		$this->assertSame( 'Fines & Finest · Berlin', FFBooking_Mailer::html_to_text( $html ) );
	}

	/**
	 * Eine Mail im Aufbau der echten Mails ergibt lesbaren Text ohne Reste von Tags.
	 */
	public function test_mail_im_aufbau_der_echten_mails() {
		$html  = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head><body style="margin:0;"><table role="presentation" width="100%"><tr><td align="center">';
		$html .= '<table role="presentation" width="100%"><tr><td style="height:4px;background:#2563eb;"></td></tr>';
		$html .= '<tr><td style="padding:32px;"><h1 style="margin:0;">Termin bestätigt</h1></td></tr>';
		$html .= '<tr><td style="padding:0 32px;"><p>Hallo Fine,</p><p>Dein Termin ist gebucht. Hier sind die Einzelheiten:</p>';
		$html .= '<table role="presentation"><tr><td style="color:#5a6068;">Termin</td><td style="color:#1a1d21;">Kennenlernen</td></tr>';
		$html .= '<tr><td>Wo</td><td><a href="https://meet.google.com/abc-defg-hij" style="color:#0e7490;">Google Meet</a></td></tr></table>';
		$html .= '<p style="margin:0 0 20px;"><a href="https://meet.google.com/abc-defg-hij" style="display:inline-block;">Zum Videocall</a></p>';
		$html .= '<p>Wenn er nicht mehr passt: <a href="https://example.com/termin/?ffbooking_cancel=abc123">Termin absagen</a></p></td></tr>';
		$html .= '<tr><td style="border-top:1px solid rgba(255,255,255,0.08);">Fines Finest &middot; <a href="https://example.com">example.com</a></td></tr>';
		$html .= '</table></td></tr></table></body></html>';

		$text = FFBooking_Mailer::html_to_text( $html );

		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( '>', $text );
		$this->assertStringContainsString( "Termin bestätigt\n\nHallo Fine,\n\nDein Termin ist gebucht. Hier sind die Einzelheiten:", $text );
		$this->assertStringContainsString( "Termin: Kennenlernen\nWo: Google Meet (https://meet.google.com/abc-defg-hij)", $text );
		$this->assertStringContainsString( 'Termin absagen (https://example.com/termin/?ffbooking_cancel=abc123)', $text, 'Der Absage-Link steht in der Textfassung.' );
		$this->assertStringContainsString( 'Fines Finest · example.com (https://example.com)', $text );
		$this->assertDoesNotMatchRegularExpression( "/\n{3,}/", $text, 'Nie mehr als eine Leerzeile.' );
	}
}
