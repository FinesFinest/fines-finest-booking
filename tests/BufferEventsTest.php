<?php
/**
 * Tests fuer die Sperrzeiten im Google-Kalender.
 *
 * Geprueft wird der Bauplan der beiden Eintraege, ohne Google. Die Buchung
 * liegt am Montag, 2. November 2026, 8:00 bis 8:30 Uhr Berliner Zeit (7:00 UTC).
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class BufferEventsTest extends TestCase {

	/**
	 * Buchung mit festen Zeiten in UTC.
	 *
	 * @param string $start_utc Beginn.
	 * @param string $end_utc   Ende.
	 * @return array
	 */
	private function booking( $start_utc = '2026-11-02 07:00:00', $end_utc = '2026-11-02 07:30:00' ) {
		return array(
			'id'        => 7,
			'start_utc' => $start_utc,
			'end_utc'   => $end_utc,
			'name'      => 'Maria Keller',
			'email'     => 'maria@example.com',
			'phone'     => '',
			'source'    => '',
			'answers'   => array(),
		);
	}

	/**
	 * Termintyp mit 30 Minuten davor und 15 danach.
	 *
	 * @param array $overrides Abweichungen.
	 * @return array
	 */
	private function event( array $overrides = array() ) {
		return array_merge(
			array(
				'title'          => 'Erstanalyse',
				'calendar_title' => '',
				'buffer_before'  => 30,
				'buffer_after'   => 15,
				'location'       => 'meet',
				'location_note'  => '',
			),
			$overrides
		);
	}

	/**
	 * Termin 8:00 bis 8:30 Uhr: Vorbereitung 7:30 bis 8:00, Nachbereitung 8:30 bis 8:45.
	 */
	public function test_vorbereitung_und_nachbereitung_umschliessen_den_termin() {
		$payloads = FFB_Google::buffer_payloads( $this->booking(), $this->event() );

		$this->assertSame( array( 'before', 'after' ), array_keys( $payloads ) );

		$this->assertSame( 'Preparation: Erstanalyse with Maria Keller', $payloads['before']['summary'] );
		$this->assertSame( '2026-11-02T07:30:00', $payloads['before']['start']['dateTime'] );
		$this->assertSame( '2026-11-02T08:00:00', $payloads['before']['end']['dateTime'] );

		$this->assertSame( 'Follow-up: Erstanalyse with Maria Keller', $payloads['after']['summary'] );
		$this->assertSame( '2026-11-02T08:30:00', $payloads['after']['start']['dateTime'] );
		$this->assertSame( '2026-11-02T08:45:00', $payloads['after']['end']['dateTime'] );
		$this->assertSame( 'Europe/Berlin', $payloads['after']['end']['timeZone'] );
	}

	/**
	 * Sperrzeiten sind belegt, ohne Gaeste, ohne Erinnerung, ohne Meet.
	 */
	public function test_sperrzeiten_sind_belegt_und_ohne_gaeste() {
		$payloads = FFB_Google::buffer_payloads( $this->booking(), $this->event() );

		$this->assertCount( 2, $payloads );
		foreach ( $payloads as $payload ) {
			$this->assertSame( 'opaque', $payload['transparency'] );
			$this->assertArrayNotHasKey( 'attendees', $payload );
			$this->assertArrayNotHasKey( 'conferenceData', $payload );
			$this->assertFalse( $payload['reminders']['useDefault'] );
			$this->assertSame( array(), $payload['reminders']['overrides'] );
		}
	}

	/**
	 * Ein Puffer von 0 Minuten ergibt keinen Eintrag.
	 */
	public function test_puffer_null_ergibt_keinen_eintrag() {
		$keine = FFB_Google::buffer_payloads(
			$this->booking(),
			$this->event(
				array(
					'buffer_before' => 0,
					'buffer_after'  => 0,
				)
			)
		);
		$this->assertSame( array(), $keine );

		$nur_danach = FFB_Google::buffer_payloads( $this->booking(), $this->event( array( 'buffer_before' => 0 ) ) );
		$this->assertSame( array( 'after' ), array_keys( $nur_danach ) );
	}

	/**
	 * Der Titel folgt der Vorlage des Termintyps fuer den Kalendereintrag.
	 */
	public function test_titel_folgt_der_kalendervorlage() {
		$payloads = FFB_Google::buffer_payloads( $this->booking(), $this->event( array( 'calendar_title' => '{name} ({termintyp})' ) ) );

		$this->assertSame( 'Preparation: Maria Keller (Erstanalyse)', $payloads['before']['summary'] );
		$this->assertSame( 'Follow-up: Maria Keller (Erstanalyse)', $payloads['after']['summary'] );
	}

	/**
	 * Sommerzeitbeginn am 29. Maerz 2026: 30 Minuten vor 3:00 Uhr MESZ sind 1:30 Uhr MEZ,
	 * weil die Stunde dazwischen fehlt. Die Vorbereitung dauert trotzdem 30 echte Minuten.
	 */
	public function test_vorbereitung_ueber_den_sommerzeitbeginn() {
		// 3:00 Uhr MESZ ist 1:00 Uhr UTC.
		$payloads = FFB_Google::buffer_payloads( $this->booking( '2026-03-29 01:00:00', '2026-03-29 01:30:00' ), $this->event() );

		$this->assertSame( '2026-03-29T01:30:00', $payloads['before']['start']['dateTime'] );
		$this->assertSame( '2026-03-29T03:00:00', $payloads['before']['end']['dateTime'] );
		$this->assertSame( '2026-03-29T03:30:00', $payloads['after']['start']['dateTime'] );
		$this->assertSame( '2026-03-29T03:45:00', $payloads['after']['end']['dateTime'] );
	}
}
