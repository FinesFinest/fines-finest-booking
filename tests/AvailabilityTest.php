<?php
/**
 * Tests fuer die Slot-Berechnung.
 *
 * Alle Faelle rechnen mit festen Zeitpunkten in der Zeitzone Europe/Berlin.
 * Grundlage ist meist der Sonntag, 1. November 2026, 12:00 Uhr. Der Montag
 * danach ist der 2. November 2026 (Normalzeit, UTC+1).
 *
 * @package FinesFinestBooking
 */

use PHPUnit\Framework\TestCase;

class AvailabilityTest extends TestCase {

	/**
	 * Zeitpunkt in Berliner Zeit.
	 *
	 * @param string $when Datum und Uhrzeit.
	 * @return DateTimeImmutable
	 */
	private function berlin( $when ) {
		return new DateTimeImmutable( $when, new DateTimeZone( 'Europe/Berlin' ) );
	}

	/**
	 * Belegter Zeitraum in Berliner Zeit als UTC-Zeitstempel, so wie ihn Buchungen und Google liefern.
	 *
	 * @param string $start Beginn.
	 * @param string $end   Ende.
	 * @return array
	 */
	private function busy( $start, $end ) {
		return array( $this->berlin( $start )->getTimestamp(), $this->berlin( $end )->getTimestamp() );
	}

	/**
	 * Termintyp mit sinnvollen Standardwerten: montags 9 bis 12 und 14 bis 17 Uhr,
	 * 30 Minuten im 30-Minuten-Raster, ohne Puffer, ohne Vorlauf, ohne Limit.
	 *
	 * @param array $overrides Abweichungen.
	 * @return array
	 */
	private function event( array $overrides = array() ) {
		$defaults = array(
			'id'            => 1,
			'duration'      => 30,
			'slot_step'     => 30,
			'buffer_before' => 0,
			'buffer_after'  => 0,
			'min_notice'    => 0,
			'max_days'      => 45,
			'daily_limit'   => 0,
			'availability'  => FFB_Event_Types::normalize_availability(
				array( '1' => array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) ) )
			),
			'blackouts'     => array(),
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Globale Grenzen, standardmaessig ohne Wirkung.
	 *
	 * @param array $overrides Abweichungen.
	 * @return array
	 */
	private function limits( array $overrides = array() ) {
		return array_merge(
			array(
				'enforce'    => false,
				'hours'      => array(),
				'min_notice' => 0,
				'blackouts'  => array(),
			),
			$overrides
		);
	}

	/**
	 * Rechnet Slots wie get_slots(), aber mit festen Eingaben.
	 *
	 * @param array             $event  Termintyp.
	 * @param DateTimeImmutable $now    Jetzt.
	 * @param array             $busy   Belegte Zeitraeume.
	 * @param array             $daily  Buchungen pro Tag.
	 * @param array|null        $global Globale Grenzen.
	 * @param string            $from   Startdatum.
	 * @param int               $days   Tage.
	 * @return array
	 */
	private function slots( array $event, DateTimeImmutable $now, array $busy = array(), array $daily = array(), $global = null, $from = '', $days = 14 ) {
		$range = FFB_Availability::range( $event, $now, $from, $days );

		return FFB_Availability::compute( $event, $now, $range, $busy, $daily, null === $global ? $this->limits() : $global );
	}

	/**
	 * Uhrzeit-Labels eines Tages aus dem Ergebnis.
	 *
	 * @param array  $result Ergebnis.
	 * @param string $date   Tag Y-m-d.
	 * @return array
	 */
	private function labels( array $result, $date ) {
		foreach ( $result['days'] as $day ) {
			if ( $day['date'] === $date ) {
				return array_column( $day['slots'], 'label' );
			}
		}

		return array();
	}

	/**
	 * UTC-Startzeiten eines Tages aus dem Ergebnis.
	 *
	 * @param array  $result Ergebnis.
	 * @param string $date   Tag Y-m-d.
	 * @return array
	 */
	private function starts( array $result, $date ) {
		foreach ( $result['days'] as $day ) {
			if ( $day['date'] === $date ) {
				return array_column( $day['slots'], 'start' );
			}
		}

		return array();
	}

