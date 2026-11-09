<?php
/**
 * Tests fuer die Bewertung der Erinnerungsaufgabe im Einrichtungsstand.
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class CronStatusTest extends TestCase {

	/**
	 * Ohne geplanten Lauf fehlt die Aufgabe.
	 */
	public function test_nicht_eingeplant() {
		$this->assertSame( 'missing', FFBooking_Cron::evaluate( 0, 1800000000 ) );
		$this->assertSame( 'missing', FFBooking_Cron::evaluate( false, 1800000000 ) );
	}

	/**
	 * Ein geplanter Lauf in der Zukunft ist in Ordnung, ebenso einer, der hoechstens eine Stunde zurueckliegt.
	 */
	public function test_in_ordnung_bis_eine_stunde_verzug() {
		$now = 1800000000;

		$this->assertSame( 'ok', FFBooking_Cron::evaluate( $now + 600, $now ), 'Naechster Lauf in zehn Minuten.' );
		$this->assertSame( 'ok', FFBooking_Cron::evaluate( $now, $now ), 'Genau jetzt faellig.' );
		$this->assertSame( 'ok', FFBooking_Cron::evaluate( $now - 3600, $now ), 'Eine Stunde Verzug ist bei WP-Cron normal.' );
	}

	/**
	 * Mehr als eine Stunde Verzug heisst: Der Cron wird nicht angestossen.
	 */
	public function test_ueberfaellig_ab_einer_stunde_und_einer_sekunde() {
		$now = 1800000000;

		$this->assertSame( 'overdue', FFBooking_Cron::evaluate( $now - 3601, $now ) );
		$this->assertSame( 'overdue', FFBooking_Cron::evaluate( $now - 86400, $now ) );
	}
}
