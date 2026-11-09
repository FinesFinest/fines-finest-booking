<?php
/**
 * Tests fuer das Erinnerungsfenster und die Heute-Morgen-Entscheidung.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class ReminderTest extends TestCase {

	/**
	 * Zeitstempel eines UTC-Zeitpunkts.
	 *
	 * @param string $when Datum und Uhrzeit in UTC.
	 * @return int
	 */
	private function utc( $when ) {
		return ( new DateTimeImmutable( $when, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/**
	 * Zeitpunkt relativ zu jetzt.
	 *
	 * @param int    $now    Jetzt als Zeitstempel.
	 * @param string $modify Verschiebung.
	 * @return DateTimeImmutable
	 */
	private function at( $now, $modify ) {
		return ( new DateTimeImmutable( '@' . $now ) )->modify( $modify );
	}

	/**
	 * Wer frueher als 25 Stunden vor dem Termin bucht, bekommt eine Erinnerung. Wer spaeter bucht, nicht.
	 */
	public function test_erinnerung_nur_ausserhalb_des_fensters() {
		$now = $this->utc( '2026-11-02 10:00:00' );

		$this->assertTrue( FFBooking_Cron::reminder_needed( $this->at( $now, '+3 days' ), $now ) );
		$this->assertTrue( FFBooking_Cron::reminder_needed( $this->at( $now, '+25 hours' ), $now ), 'Genau 25 Stunden liegen noch ausserhalb des Fensters.' );
		$this->assertFalse( FFBooking_Cron::reminder_needed( $this->at( $now, '+25 hours -1 second' ), $now ) );
		$this->assertFalse( FFBooking_Cron::reminder_needed( $this->at( $now, '+2 hours' ), $now ) );
	}

	/**
	 * Heute oder morgen wird in der Zeitzone der Website entschieden, nicht in UTC.
	 */
	public function test_heute_und_morgen_in_berliner_zeit() {
		// Jetzt: 2. November 2026, 10:00 UTC, also 11:00 Uhr in Berlin.
		$now = $this->utc( '2026-11-02 10:00:00' );

		$this->assertSame( 'today', FFBooking_Mailer::relative_day( '2026-11-02 14:00:00', $now ) );
		$this->assertSame( 'tomorrow', FFBooking_Mailer::relative_day( '2026-11-03 08:00:00', $now ) );
		$this->assertSame( '', FFBooking_Mailer::relative_day( '2026-11-04 08:00:00', $now ) );

		// 23:30 UTC ist in Berlin schon 00:30 Uhr des naechsten Tages.
		$spaet = $this->utc( '2026-11-02 23:30:00' );
		$this->assertSame( 'today', FFBooking_Mailer::relative_day( '2026-11-03 07:00:00', $spaet ) );
		$this->assertSame( 'tomorrow', FFBooking_Mailer::relative_day( '2026-11-04 07:00:00', $spaet ) );
	}

	/**
	 * Der Sommerzeitwechsel bringt die Tagesgrenze nicht durcheinander.
	 */
	public function test_tagesgrenze_ueber_den_sommerzeitwechsel() {
		$samstag = $this->utc( '2026-03-28 10:00:00' );

		$this->assertSame( 'tomorrow', FFBooking_Mailer::relative_day( '2026-03-29 08:00:00', $samstag ) );
	}
}