	/**
	 * Nur Tage mit Fenstern tauchen auf, und ein Fenster liefert genau die Slots, die hineinpassen.
	 */
	public function test_wochenfenster_ergeben_zwoelf_slots_am_montag_und_keine_an_anderen_tagen() {
		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), array(), array(), null, '', 7 );

		$this->assertSame( array( '2026-11-02' ), array_column( $result['days'], 'date' ), 'Innerhalb einer Woche ist nur der Montag buchbar.' );
		$this->assertSame(
			array( '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30' ),
			$this->labels( $result, '2026-11-02' )
		);
		$this->assertSame( '2026-11-02T08:00:00Z', $this->starts( $result, '2026-11-02' )[0], '9 Uhr Berliner Normalzeit ist 8 Uhr UTC.' );
		$this->assertSame( 'Europe/Berlin', $result['timezone'] );
	}

	/**
	 * Ein Slot, der genau am Fensterende aufhoert, zaehlt noch. Einer, der darueber hinausragt, nicht.
	 */
	public function test_slot_darf_genau_am_fensterende_aufhoeren_aber_nicht_darueber_hinaus() {
		$event = $this->event(
			array(
				'duration'     => 45,
				'availability' => FFB_Event_Types::normalize_availability( array( '1' => array( array( '09:00', '12:00' ) ) ) ),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-11-01 12:00' ) );

		// 11:00 bis 11:45 passt, 11:30 bis 12:15 nicht mehr.
		$this->assertSame( array( '09:00', '09:30', '10:00', '10:30', '11:00' ), $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Die Vorlaufzeit des Termintyps blendet die naechsten Slots aus.
	 */
	public function test_vorlaufzeit_blendet_fruehe_slots_aus() {
		$event  = $this->event( array( 'min_notice' => 120 ) );
		$result = $this->slots( $event, $this->berlin( '2026-11-02 08:00' ) );

		// Fruehestens 10:00 Uhr: 9:00 und 9:30 fallen weg.
		$this->assertSame( '10:00', $this->labels( $result, '2026-11-02' )[0] );
		$this->assertCount( 10, $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Von Termintyp und globaler Grenze gilt die laengere Vorlaufzeit, egal wo sie steht.
	 */
	public function test_laengere_vorlaufzeit_gewinnt_in_beide_richtungen() {
		$now = $this->berlin( '2026-11-02 08:00' );

		$a = $this->slots( $this->event( array( 'min_notice' => 60 ) ), $now, array(), array(), $this->limits( array( 'min_notice' => 180 ) ) );
		$b = $this->slots( $this->event( array( 'min_notice' => 180 ) ), $now, array(), array(), $this->limits( array( 'min_notice' => 60 ) ) );

		$this->assertSame( array( '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30' ), $this->labels( $a, '2026-11-02' ) );
		$this->assertSame( $this->labels( $a, '2026-11-02' ), $this->labels( $b, '2026-11-02' ) );
	}

	/**
	 * Ein belegter Zeitraum sperrt den Slot selbst und mit Puffer auch die Nachbarn.
	 *
	 * Belegt: 10:00 bis 10:30 Uhr. Puffer 15 Minuten danach.
	 * 9:30 bis 10:00 faellt weg, weil der eigene Puffer in die Belegung liefe.
	 * 10:30 bis 11:00 faellt weg, weil der Abstand zur Belegung fehlt.
	 */
	public function test_puffer_haelt_abstand_zu_belegtem_zeitraum() {
		$event  = $this->event( array( 'buffer_after' => 15 ) );
		$busy   = array( $this->busy( '2026-11-02 10:00', '2026-11-02 10:30' ) );
		$result = $this->slots( $event, $this->berlin( '2026-11-01 12:00' ), $busy );

		$this->assertSame( array( '09:00', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30' ), $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Ohne Puffer darf ein Slot direkt an eine Belegung grenzen.
	 */
	public function test_ohne_puffer_grenzen_slots_direkt_an_belegungen() {
		$busy   = array( $this->busy( '2026-11-02 10:00', '2026-11-02 10:30' ) );
		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), $busy );

		$this->assertSame( array( '09:00', '09:30', '10:30', '11:00', '11:30' ), array_slice( $this->labels( $result, '2026-11-02' ), 0, 5 ) );
	}

	/**
	 * Von Puffer davor und danach gilt der groessere, in beide Richtungen.
	 */
	public function test_groesserer_puffer_gilt_symmetrisch() {
		$busy = array( $this->busy( '2026-11-02 10:00', '2026-11-02 10:30' ) );
		$now  = $this->berlin( '2026-11-01 12:00' );

		$vorher  = $this->slots( $this->event( array( 'buffer_before' => 15 ) ), $now, $busy );
		$nachher = $this->slots( $this->event( array( 'buffer_after' => 15 ) ), $now, $busy );

		$this->assertSame( $this->labels( $nachher, '2026-11-02' ), $this->labels( $vorher, '2026-11-02' ) );
	}

	/**
	 * Eine Belegung, die genau beim Slotbeginn endet, stoert nicht.
	 */
	public function test_belegung_bis_zum_slotbeginn_stoert_nicht() {
		$busy   = array( $this->busy( '2026-11-02 08:30', '2026-11-02 09:00' ) );
		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), $busy );

		$this->assertSame( '09:00', $this->labels( $result, '2026-11-02' )[0] );
	}

	/**
	 * Ist der ganze Tag belegt, taucht er gar nicht auf.
	 */
	public function test_komplett_belegter_tag_wird_nicht_gelistet() {
		$busy   = array( $this->busy( '2026-11-02 00:00', '2026-11-03 00:00' ) );
		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), $busy, array(), null, '', 7 );

		$this->assertSame( array(), $result['days'] );
	}

	/**
	 * Das Tageslimit sperrt den Tag, sobald es erreicht ist.
	 */
	public function test_tageslimit_sperrt_den_tag_sobald_es_erreicht_ist() {
		$event = $this->event( array( 'daily_limit' => 2 ) );
		$now   = $this->berlin( '2026-11-01 12:00' );

		$voll = $this->slots( $event, $now, array(), array( '2026-11-02' => 2 ), null, '', 7 );
		$frei = $this->slots( $event, $now, array(), array( '2026-11-02' => 1 ), null, '', 7 );

		$this->assertSame( array(), $voll['days'] );
		$this->assertCount( 12, $this->labels( $frei, '2026-11-02' ) );
	}

	/**
	 * Sperrtage des Termintyps und globale Sperrtage nehmen den Tag heraus, die naechste Woche bleibt.
	 */
	public function test_sperrtage_nehmen_den_tag_heraus() {
		$now = $this->berlin( '2026-11-01 12:00' );

		$typ    = $this->slots( $this->event( array( 'blackouts' => array( '2026-11-02' ) ) ), $now );
		$global = $this->slots( $this->event(), $now, array(), array(), $this->limits( array( 'blackouts' => array( '2026-11-02' ) ) ) );

		$this->assertSame( array( '2026-11-09' ), array_column( $typ['days'], 'date' ) );
		$this->assertSame( array( '2026-11-09' ), array_column( $global['days'], 'date' ) );
	}

	/**
	 * Erzwungene Geschaeftszeiten schneiden die Fenster des Termintyps.
	 */
	public function test_geschaeftszeiten_schneiden_die_fenster() {
		$limits = $this->limits(
			array(
				'enforce' => true,
				'hours'   => array( '1' => array( array( '10:00', '15:00' ) ) ),
			)
		);

		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), array(), array(), $limits );

		$this->assertSame( array( '10:00', '10:30', '11:00', '11:30', '14:00', '14:30' ), $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Erzwungene Geschaeftszeiten ohne Fenster an einem Wochentag sperren ihn komplett.
	 */
	public function test_leere_geschaeftszeiten_sperren_den_wochentag() {
		$limits = $this->limits(
			array(
				'enforce' => true,
				'hours'   => array( '1' => array() ),
			)
		);

		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), array(), array(), $limits );

		$this->assertSame( array(), $result['days'] );
	}

	/**
	 * Ohne den Haken bleiben die Geschaeftszeiten wirkungslos.
	 */
	public function test_geschaeftszeiten_ohne_haken_sind_wirkungslos() {
		$limits = $this->limits(
			array(
				'enforce' => false,
				'hours'   => array( '1' => array( array( '10:00', '15:00' ) ) ),
			)
		);

		$result = $this->slots( $this->event(), $this->berlin( '2026-11-01 12:00' ), array(), array(), $limits );

		$this->assertCount( 12, $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Die Schnittmenge zweier Fenstermengen enthaelt nie mehr Zeit als beide zusammen erlauben.
	 */
	public function test_schnittmenge_der_fenster() {
		$typ    = array( array( '09:00', '12:00' ), array( '14:00', '17:00' ) );
		$global = array( array( '10:00', '15:00' ) );

		$this->assertSame( array( array( '10:00', '12:00' ), array( '14:00', '15:00' ) ), FFB_Availability::intersect_windows( $typ, $global ) );
		$this->assertSame( array(), FFB_Availability::intersect_windows( $typ, array( array( '12:00', '14:00' ) ) ), 'Die Mittagspause ueberschneidet nichts.' );
		$this->assertSame( array(), FFB_Availability::intersect_windows( $typ, array() ) );
	}

	/**
	 * Der Buchungshorizont begrenzt den Zeitraum, egal wie viele Tage angefragt werden.
	 */
	public function test_horizont_begrenzt_den_zeitraum() {
		$event = $this->event( array( 'max_days' => 3 ) );
		$now   = $this->berlin( '2026-11-01 12:00' );

		$range = FFB_Availability::range( $event, $now, '', 14 );
		$this->assertSame( '2026-11-01', $range['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-11-04', $range['end']->format( 'Y-m-d' ), 'Das Ende ist exklusiv: 1., 2. und 3. November sind drin.' );
		$this->assertSame( '2026-11-04', $range['horizon']->format( 'Y-m-d' ) );

		$result = $this->slots( $event, $now );
		$this->assertSame( array( '2026-11-02' ), array_column( $result['days'], 'date' ) );
		$this->assertSame( '2026-11-04', $result['horizon'] );

		$dahinter = $this->slots( $event, $now, array(), array(), null, '2026-11-10' );
		$this->assertSame( array(), $dahinter['days'], 'Ein Startdatum hinter dem Horizont liefert nichts.' );
	}

	/**
	 * Ein Startdatum in der Vergangenheit oder Unsinn als Datum beginnt heute.
	 */
	public function test_startdatum_wird_auf_heute_begrenzt() {
		$event = $this->event();
		$now   = $this->berlin( '2026-11-01 12:00' );

		$this->assertSame( '2026-11-01', FFB_Availability::range( $event, $now, '2026-10-01' )['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-11-01', FFB_Availability::range( $event, $now, 'quatsch' )['start']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-11-09', FFB_Availability::range( $event, $now, '2026-11-09' )['start']->format( 'Y-m-d' ) );
	}

	/**
	 * Die Anzahl Tage wird auf 1 bis 60 begrenzt.
	 */
	public function test_anzahl_tage_wird_begrenzt() {
		$event = $this->event( array( 'max_days' => 365 ) );
		$now   = $this->berlin( '2026-11-01 12:00' );

		$this->assertSame( '2026-11-02', FFB_Availability::range( $event, $now, '', 0 )['end']->format( 'Y-m-d' ) );
		$this->assertSame( '2026-12-31', FFB_Availability::range( $event, $now, '', 999 )['end']->format( 'Y-m-d' ) );
	}

	/**
	 * Heute vor der Vorlaufzeit liegende Slots fallen weg, der Tag selbst bleibt buchbar.
	 */
	public function test_heute_bleiben_nur_slots_nach_der_vorlaufzeit() {
		$event  = $this->event( array( 'min_notice' => 30 ) );
		$result = $this->slots( $event, $this->berlin( '2026-11-02 10:10' ) );

		// Fruehestens 10:40 Uhr: der naechste Slot im Raster ist 11:00 Uhr.
		$this->assertSame( array( '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30' ), $this->labels( $result, '2026-11-02' ) );
	}

	/**
	 * Sommerzeitbeginn am 29. Maerz 2026: 9 Uhr ist am Samstag 8 Uhr UTC, am Sonntag 7 Uhr UTC.
	 */
	public function test_sommerzeitbeginn_verschiebt_die_utc_zeit_nicht_die_uhrzeit() {
		$event = $this->event(
			array(
				'availability' => FFB_Event_Types::normalize_availability(
					array(
						'6' => array( array( '09:00', '12:00' ) ),
						'7' => array( array( '09:00', '12:00' ) ),
					)
				),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-03-27 12:00' ), array(), array(), null, '', 3 );

		$this->assertSame( '2026-03-28T08:00:00Z', $this->starts( $result, '2026-03-28' )[0] );
		$this->assertSame( '2026-03-29T07:00:00Z', $this->starts( $result, '2026-03-29' )[0] );
		$this->assertSame( $this->labels( $result, '2026-03-28' ), $this->labels( $result, '2026-03-29' ), 'Die angezeigten Uhrzeiten bleiben gleich.' );
	}

	/**
	 * In der Nacht des Sommerzeitbeginns gibt es keine Slots fuer die fehlende Stunde 2 bis 3 Uhr.
	 */
	public function test_sommerzeitbeginn_ueberspringt_die_fehlende_stunde() {
		$event = $this->event(
			array(
				'availability' => FFB_Event_Types::normalize_availability( array( '7' => array( array( '01:00', '04:00' ) ) ) ),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-03-28 12:00' ), array(), array(), null, '', 2 );
		$labels = $this->labels( $result, '2026-03-29' );
		$starts = $this->starts( $result, '2026-03-29' );

		$this->assertSame( array( '01:00', '01:30', '03:00', '03:30' ), $labels );
		$this->assertSame( array( '2026-03-29T00:00:00Z', '2026-03-29T00:30:00Z', '2026-03-29T01:00:00Z', '2026-03-29T01:30:00Z' ), $starts, 'In UTC laufen die Slots lueckenlos im 30-Minuten-Takt.' );
	}

	/**
	 * Sommerzeitende am 25. Oktober 2026: 9 Uhr ist am Samstag 7 Uhr UTC, am Sonntag 8 Uhr UTC.
	 */
	public function test_sommerzeitende_verschiebt_die_utc_zeit_nicht_die_uhrzeit() {
		$event = $this->event(
			array(
				'availability' => FFB_Event_Types::normalize_availability(
					array(
						'6' => array( array( '09:00', '12:00' ) ),
						'7' => array( array( '09:00', '12:00' ) ),
					)
				),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-10-23 12:00' ), array(), array(), null, '', 3 );

		$this->assertSame( '2026-10-24T07:00:00Z', $this->starts( $result, '2026-10-24' )[0] );
		$this->assertSame( '2026-10-25T08:00:00Z', $this->starts( $result, '2026-10-25' )[0] );
	}

	/**
	 * In der Nacht des Sommerzeitendes gibt es die Stunde 2 bis 3 Uhr zweimal.
	 *
	 * Jede Uhrzeit wird genau einmal angeboten, damit im Buchungsfeld kein Slot
	 * doppelt erscheint. Die UTC-Startzeiten sind eindeutig und steigen an.
	 * Nachts bucht niemand, aber die Rechnung soll dabei nicht kippen.
	 */
	public function test_sommerzeitende_bietet_jede_uhrzeit_einmal_an() {
		$event = $this->event(
			array(
				'availability' => FFB_Event_Types::normalize_availability( array( '7' => array( array( '01:00', '04:00' ) ) ) ),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-10-24 12:00' ), array(), array(), null, '', 2 );
		$starts = $this->starts( $result, '2026-10-25' );
		$labels = $this->labels( $result, '2026-10-25' );

		$this->assertSame( array( '01:00', '01:30', '02:00', '02:30', '03:00', '03:30' ), $labels );
		$this->assertSame( $starts, array_values( array_unique( $starts ) ), 'Keine UTC-Zeit doppelt.' );
		$sorted = $starts;
		sort( $sorted );
		$this->assertSame( $sorted, $starts, 'Die Startzeiten steigen an.' );
		$this->assertSame( '2026-10-24T23:00:00Z', $starts[0], '1 Uhr Sommerzeit ist 23 Uhr UTC am Vortag.' );
		$this->assertSame( '2026-10-25T02:30:00Z', $starts[5], '3:30 Uhr Normalzeit ist 2:30 Uhr UTC.' );
	}

	/**
	 * Ein Fenster darf bis 23:59 Uhr reichen, ohne dass die Rechnung in den naechsten Tag kippt.
	 */
	public function test_fenster_bis_kurz_vor_mitternacht() {
		$event = $this->event(
			array(
				'availability' => FFB_Event_Types::normalize_availability( array( '1' => array( array( '22:00', '23:59' ) ) ) ),
			)
		);

		$result = $this->slots( $event, $this->berlin( '2026-11-01 12:00' ) );

		$this->assertSame( array( '22:00', '22:30', '23:00' ), $this->labels( $result, '2026-11-02' ), '23:30 bis 0:00 passt nicht mehr in ein Fenster bis 23:59.' );
	}
}
