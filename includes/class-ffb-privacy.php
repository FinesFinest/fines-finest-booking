<?php
/**
 * DSGVO: Datenauskunft, Loeschung, Hinweistext.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Haengt sich in die WordPress-Datenschutz-Werkzeuge.
 */
class FFB_Privacy {

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy_text' ) );
	}

	/**
	 * Exporter registrieren.
	 *
	 * @param array $exporters Liste.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ff-booking'] = array(
			'exporter_friendly_name' => 'Terminbuchungen',
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Eraser registrieren.
	 *
	 * @param array $erasers Liste.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ff-booking'] = array(
			'eraser_friendly_name' => 'Terminbuchungen',
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exportiert alle Buchungen einer Mailadresse.
	 *
	 * @param string $email Adresse.
	 * @param int    $page  Seite.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;

		$table = FFB_Install::table_bookings();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$items = array();

		foreach ( (array) $rows as $row ) {
			$event = FFB_Event_Types::get( $row['event_type_id'] );

			$data = array(
				array(
					'name'  => 'Termin',
					'value' => $event ? $event['title'] : '-',
				),
				array(
					'name'  => 'Zeitpunkt',
					'value' => FFB_Mailer::format_datetime( $row['start_utc'] ),
				),
				array(
					'name'  => 'Name',
					'value' => $row['name'],
				),
				array(
					'name'  => 'E-Mail',
					'value' => $row['email'],
				),
				array(
					'name'  => 'Telefon',
					'value' => $row['phone'],
				),
				array(
					'name'  => 'Status',
					'value' => $row['status'],
				),
				array(
					'name'  => 'Einwilligung erteilt am',
					'value' => (string) $row['consent_at'],
				),
			);

			foreach ( (array) json_decode( (string) $row['answers'], true ) as $label => $value ) {
				$data[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}

			$items[] = array(
				'group_id'    => 'ffb-bookings',
				'group_label' => 'Terminbuchungen',
				'item_id'     => 'ffb-booking-' . $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Loescht alle Buchungen einer Mailadresse.
	 *
	 * @param string $email Adresse.
	 * @param int    $page  Seite.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		$table = FFB_Install::table_bookings();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, google_event_id FROM {$table} WHERE email = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['google_event_id'] ) ) {
				FFB_Google::delete_event( $row['google_event_id'] );
			}
		}

		$removed = (int) $wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) ); // phpcs:ignore WordPress.DB

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Textbaustein fuer die Datenschutzerklaerung.
	 */
	public static function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<h3>Terminbuchung</h3>';
		$content .= '<p>Wenn du ueber diese Website einen Termin buchst, speichere ich deinen Namen, deine E-Mail-Adresse, optional deine Telefonnummer, deine Antworten auf die Fragen im Buchungsformular sowie Datum und Uhrzeit des Termins. Diese Daten liegen in der Datenbank dieser Website.</p>';
		$content .= '<p>Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Durchfuehrung vorvertraglicher Massnahmen) sowie deine Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO.</p>';
		$content .= '<p>Der Termin wird zusaetzlich in meinen Google-Kalender eingetragen. Dabei werden dein Name, deine E-Mail-Adresse und deine Angaben aus dem Formular an Google uebermittelt. Anbieter ist Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland. Findet der Termin per Google Meet statt, wird ausserdem ein Videokonferenz-Link erzeugt.</p>';
		$content .= '<p>Buchungsdaten werden nach ' . (int) ffb_get_setting( 'retention_days', 730 ) . ' Tagen automatisch geloescht. Du kannst jederzeit Auskunft oder Loeschung verlangen.</p>';

		wp_add_privacy_policy_content( 'Fines Finest Booking', wp_kses_post( $content ) );
	}
}
