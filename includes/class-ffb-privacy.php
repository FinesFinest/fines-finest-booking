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
		$exporters['fines-finest-booking'] = array(
			'exporter_friendly_name' => __( 'Appointment bookings', 'fines-finest-booking' ),
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
		$erasers['fines-finest-booking'] = array(
			'eraser_friendly_name' => __( 'Appointment bookings', 'fines-finest-booking' ),
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

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ffb_bookings WHERE email = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- eigene Tabelle.

		$items = array();

		foreach ( (array) $rows as $row ) {
			$event = FFB_Event_Types::get( $row['event_type_id'] );

			$data = array(
				array(
					'name'  => __( 'Appointment', 'fines-finest-booking' ),
					'value' => $event ? $event['title'] : '-',
				),
				array(
					'name'  => __( 'Date and time', 'fines-finest-booking' ),
					'value' => FFB_Mailer::format_datetime( $row['start_utc'] ),
				),
				array(
					'name'  => __( 'Name', 'fines-finest-booking' ),
					'value' => $row['name'],
				),
				array(
					'name'  => __( 'Email', 'fines-finest-booking' ),
					'value' => $row['email'],
				),
				array(
					'name'  => __( 'Phone', 'fines-finest-booking' ),
					'value' => $row['phone'],
				),
				array(
					'name'  => __( 'Status', 'fines-finest-booking' ),
					'value' => $row['status'],
				),
				array(
					'name'  => __( 'Consent given on', 'fines-finest-booking' ),
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
				'group_label' => __( 'Appointment bookings', 'fines-finest-booking' ),
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
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, google_event_id FROM {$wpdb->prefix}ffb_bookings WHERE email = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- eigene Tabelle.

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

		$days = (int) ffb_get_setting( 'retention_days', 730 );

		$content  = '<h3>' . esc_html__( 'Appointment booking', 'fines-finest-booking' ) . '</h3>';
		$content .= '<p>' . esc_html__( 'When you book an appointment through this website, I store your name, your email address, optionally your phone number, your answers to the questions in the booking form, and the date and time of the appointment. This data is kept in the database of this website.', 'fines-finest-booking' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The legal basis is Art. 6 (1) (b) GDPR (steps prior to entering into a contract) and your consent under Art. 6 (1) (a) GDPR.', 'fines-finest-booking' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The appointment is also added to my Google Calendar. Your name, your email address and your answers from the form are transmitted to Google for this. The provider is Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Ireland. If the appointment takes place via Google Meet, a video call link is created as well.', 'fines-finest-booking' ) . '</p>';
		$content .= '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of days */
				_n( 'Booking data is deleted automatically after %d day. You can request access to or deletion of your data at any time.', 'Booking data is deleted automatically after %d days. You can request access to or deletion of your data at any time.', $days, 'fines-finest-booking' ),
				$days
			)
		) . '</p>';

		wp_add_privacy_policy_content( 'Fines Finest Booking', wp_kses_post( $content ) );
	}
}
