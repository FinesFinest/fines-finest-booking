<?php
/**
 * Backend: Buchungen, Termintypen, Einstellungen.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adminoberflaeche.
 */
class FFB_Admin {

	/**
	 * Wochentage.
	 *
	 * @var array
	 */
	protected static $weekdays = array(
		1 => 'Montag',
		2 => 'Dienstag',
		3 => 'Mittwoch',
		4 => 'Donnerstag',
		5 => 'Freitag',
		6 => 'Samstag',
		7 => 'Sonntag',
	);

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
	}

	/**
	 * Adminstyles laden.
	 *
	 * @param string $hook Aktuelle Seite.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'ffb-' ) && 'toplevel_page_ffb-bookings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ffb-admin', FFB_URL . 'assets/ffb-admin.css', array(), FFB_VERSION );
		wp_enqueue_script( 'ffb-admin', FFB_URL . 'assets/ffb-admin.js', array(), FFB_VERSION, true );
	}

	/**
	 * Menue aufbauen.
	 */
	public static function menu() {
		add_menu_page( 'Buchungen', 'Buchungen', 'manage_options', 'ffb-bookings', array( __CLASS__, 'page_bookings' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'ffb-bookings', 'Buchungen', 'Buchungen', 'manage_options', 'ffb-bookings', array( __CLASS__, 'page_bookings' ) );
		add_submenu_page( 'ffb-bookings', 'Termintypen', 'Termintypen', 'manage_options', 'ffb-event-types', array( __CLASS__, 'page_event_types' ) );
		add_submenu_page( 'ffb-bookings', 'Einstellungen', 'Einstellungen', 'manage_options', 'ffb-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Formulare und Links verarbeiten.
	 */
	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// OAuth-Rueckkehr von Google.
		if ( isset( $_GET['page'] ) && 'ffb-settings' === $_GET['page'] && ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::handle_google_return();
		}

		if ( isset( $_POST['ffb_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['ffb_action'] ) );
			check_admin_referer( 'ffb_' . $action );

			if ( 'save_settings' === $action ) {
				self::save_settings();
			} elseif ( 'save_event_type' === $action ) {
				self::save_event_type();
			}
		}

		if ( isset( $_GET['ffb_do'] ) ) {
			$do = sanitize_key( wp_unslash( $_GET['ffb_do'] ) );

			if ( 'disconnect' === $do ) {
				check_admin_referer( 'ffb_disconnect' );
				FFB_Google::disconnect();
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=disconnected' ) );
				exit;
			}

			if ( 'cancel_booking' === $do ) {
				check_admin_referer( 'ffb_cancel_booking' );
				FFB_Bookings::cancel( (int) ( $_GET['id'] ?? 0 ), true );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-bookings&ffb_notice=cancelled' ) );
				exit;
			}

			if ( 'delete_event_type' === $do ) {
				check_admin_referer( 'ffb_delete_event_type' );
				FFB_Event_Types::delete( (int) ( $_GET['id'] ?? 0 ) );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=deleted' ) );
				exit;
			}

			if ( 'test_mail' === $do ) {
				check_admin_referer( 'ffb_test_mail' );

				$an       = ffb_get_setting( 'notify_email', get_option( 'admin_email' ) );
				$gesendet = wp_mail(
					$an,
					'Testmail von Fines Finest Booking',
					'<p>Diese Mail kommt aus dem Buchungs-Plugin. Wenn du sie liest, funktioniert der Versand.</p><p>Pruef bitte auch, ob sie im Spam gelandet ist.</p>',
					array( 'Content-Type: text/html; charset=UTF-8', sprintf( 'From: %s <%s>', ffb_get_setting( 'from_name', get_bloginfo( 'name' ) ), ffb_from_email() ) )
				);

				if ( $gesendet ) {
					update_option( 'ffb_mail_tested', current_time( 'd.m.Y H:i' ), false );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=mail_ok' ) );
				} else {
					set_transient( 'ffb_admin_error', 'wp_mail() hat false zurueckgegeben. Pruef dein SMTP-Setup.', 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=mail_error' ) );
				}
				exit;
			}

			if ( 'test_brevo' === $do ) {
				check_admin_referer( 'ffb_test_brevo' );
				$result = FFB_Brevo::test();
				if ( is_wp_error( $result ) ) {
					set_transient( 'ffb_admin_error', $result->get_error_message(), 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=brevo_error' ) );
				} else {
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=brevo_ok' ) );
				}
				exit;
			}
		}
	}

	/**
	 * Verarbeitet die Rueckkehr von Googles Anmeldeseite.
	 *
	 * Jeder Ausgang endet mit einer Meldung. Vorher liefen ein abgelaufener State
	 * und ein Abbruch bei Google stumm auf die Einstellungsseite zurueck.
	 */
	protected static function handle_google_return() {
		// phpcs:disable WordPress.Security.NonceVerification -- Der OAuth-State uebernimmt hier die Rolle des Nonce.
		if ( isset( $_GET['error'] ) ) {
			$grund = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			self::google_return_error( 'Google hat die Anmeldung abgebrochen (' . $grund . '). Es wurde nichts geaendert. Klick noch einmal auf "Mit Google verbinden" und erlaube den Zugriff auf den Kalender.' );
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = (string) get_transient( 'ffb_oauth_state' );

		if ( '' === $state || '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::google_return_error( 'Die Anmeldung bei Google ist abgelaufen oder kam nicht von dieser Seite. Klick noch einmal auf "Mit Google verbinden".' );
		}

		delete_transient( 'ffb_oauth_state' );
		$result = FFB_Google::exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ) );
		// phpcs:enable

		if ( is_wp_error( $result ) ) {
			self::google_return_error( $result->get_error_message() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=google_ok' ) );
		exit;
	}

	/**
	 * Bricht die Google-Rueckkehr mit einer Meldung ab.
	 *
	 * @param string $message Meldung fuer den Hinweis.
	 */
	protected static function google_return_error( $message ) {
		set_transient( 'ffb_admin_error', $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=google_error' ) );
		exit;
	}

	/**
	 * Einstellungen speichern.
	 */
	protected static function save_settings() {
		$input    = isset( $_POST['ffb'] ) ? wp_unslash( $_POST['ffb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Geschaeftszeiten kommen als Von-Bis-Auswahl und werden hier zur gespeicherten Textform.
		foreach ( array_keys( self::$weekdays ) as $ffb_tag ) {
			$ffb_fenster              = self::windows_from_selects( $input, 'hours_' . $ffb_tag );
			$input[ 'hours_' . $ffb_tag ] = self::windows_to_string( $ffb_fenster );
		}

		// Vorlaufzeit: Zahl mal Einheit.
		if ( isset( $input['global_min_notice'] ) ) {
			$ffb_einheit               = max( 1, (int) ( $input['global_min_notice_unit'] ?? 1 ) );
			$input['global_min_notice'] = max( 0, (int) $input['global_min_notice'] ) * $ffb_einheit;
		}

		$current  = get_option( 'ffb_settings', array() );
		$defaults = ffb_default_settings();
		$clean    = is_array( $current ) ? $current : array();

		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $input[ $key ] ) ) {
				if ( in_array( $key, array( 'load_css', 'delete_on_uninstall', 'hours_enforce' ), true ) ) {
					$clean[ $key ] = 0;
				}
				if ( 'google_busy_calendars' === $key && isset( $input['google_busy_calendars_vorhanden'] ) ) {
					$clean[ $key ] = '';
				}
				continue;
			}

			$value = $input[ $key ];

			if ( in_array( $key, array( 'load_css', 'delete_on_uninstall', 'hours_enforce' ), true ) ) {
				$clean[ $key ] = $value ? 1 : 0;
			} elseif ( in_array( $key, array( 'retention_days', 'rate_limit', 'brevo_list_id', 'global_min_notice' ), true ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( 'google_busy_calendars' === $key ) {
				$ids = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
				$ids = array_filter( array_map( 'sanitize_text_field', (array) $ids ) );
				$clean[ $key ] = implode( ', ', array_unique( $ids ) );
			} elseif ( in_array( $key, array( 'notify_email', 'from_email' ), true ) ) {
				$clean[ $key ] = sanitize_email( $value );
			} elseif ( in_array( $key, array( 'privacy_url', 'booking_page_url' ), true ) ) {
				$clean[ $key ] = esc_url_raw( $value );
			} elseif ( 0 === strpos( $key, 'color_' ) ) {
				$clean[ $key ] = sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : $defaults[ $key ];
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		update_option( 'ffb_settings', $clean, false );

		wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=saved' ) );
		exit;
	}

	/**
	 * Termintyp speichern.
	 */
	protected static function save_event_type() {
		$input = isset( $_POST['ffb'] ) ? wp_unslash( $_POST['ffb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$id    = isset( $_POST['ffb_id'] ) ? (int) $_POST['ffb_id'] : 0;

		$availability = array();
		foreach ( array_keys( self::$weekdays ) as $day ) {
			$availability[ (string) $day ] = self::windows_from_selects( $input, 'day_' . $day );
		}

		// Vorlaufzeit: Zahl mal Einheit.
		if ( isset( $input['min_notice'] ) ) {
			$einheit             = max( 1, (int) ( $input['min_notice_unit'] ?? 1 ) );
			$input['min_notice'] = max( 0, (int) $input['min_notice'] ) * $einheit;
		}

		$questions = self::parse_questions( isset( $input['questions'] ) ? (string) $input['questions'] : '' );

		$data = array(
			'title'         => $input['title'] ?? '',
			'slug'          => $input['slug'] ?? '',
			'description'   => $input['description'] ?? '',
			'duration'      => $input['duration'] ?? 30,
			'slot_step'     => $input['slot_step'] ?? 30,
			'buffer_before' => $input['buffer_before'] ?? 0,
			'buffer_after'  => $input['buffer_after'] ?? 0,
			'min_notice'    => $input['min_notice'] ?? 720,
			'max_days'      => $input['max_days'] ?? 45,
			'daily_limit'   => $input['daily_limit'] ?? 0,
			'location'      => $input['location'] ?? 'meet',
			'location_note' => $input['location_note'] ?? '',
			'calendar_title' => $input['calendar_title'] ?? '',
			'availability'  => $availability,
			'blackouts'     => $input['blackouts'] ?? '',
			'questions'     => $questions,
			'active'        => ! empty( $input['active'] ),
			'sort_order'    => $input['sort_order'] ?? 0,
		);

		$result = FFB_Event_Types::save( $data, $id );

		if ( is_wp_error( $result ) ) {
			set_transient( 'ffb_admin_error', $result->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=error' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&edit=' . (int) $result . '&ffb_notice=saved' ) );
		exit;
	}

	/**
	 * Uhrzeiten fuer die Auswahlfelder, in Viertelstundenschritten.
	 *
	 * @return array
	 */
	public static function time_options() {
		$zeiten = array();
		for ( $minute = 0; $minute < 24 * 60; $minute += 15 ) {
			$zeiten[] = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );
		}

		return $zeiten;
	}

	/**
	 * Zwei Paare Von-Bis-Felder fuer einen Wochentag.
	 *
	 * @param string $prefix  Feldname-Praefix, etwa "hours_1" oder "day_1".
	 * @param array  $windows Bestehende Fenster.
	 */
	public static function render_day_selects( $prefix, $windows ) {
		$zeiten = self::time_options();
		$windows = array_values( (array) $windows );

		for ( $i = 0; $i < 2; $i++ ) {
			$von = isset( $windows[ $i ][0] ) ? $windows[ $i ][0] : '';
			$bis = isset( $windows[ $i ][1] ) ? $windows[ $i ][1] : '';
			?>
			<span style="display:inline-block;margin-right:1.2em;white-space:nowrap;">
				<select name="ffb[<?php echo esc_attr( $prefix . '_von_' . $i ); ?>]">
					<option value="">&mdash;</option>
					<?php foreach ( $zeiten as $z ) : ?>
						<option value="<?php echo esc_attr( $z ); ?>" <?php selected( $von, $z ); ?>><?php echo esc_html( $z ); ?></option>
					<?php endforeach; ?>
				</select>
				bis
				<select name="ffb[<?php echo esc_attr( $prefix . '_bis_' . $i ); ?>]">
					<option value="">&mdash;</option>
					<?php foreach ( $zeiten as $z ) : ?>
						<option value="<?php echo esc_attr( $z ); ?>" <?php selected( $bis, $z ); ?>><?php echo esc_html( $z ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>
			<?php
		}

		if ( count( $windows ) > 2 ) {
			echo '<span style="color:#996800;">Dieser Tag hatte mehr als zwei Zeitfenster. Beim naechsten Speichern bleiben nur die ersten beiden.</span>';
		}
	}

	/**
	 * Baut aus den Von-Bis-Feldern eines Tages die Fenster.
	 *
	 * Unvollstaendige oder verdrehte Paare werden verworfen. Dadurch kann kein
	 * Tippfehler mehr dazu fuehren, dass versehentlich rund um die Uhr buchbar ist.
	 *
	 * @param array  $input  Rohes Formular-Array.
	 * @param string $prefix Feldname-Praefix.
	 * @return array
	 */
	public static function windows_from_selects( $input, $prefix ) {
		$out = array();

		for ( $i = 0; $i < 2; $i++ ) {
			$von = FFB_Event_Types::clean_time( $input[ $prefix . '_von_' . $i ] ?? '' );
			$bis = FFB_Event_Types::clean_time( $input[ $prefix . '_bis_' . $i ] ?? '' );

			if ( '' === $von || '' === $bis || $von >= $bis ) {
				continue;
			}

			$out[] = array( $von, $bis );
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);

		return $out;
	}

	/**
	 * Zerlegt Minuten in Wert und Einheit fuer die Anzeige.
	 *
	 * @param int $minuten Minuten.
	 * @return array wert, einheit
	 */
	public static function split_duration( $minuten ) {
		$minuten = max( 0, (int) $minuten );

		if ( $minuten > 0 && 0 === $minuten % 1440 ) {
			return array( $minuten / 1440, 1440 );
		}
		if ( $minuten > 0 && 0 === $minuten % 60 ) {
			return array( $minuten / 60, 60 );
		}

		return array( $minuten, 1 );
	}

	/**
	 * Auswahlfeld fuer die Einheit einer Dauer.
	 *
	 * @param string $name    Feldname.
	 * @param int    $einheit Aktuelle Einheit in Minuten.
	 */
	public static function render_unit_select( $name, $einheit ) {
		$einheiten = array(
			1    => 'Minuten',
			60   => 'Stunden',
			1440 => 'Tage',
		);
		?>
		<select name="ffb[<?php echo esc_attr( $name ); ?>]">
			<?php foreach ( $einheiten as $wert => $label ) : ?>
				<option value="<?php echo (int) $wert; ?>" <?php selected( (int) $einheit, (int) $wert ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Wandelt "09:00-12:00, 14:00-17:00" in Fenster.
	 *
	 * @param string $raw Eingabe.
	 * @return array
	 */
	public static function parse_windows( $raw ) {
		$out = array();

		foreach ( preg_split( '/[,;]+/', (string) $raw ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}
			$parts = preg_split( '/\s*(?:-|bis|–)\s*/u', $chunk );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$start = FFB_Event_Types::clean_time( $parts[0] );
			$end   = FFB_Event_Types::clean_time( $parts[1] );
			if ( '' === $start || '' === $end ) {
				continue;
			}
			$out[] = array( $start, $end );
		}

		return $out;
	}

	/**
	 * Wandelt Fenster zurueck in eine Textzeile.
	 *
	 * @param array $windows Fenster.
	 * @return string
	 */
	public static function windows_to_string( $windows ) {
		$parts = array();
		foreach ( (array) $windows as $window ) {
			$parts[] = $window[0] . '-' . $window[1];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Wandelt Fragen-Zeilen in Struktur.
	 *
	 * @param string $raw Eingabe.
	 * @return array
	 */
	public static function parse_questions( $raw ) {
		$out   = array();
		$index = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$index++;
			$parts = array_map( 'trim', explode( '|', $line ) );

			$out[] = array(
				'key'      => 'frage_' . $index,
				'label'    => $parts[0],
				'type'     => isset( $parts[1] ) && $parts[1] ? $parts[1] : 'text',
				'required' => isset( $parts[2] ) && in_array( strtolower( $parts[2] ), array( 'pflicht', 'ja', '1', 'required' ), true ) ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * Wandelt Fragen zurueck in Text.
	 *
	 * @param array $questions Fragen.
	 * @return string
	 */
	public static function questions_to_string( $questions ) {
		$lines = array();
		foreach ( (array) $questions as $question ) {
			$lines[] = $question['label'] . ' | ' . $question['type'] . ( $question['required'] ? ' | pflicht' : '' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Hinweis direkt nach der Aktivierung.
	 */
	public static function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'ffb_just_activated' ) ) {
			return;
		}
		delete_transient( 'ffb_just_activated' );
		?>
		<div class="notice notice-info">
			<p>
				<strong>Fines Finest Booking ist aktiv.</strong>
				Es ist noch nichts buchbar &ndash; zuerst muss der Google-Kalender verbunden werden.
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-settings' ) ); ?>">Jetzt einrichten</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Aufklappbarer Hilfetext hinter einer Beschriftung.
	 *
	 * Bewusst zum Anklicken statt beim Darueberfahren: Hover gibt es auf
	 * Touchgeraeten nicht und Screenreader kommen damit schlecht zurecht.
	 *
	 * @param string $text  Hilfetext, einfaches HTML erlaubt.
	 * @param string $titel Kurzer Titel fuer die Schaltflaeche.
	 */
	public static function hint( $text, $titel = 'Erklaerung einblenden' ) {
		static $nr = 0;
		$nr++;
		$id = 'ffb-hint-' . $nr;
		?>
		<button type="button" class="ffb-hint-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>" title="<?php echo esc_attr( $titel ); ?>">?</button>
		<div class="ffb-hint" id="<?php echo esc_attr( $id ); ?>" hidden><?php echo wp_kses_post( $text ); ?></div>
		<?php
	}

	/**
	 * Sammelt den Einrichtungsstand.
	 *
	 * @return array
	 */
	public static function setup_state() {
		global $wpdb;

		$typen = FFB_Event_Types::all( true );

		$shortcode = get_transient( 'ffb_shortcode_found' );
		if ( false === $shortcode ) {
			$treffer   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[ff_booking%'" ); // phpcs:ignore WordPress.DB
			$shortcode = $treffer > 0 ? 'ja' : 'nein';
			set_transient( 'ffb_shortcode_found', $shortcode, 5 * MINUTE_IN_SECONDS );
		}

		$seite_bekannt = 'ja' === $shortcode || '' !== (string) ffb_get_setting( 'booking_page_url' );

		return array(
			array(
				'ok'      => FFB_Google::is_connected(),
				'titel'   => 'Google-Kalender verbunden',
				'gut'     => 'Verbunden als ' . FFB_Google::connected_account(),
				'schlecht' => 'Ohne Verbindung werden keine Termine eingetragen und freie Zeiten nicht geprueft.',
				'link'    => '#ffb-google',
				'aktion'  => 'Zur Google-Einrichtung',
			),
			array(
				'ok'      => ! empty( $typen ),
				'titel'   => 'Mindestens ein Termintyp aktiv',
				'gut'     => count( $typen ) . ' aktiv: ' . implode( ', ', wp_list_pluck( $typen, 'title' ) ),
				'schlecht' => 'Ohne aktiven Termintyp zeigt das Buchungsfeld nichts an.',
				'link'    => admin_url( 'admin.php?page=ffb-event-types' ),
				'aktion'  => 'Termintypen oeffnen',
			),
			array(
				'ok'      => $seite_bekannt,
				'titel'   => 'Buchungsseite eingerichtet',
				'gut'     => 'Shortcode gefunden oder Buchungsseite hinterlegt.',
				'schlecht' => 'Setz den Shortcode auf eine Seite und trag deren Adresse unten ein. Die Adresse wird fuer die Absage-Links in den Mails gebraucht.',
				'link'    => '#ffb-mails',
				'aktion'  => 'Buchungsseite eintragen',
			),
			array(
				'ok'      => (bool) get_option( 'ffb_mail_tested' ),
				'titel'   => 'Mailversand getestet',
				'gut'     => 'Zuletzt getestet am ' . esc_html( (string) get_option( 'ffb_mail_tested' ) ),
				'schlecht' => 'Ungetestet. Viele Hostings verschicken Mails unzuverlaessig &ndash; das faellt sonst erst auf, wenn jemand nicht zum Termin erscheint.',
				'link'    => '#ffb-mails',
				'aktion'  => 'Testmail senden',
			),
		);
	}

	/**
	 * Ampelkasten mit dem Einrichtungsstand.
	 */
	public static function render_status_box() {
		$punkte = self::setup_state();
		$offen  = count(
			array_filter(
				$punkte,
				static function ( $p ) {
					return ! $p['ok'];
				}
			)
		);
		?>
		<div class="ffb-status">
			<h2><?php echo $offen ? 'Einrichtung: noch ' . (int) $offen . ' offen' : 'Einrichtung vollstaendig'; ?></h2>
			<ul>
				<?php foreach ( $punkte as $punkt ) : ?>
					<li class="<?php echo $punkt['ok'] ? 'is-ok' : 'is-offen'; ?>">
						<span class="ffb-status__dot"></span>
						<strong><?php echo esc_html( $punkt['titel'] ); ?></strong>
						<span class="ffb-status__text"><?php echo wp_kses_post( $punkt['ok'] ? $punkt['gut'] : $punkt['schlecht'] ); ?></span>
						<?php if ( ! $punkt['ok'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $punkt['link'] ); ?>"><?php echo esc_html( $punkt['aktion'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php
			$zeitzone = wp_timezone_string();
			$utc_only = (bool) preg_match( '/^[+-]?\d{2}:\d{2}$|^UTC/i', $zeitzone );
			?>
			<p class="ffb-status__tz">
				Zeitzone dieser Website: <code><?php echo esc_html( $zeitzone ); ?></code>
				<?php if ( $utc_only ) : ?>
					<span style="color:#b32d2e;">Das ist ein fester Zeitversatz. Bei Sommerzeit verschieben sich dann alle Termine um eine Stunde.
					Stell unter Einstellungen, Allgemein eine Stadt ein, zum Beispiel Berlin.</span>
				<?php endif; ?>
			</p>

			<?php
			$fehlende = FFB_Google::is_connected() ? FFB_Google::unreachable_busy_calendars() : array();
			if ( $fehlende ) :
				?>
				<p class="ffb-status__warn">Diese Kalender sind eingetragen, aber fuer das verbundene Konto nicht sichtbar und blocken nichts: <code><?php echo esc_html( implode( ', ', $fehlende ) ); ?></code></p>
			<?php endif; ?>

			<?php if ( FFB_Brevo::is_configured() && ! (int) ffb_get_setting( 'brevo_list_id' ) ) : ?>
				<p class="ffb-status__warn">Brevo hat einen API-Key, aber keine Liste. So werden keine Kontakte angelegt.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Zeigt Hinweise oben auf der Seite.
	 */
	protected static function notices() {
		$notice = isset( $_GET['ffb_notice'] ) ? sanitize_key( wp_unslash( $_GET['ffb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'saved'        => array( 'success', 'Gespeichert.' ),
			'deleted'      => array( 'success', 'Termintyp geloescht.' ),
			'cancelled'    => array( 'success', 'Termin abgesagt.' ),
			'google_ok'    => array( 'success', 'Google-Kalender ist verbunden.' ),
			'disconnected' => array( 'success', 'Google-Verbindung getrennt.' ),
			'brevo_ok'     => array( 'success', 'Brevo antwortet. Der API-Key stimmt.' ),
			'mail_ok'      => array( 'success', 'Testmail ist raus. Schau in dein Postfach, auch in den Spam.' ),
			'mail_error'   => array( 'error', 'Die Testmail konnte nicht verschickt werden.' ),
			'google_error' => array( 'error', 'Die Google-Verbindung hat nicht geklappt.' ),
			'brevo_error'  => array( 'error', 'Brevo hat die Anfrage abgelehnt.' ),
			'error'        => array( 'error', 'Das hat nicht geklappt.' ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$detail = get_transient( 'ffb_admin_error' );
		delete_transient( 'ffb_admin_error' );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] ),
			$detail ? ' <code>' . esc_html( $detail ) . '</code>' : ''
		);
	}

	/**
	 * Seite: Buchungen.
	 */
	public static function page_bookings() {
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'upcoming'; // phpcs:ignore WordPress.Security.NonceVerification
		$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$data  = FFB_Bookings::query( $scope, 30, $page );
		?>
		<div class="wrap ffb-admin">
			<h1>Buchungen</h1>
			<?php self::notices(); ?>

			<ul class="subsubsub">
				<?php
				$tabs = array(
					'upcoming'  => 'Kommend',
					'past'      => 'Vergangen',
					'cancelled' => 'Abgesagt',
				);
				$last = array_key_last( $tabs );
				foreach ( $tabs as $key => $label ) :
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-bookings&scope=' . $key ) ); ?>" class="<?php echo $scope === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a>
						<?php echo $key === $last ? '' : ' |'; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:210px;">Termin</th>
						<th>Wer</th>
						<th>Termintyp</th>
						<th>Quelle</th>
						<th>Angaben</th>
						<th style="width:120px;">Aktion</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="6">Keine Eintraege.</td></tr>
				<?php endif; ?>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<?php $event = FFB_Event_Types::get( $row['event_type_id'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( FFB_Mailer::format_datetime( $row['start_utc'] ) ); ?></strong></td>
						<td>
							<?php echo esc_html( $row['name'] ); ?><br>
							<a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a>
							<?php if ( $row['phone'] ) : ?><br><?php echo esc_html( $row['phone'] ); ?><?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $event ? $event['title'] : '-' ); ?>
							<?php if ( $row['meet_url'] ) : ?>
								<br><a href="<?php echo esc_url( $row['meet_url'] ); ?>" target="_blank" rel="noopener">Meet-Link</a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $row['source'] ) && '' !== $row['source'] ? $row['source'] : '-' ); ?></td>
						<td>
							<?php foreach ( (array) json_decode( (string) $row['answers'], true ) as $label => $value ) : ?>
								<div><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( wp_trim_words( $value, 30 ) ); ?></div>
							<?php endforeach; ?>
						</td>
						<td>
							<?php if ( 'confirmed' === $row['status'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-bookings&ffb_do=cancel_booking&id=' . (int) $row['id'] ), 'ffb_cancel_booking' ) ); ?>" onclick="return confirm('Termin wirklich absagen? Die Person bekommt eine Mail.');">Absagen</a>
							<?php else : ?>
								<span class="ffb-badge">abgesagt</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $data['total'] > 30 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => admin_url( 'admin.php?page=ffb-bookings&scope=' . $scope . '&paged=%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => (int) ceil( $data['total'] / 30 ),
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Seite: Termintypen.
	 */
	public static function page_event_types() {
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = isset( $_GET['new'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $edit_id || $is_new ) {
			self::render_event_form( $edit_id );
			return;
		}
		?>
		<div class="wrap ffb-admin">
			<h1>Termintypen <a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&new=1' ) ); ?>" class="page-title-action">Neu</a></h1>
			<?php self::notices(); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Titel</th><th>Shortcode</th><th>Dauer</th><th>Status</th><th style="width:160px;">Aktion</th></tr></thead>
				<tbody>
				<?php foreach ( FFB_Event_Types::all() as $event ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&edit=' . $event['id'] ) ); ?>"><?php echo esc_html( $event['title'] ); ?></a></strong></td>
						<td><code>[ff_booking event="<?php echo esc_html( $event['slug'] ); ?>"]</code></td>
						<td><?php echo (int) $event['duration']; ?> Min.</td>
						<td><?php echo $event['active'] ? 'aktiv' : 'inaktiv'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&edit=' . $event['id'] ) ); ?>">Bearbeiten</a>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-event-types&ffb_do=delete_event_type&id=' . $event['id'] ), 'ffb_delete_event_type' ) ); ?>" onclick="return confirm('Termintyp loeschen? Bereits gebuchte Termine bleiben bestehen.');">Loeschen</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Formular fuer einen Termintyp.
	 *
	 * @param int $id ID.
	 */
	protected static function render_event_form( $id ) {
		$event = $id ? FFB_Event_Types::get( $id ) : null;

		$defaults = array(
			'title'         => '',
			'slug'          => '',
			'description'   => '',
			'duration'      => 30,
			'slot_step'     => 30,
			'buffer_before' => 0,
			'buffer_after'  => 15,
			'min_notice'    => 720,
			'max_days'      => 45,
			'daily_limit'   => 0,
			'location'      => 'meet',
			'location_note' => '',
			'calendar_title' => '',
			'availability'  => FFB_Event_Types::normalize_availability( array() ),
			'blackouts'     => array(),
			'questions'     => array(),
			'active'        => 1,
			'sort_order'    => 0,
		);

		$event = $event ? array_merge( $defaults, $event ) : $defaults;
		?>
		<div class="wrap ffb-admin">
			<h1><?php echo $id ? 'Termintyp bearbeiten' : 'Neuer Termintyp'; ?></h1>
			<?php self::notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types' ) ); ?>">
				<?php wp_nonce_field( 'ffb_save_event_type' ); ?>
				<input type="hidden" name="ffb_action" value="save_event_type">
				<input type="hidden" name="ffb_id" value="<?php echo (int) $id; ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-title">Titel</label></th>
						<td><input id="ffb-title" name="ffb[title]" type="text" class="regular-text" value="<?php echo esc_attr( $event['title'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-slug">Slug</label></th>
						<td>
							<input id="ffb-slug" name="ffb[slug]" type="text" class="regular-text" value="<?php echo esc_attr( $event['slug'] ); ?>">
							<p class="description">Wird im Shortcode benutzt. Leer lassen erzeugt ihn aus dem Titel.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-desc">Beschreibung</label></th>
						<td><textarea id="ffb-desc" name="ffb[description]" rows="4" class="large-text"><?php echo esc_textarea( $event['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row">Dauer und Raster</th>
						<td>
							<label>Dauer <input name="ffb[duration]" type="number" min="5" step="5" value="<?php echo (int) $event['duration']; ?>" class="small-text"> Min.</label>
							&nbsp;&nbsp;
							<label>Raster <input name="ffb[slot_step]" type="number" min="5" step="5" value="<?php echo (int) $event['slot_step']; ?>" class="small-text"> Min.</label>
							<p class="description">Das Raster bestimmt, in welchem Abstand Slots angeboten werden, etwa alle 30 Minuten.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Puffer</th>
						<td>
							<label>davor <input name="ffb[buffer_before]" type="number" min="0" step="5" value="<?php echo (int) $event['buffer_before']; ?>" class="small-text"> Min.</label>
							&nbsp;&nbsp;
							<label>danach <input name="ffb[buffer_after]" type="number" min="0" step="5" value="<?php echo (int) $event['buffer_after']; ?>" class="small-text"> Min.</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Zeitfenster</th>
						<td>
							<?php foreach ( self::$weekdays as $num => $label ) : ?>
								<p style="margin:0 0 .5em;">
									<label style="display:inline-block;width:110px;vertical-align:middle;"><?php echo esc_html( $label ); ?></label>
									<?php self::render_day_selects( 'day_' . $num, $event['availability'][ (string) $num ] ?? array() ); ?>
								</p>
							<?php endforeach; ?>
							<p class="description">Zwei Fenster pro Tag moeglich. Beide Felder auf &bdquo;&mdash;&ldquo; heisst: an diesem Tag nicht buchbar.</p>
							<?php if ( (int) ffb_get_setting( 'hours_enforce' ) ) : ?>
								<p class="description" style="color:#996800;">Achtung: In den Einstellungen sind Geschaeftszeiten erzwungen. Was ausserhalb davon liegt, wird hier zwar gespeichert, aber nicht angeboten.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Grenzen</th>
						<td>
							<?php list( $ffb_nwert, $ffb_neinheit ) = self::split_duration( $event['min_notice'] ); ?>
							<label>Vorlaufzeit <input name="ffb[min_notice]" type="number" min="0" value="<?php echo (int) $ffb_nwert; ?>" class="small-text"></label>
							<?php self::render_unit_select( 'min_notice_unit', $ffb_neinheit ); ?><br>
							<label>Buchbar bis <input name="ffb[max_days]" type="number" min="1" value="<?php echo (int) $event['max_days']; ?>" class="small-text"> Tage im Voraus</label><br>
							<label>Maximal <input name="ffb[daily_limit]" type="number" min="0" value="<?php echo (int) $event['daily_limit']; ?>" class="small-text"> Termine pro Tag (0 = kein Limit)</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-caltitle">Titel im Kalender</label></th>
						<td>
							<input id="ffb-caltitle" name="ffb[calendar_title]" type="text" class="large-text" value="<?php echo esc_attr( $event['calendar_title'] ); ?>" placeholder="{termintyp} mit {name}">
							<p class="description">So heisst der Eintrag in deinem Google-Kalender. Platzhalter: <code>{name}</code>, <code>{termintyp}</code>, <code>{quelle}</code>, <code>{datum}</code>, <code>{uhrzeit}</code>, <code>{email}</code>. Leer lassen ergibt &bdquo;{termintyp} mit {name}&ldquo;.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-blackouts">Sperrtage</label></th>
						<td>
							<input id="ffb-blackouts" name="ffb[blackouts]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', (array) $event['blackouts'] ) ); ?>" placeholder="2026-12-24, 2026-12-31">
							<p class="description">Einzelne Tage im Format JJJJ-MM-TT, mit Komma getrennt. Urlaub traegst du besser direkt im Google-Kalender ein, das wird automatisch beruecksichtigt.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Ort</th>
						<td>
							<select name="ffb[location]">
								<?php
								$locations = array(
									'meet'     => 'Google Meet (Link wird automatisch erzeugt)',
									'phone'    => 'Telefon (ich rufe an)',
									'inperson' => 'Vor Ort',
									'custom'   => 'Eigener Hinweis',
								);
								foreach ( $locations as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $event['location'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p><input name="ffb[location_note]" type="text" class="regular-text" value="<?php echo esc_attr( $event['location_note'] ); ?>" placeholder="Adresse oder Hinweis"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-questions">Zusatzfragen</label></th>
						<td>
							<textarea id="ffb-questions" name="ffb[questions]" rows="5" class="large-text" placeholder="Deine Website | text&#10;Worum geht es? | textarea | pflicht"><?php echo esc_textarea( self::questions_to_string( $event['questions'] ) ); ?></textarea>
							<p class="description">Eine Frage pro Zeile: <code>Label | typ | pflicht</code>. Typen: text, textarea, tel, url. Name und E-Mail werden immer abgefragt.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Status</th>
						<td>
							<label><input type="checkbox" name="ffb[active]" value="1" <?php checked( $event['active'], 1 ); ?>> Buchbar</label>
							&nbsp;&nbsp;
							<label>Reihenfolge <input name="ffb[sort_order]" type="number" value="<?php echo (int) $event['sort_order']; ?>" class="small-text"></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( 'Speichern', 'primary', 'submit', false ); ?>
					<a class="button button-secondary" style="margin-left:8px;" href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types' ) ); ?>">Abbrechen</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Seite: Einstellungen.
	 */
	public static function page_settings() {
		$connected = FFB_Google::is_connected();
		$auth_url  = FFB_Google::auth_url();
		$log       = get_option( 'ffb_google_log', array() );
		?>
		<div class="wrap ffb-admin">
			<h1>Einstellungen</h1>
			<?php self::notices(); ?>
			<?php self::render_status_box(); ?>

			<h2 id="ffb-google">Google-Kalender
				<?php
				self::hint(
					'<p><strong>Warum das noetig ist:</strong> Das Plugin spricht mit deinem Google-Kalender ueber eine Schnittstelle. Damit Google weiss, dass ausgerechnet deine Website fragen darf, brauchst du einmalig ein kostenloses Projekt in der Google-Cloud-Konsole. Das dauert etwa fuenfzehn Minuten und ist danach erledigt.</p>
					<ol>
						<li>Auf <a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a> mit dem Google-Konto anmelden, in dessen Kalender die Termine landen sollen.</li>
						<li>Oben links ein neues Projekt anlegen, Name frei waehlbar.</li>
						<li>Links unter <em>APIs und Dienste, Bibliothek</em> nach <em>Google Calendar API</em> suchen und auf <em>Aktivieren</em> klicken.</li>
						<li>Links unter <em>Google Auth Platform</em> die Konfiguration starten: App-Name, Support-Mail, Nutzertyp <em>Extern</em>, Kontaktadresse.</li>
						<li>Unter <em>Clients</em> einen neuen Client vom Typ <em>Webanwendung</em> anlegen. Bei den autorisierten Weiterleitungs-URIs genau die Adresse eintragen, die unten steht.</li>
						<li>Client-ID und Clientschluessel unten eintragen und speichern. Danach erscheint der Verbinden-Knopf.</li>
						<li>Unter <em>Zielgruppe</em> auf <em>App veroeffentlichen</em> klicken. Sonst laeuft die Verbindung nach sieben Tagen ab.</li>
					</ol>
					<p>Beim Verbinden zeigt Google einmal die Warnung, die App sei nicht ueberprueft. Das ist bei einer App normal, die nur du selbst nutzt: auf <em>Erweitert</em> klicken und fortfahren.</p>',
					'Anleitung fuer das Google-Projekt'
				);
				?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Status</th>
					<td>
						<?php if ( $connected ) : ?>
							<p><span class="ffb-dot ffb-dot--on"></span> Verbunden<?php echo FFB_Google::connected_account() ? ' als <code>' . esc_html( FFB_Google::connected_account() ) . '</code>' : ''; ?></p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=disconnect' ), 'ffb_disconnect' ) ); ?>">Verbindung trennen</a>
						<?php elseif ( $auth_url ) : ?>
							<p><span class="ffb-dot"></span> Nicht verbunden</p>
							<a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>">Mit Google verbinden</a>
						<?php else : ?>
							<p><span class="ffb-dot"></span> Trag zuerst Client-ID und Client-Secret ein und speichere.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Weiterleitungs-URI</th>
					<td>
						<code id="ffb-redirect"><?php echo esc_html( FFB_Google::redirect_uri() ); ?></code>
						<button type="button" class="button button-small ffb-copy" data-ffb-copy="#ffb-redirect">Kopieren</button>
						<p class="description">Genau diese Adresse muss in der Google-Cloud-Konsole als autorisierter Redirect-URI eingetragen sein. Ein fehlendes <code>www</code> reicht, damit es nicht funktioniert.</p>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ffb-settings' ) ); ?>">
				<?php wp_nonce_field( 'ffb_save_settings' ); ?>
				<input type="hidden" name="ffb_action" value="save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-cid">Client-ID</label></th>
						<td><input id="ffb-cid" name="ffb[google_client_id]" type="text" class="large-text" value="<?php echo esc_attr( ffb_get_setting( 'google_client_id' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-cs">Client-Secret</label></th>
						<td><input id="ffb-cs" name="ffb[google_client_secret]" type="password" class="large-text" autocomplete="new-password" value="<?php echo esc_attr( ffb_get_setting( 'google_client_secret' ) ); ?>"></td>
					</tr>
					<?php
					$ffb_kalender  = $connected ? FFB_Google::get_calendars() : null;
					$ffb_ziel      = (string) ffb_get_setting( 'google_calendar_id', 'primary' );
					$ffb_busy_ids  = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) ffb_get_setting( 'google_busy_calendars' ) ) ) );
					?>
					<tr>
						<th scope="row"><label for="ffb-calid">Zielkalender</label></th>
						<td>
							<?php if ( is_array( $ffb_kalender ) && $ffb_kalender ) : ?>
								<select id="ffb-calid" name="ffb[google_calendar_id]">
									<option value="primary" <?php selected( $ffb_ziel, 'primary' ); ?>>Hauptkalender</option>
									<?php
									foreach ( $ffb_kalender as $ffb_id => $ffb_cal ) :
										if ( ! $ffb_cal['schreiben'] || $ffb_cal['primaer'] ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $ffb_id ); ?>" <?php selected( $ffb_ziel, $ffb_id ); ?>><?php echo esc_html( $ffb_cal['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Hier landen die gebuchten Termine. Es werden nur Kalender angeboten, in die du schreiben darfst.</p>
							<?php else : ?>
								<input id="ffb-calid" name="ffb[google_calendar_id]" type="text" class="regular-text" value="<?php echo esc_attr( $ffb_ziel ); ?>">
								<p class="description"><code>primary</code> ist dein Hauptkalender. Nach dem Verbinden kannst du hier auswaehlen.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Weitere Kalender pruefen</th>
						<td>
							<input type="hidden" name="ffb[google_busy_calendars_vorhanden]" value="1">
							<?php if ( is_array( $ffb_kalender ) && $ffb_kalender ) : ?>
								<?php foreach ( $ffb_kalender as $ffb_id => $ffb_cal ) : ?>
									<?php if ( $ffb_cal['primaer'] ) { continue; } ?>
									<p style="margin:0 0 .4em;">
										<label>
											<input type="checkbox" name="ffb[google_busy_calendars][]" value="<?php echo esc_attr( $ffb_id ); ?>" <?php checked( in_array( (string) $ffb_id, $ffb_busy_ids, true ) ); ?>>
											<?php echo esc_html( $ffb_cal['name'] ); ?>
											<span style="color:#787c82;">&nbsp;<?php echo esc_html( $ffb_id ); ?></span>
										</label>
									</p>
								<?php endforeach; ?>
								<p class="description">Diese Kalender blocken zusaetzlich Slots, es wird aber nichts hineingeschrieben. Der Hauptkalender wird immer geprueft.</p>
								<?php
								$ffb_fehlend = array_diff( $ffb_busy_ids, array_map( 'strval', array_keys( $ffb_kalender ) ) );
								if ( $ffb_fehlend ) :
									?>
									<p class="description" style="color:#b32d2e;">
										Eingetragen, aber fuer dieses Google-Konto nicht sichtbar: <code><?php echo esc_html( implode( ', ', $ffb_fehlend ) ); ?></code><br>
										Diese Kalender blocken <strong>nichts</strong>. Damit sie greifen, musst du sie im anderen Google-Konto fuer
										<code><?php echo esc_html( FFB_Google::connected_account() ); ?></code> freigeben, mindestens mit &bdquo;Nur Verfuegbarkeit&ldquo;.
										Danach erscheinen sie hier in der Liste.
									</p>
								<?php endif; ?>
							<?php elseif ( is_wp_error( $ffb_kalender ) ) : ?>
								<input name="ffb[google_busy_calendars]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', $ffb_busy_ids ) ); ?>">
								<p class="description" style="color:#b32d2e;">Die Kalenderliste liess sich nicht laden: <code><?php echo esc_html( $ffb_kalender->get_error_message() ); ?></code></p>
							<?php else : ?>
								<input name="ffb[google_busy_calendars]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', $ffb_busy_ids ) ); ?>">
								<p class="description">Nach dem Verbinden kannst du die Kalender hier ankreuzen.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2>Grenzen fuer alle Termintypen</h2>
				<p class="description" style="max-width:46em;">Diese Werte gelten zusaetzlich zu den Einstellungen der einzelnen Termintypen und koennen nur weiter einschraenken, nie erweitern. Ein Termintyp kann sie also nicht aushebeln.</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Geschaeftszeiten</th>
						<td>
							<label><input type="checkbox" name="ffb[hours_enforce]" value="1" <?php checked( (int) ffb_get_setting( 'hours_enforce' ), 1 ); ?>> Geschaeftszeiten erzwingen</label>
							<p class="description">Ist der Haken aus, gelten nur die Zeiten der einzelnen Termintypen.</p>
							<div style="margin-top:1em;">
							<?php foreach ( self::$weekdays as $ffb_num => $ffb_label ) : ?>
								<p style="margin:0 0 .5em;">
									<label style="display:inline-block;width:110px;vertical-align:middle;"><?php echo esc_html( $ffb_label ); ?></label>
									<?php self::render_day_selects( 'hours_' . $ffb_num, self::parse_windows( ffb_get_setting( 'hours_' . $ffb_num, '' ) ) ); ?>
								</p>
							<?php endforeach; ?>
							</div>
							<p class="description">Zwei Fenster pro Tag moeglich, zum Beispiel vormittags und nachmittags. <strong>Beide Felder auf &bdquo;&mdash;&ldquo; sperrt den Wochentag komplett</strong>, sobald der Haken oben gesetzt ist. Unvollstaendige oder verdrehte Paare werden verworfen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-globalnotice">Mindest-Vorlaufzeit</label></th>
						<td>
							<?php list( $ffb_wert, $ffb_einheit ) = self::split_duration( ffb_get_setting( 'global_min_notice' ) ); ?>
							<input id="ffb-globalnotice" name="ffb[global_min_notice]" type="number" min="0" class="small-text" value="<?php echo (int) $ffb_wert; ?>">
							<?php self::render_unit_select( 'global_min_notice_unit', $ffb_einheit ); ?>
							<p class="description">Untergrenze fuer alle Termintypen. Hat ein Termintyp eine laengere Vorlaufzeit, gilt die laengere. 0 heisst: keine zusaetzliche Grenze. Beispiel: 2 Tage verhindert Buchungen fuer die naechsten 48 Stunden.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-globalblackouts">Sperrtage</label></th>
						<td>
							<input id="ffb-globalblackouts" name="ffb[global_blackouts]" type="text" class="large-text" value="<?php echo esc_attr( ffb_get_setting( 'global_blackouts', '' ) ); ?>" placeholder="2026-12-24, 2026-12-25, 2026-12-31">
							<p class="description">Gilt fuer alle Termintypen, Format JJJJ-MM-TT. Fuer Urlaub reicht auch ein ganztaegiger Eintrag im Google-Kalender, der blockt automatisch.</p>
						</td>
					</tr>
				</table>

				<h2 id="ffb-mails">Mails
					<?php
					self::hint(
						'<p>WordPress verschickt Mails ueber die Funktion <code>wp_mail()</code>. Viele Hostings liefern die unzuverlaessig aus, weil der Webserver nicht als Absender deiner Domain beglaubigt ist. Die Folge: Bestaetigungen landen im Spam oder gar nicht.</p>
						<p>Die Loesung ist ein SMTP-Plugin, das den Versand ueber einen Maildienst leitet, zum Beispiel Brevo. Nutz den Testknopf unten, bevor du das Buchungsfeld oeffentlich machst.</p>',
						'Warum Mails oft nicht ankommen'
					);
					?>
				</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-notify">Benachrichtigung an</label></th>
						<td>
							<input id="ffb-notify" name="ffb[notify_email]" type="email" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'notify_email' ) ); ?>">
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=test_mail' ), 'ffb_test_mail' ) ); ?>">Testmail senden</a>
							<p class="description">Speichere erst, falls du die Adresse gerade geaendert hast. Die Testmail geht an die gespeicherte Adresse.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fromname">Absendername</label></th>
						<td><input id="ffb-fromname" name="ffb[from_name]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'from_name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fromemail">Absenderadresse</label></th>
						<td>
							<input id="ffb-fromemail" name="ffb[from_email]" type="email" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'from_email' ) ); ?>" placeholder="<?php echo esc_attr( ffb_from_email() ); ?>">
							<p class="description">Muss zur Domain passen, sonst landen die Mails im Spam. Mit Brevo als SMTP-Relay bist du auf der sicheren Seite.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-page">Buchungsseite</label></th>
						<td>
							<input id="ffb-page" name="ffb[booking_page_url]" type="url" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'booking_page_url' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/termin/' ) ); ?>">
							<p class="description">Die Seite mit dem Shortcode. Wird fuer die Absage-Links in den Mails gebraucht.</p>
						</td>
					</tr>
				</table>

				<h2>Brevo</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-brevo">API-Key</label></th>
						<td>
							<input id="ffb-brevo" name="ffb[brevo_api_key]" type="password" class="large-text" autocomplete="new-password" value="<?php echo esc_attr( ffb_get_setting( 'brevo_api_key' ) ); ?>">
							<p class="description">Brevo, dann Einstellungen, dann SMTP &amp; API, dann API-Keys. Leer lassen schaltet die Anbindung ab.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-brevo-list">Liste</label></th>
						<td>
							<?php
							$ffb_gewaehlt = (int) ffb_get_setting( 'brevo_list_id' );
							$ffb_listen   = FFB_Brevo::is_configured() ? FFB_Brevo::get_lists() : null;

							if ( is_array( $ffb_listen ) && $ffb_listen ) :
								?>
								<select id="ffb-brevo-list" name="ffb[brevo_list_id]">
									<option value="0">&mdash; bitte waehlen &mdash;</option>
									<?php foreach ( $ffb_listen as $ffb_id => $ffb_name ) : ?>
										<option value="<?php echo (int) $ffb_id; ?>" <?php selected( $ffb_gewaehlt, (int) $ffb_id ); ?>>
											<?php echo esc_html( $ffb_name . ' (ID ' . $ffb_id . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=test_brevo' ), 'ffb_test_brevo' ) ); ?>">Verbindung testen</a>
								<?php if ( ! $ffb_gewaehlt ) : ?>
									<p class="description" style="color:#b32d2e;">Ohne gewaehlte Liste legt das Plugin keine Kontakte in Brevo an.</p>
								<?php endif; ?>
							<?php elseif ( is_wp_error( $ffb_listen ) ) : ?>
								<input id="ffb-brevo-list" name="ffb[brevo_list_id]" type="number" class="small-text" value="<?php echo esc_attr( $ffb_gewaehlt ); ?>">
								<p class="description" style="color:#b32d2e;">Die Listen liessen sich nicht laden: <code><?php echo esc_html( $ffb_listen->get_error_message() ); ?></code> Pruefe, ob der API-Key das Recht &bdquo;Kontakte&ldquo; hat. So lange kannst du die ID von Hand eintragen.</p>
							<?php else : ?>
								<input id="ffb-brevo-list" name="ffb[brevo_list_id]" type="number" class="small-text" value="<?php echo esc_attr( $ffb_gewaehlt ); ?>">
								<p class="description">Trag oben den API-Key ein und speichere. Danach kannst du die Liste hier direkt auswaehlen.</p>
							<?php endif; ?>
							<p class="description">Kontakte landen in dieser Liste. Achtung: Eine Terminbuchung ist keine Newsletter-Einwilligung. Nutz eine eigene Liste dafuer.</p>
						</td>
					</tr>
				</table>

				<h2>Datenschutz</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-consent">Einwilligungstext</label></th>
						<td><textarea id="ffb-consent" name="ffb[consent_text]" rows="3" class="large-text"><?php echo esc_textarea( ffb_get_setting( 'consent_text' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-privacy">Link zur Datenschutzerklaerung</label></th>
						<td><input id="ffb-privacy" name="ffb[privacy_url]" type="url" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'privacy_url' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-retention">Aufbewahrung</label></th>
						<td>
							<input id="ffb-retention" name="ffb[retention_days]" type="number" min="0" class="small-text" value="<?php echo (int) ffb_get_setting( 'retention_days' ); ?>"> Tage
							<p class="description">Buchungen, die aelter sind, werden automatisch geloescht. 0 schaltet das Loeschen ab.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-rate">Buchungen pro Stunde und IP</label></th>
						<td><input id="ffb-rate" name="ffb[rate_limit]" type="number" min="0" class="small-text" value="<?php echo (int) ffb_get_setting( 'rate_limit' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Beim Deinstallieren</th>
						<td><label><input type="checkbox" name="ffb[delete_on_uninstall]" value="1" <?php checked( (int) ffb_get_setting( 'delete_on_uninstall' ), 1 ); ?>> Tabellen und Einstellungen mitloeschen</label></td>
					</tr>
				</table>

				<h2>Design</h2>
				<table class="form-table" role="presentation">
					<?php
					$colors = array(
						'color_accent'      => 'Akzent',
						'color_accent_soft' => 'Akzent hell',
						'color_secondary'   => 'Zweitfarbe',
						'color_success'     => 'Erfolg',
						'color_bg'          => 'Hintergrund',
						'color_bg_deep'     => 'Hintergrund dunkel',
						'color_surface'     => 'Flaeche',
						'color_text'        => 'Text',
						'color_muted'       => 'Text gedaempft',
					);
					foreach ( $colors as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ffb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input id="ffb-<?php echo esc_attr( $key ); ?>" name="ffb[<?php echo esc_attr( $key ); ?>]" type="text" class="small-text" value="<?php echo esc_attr( ffb_get_setting( $key ) ); ?>"> <input type="color" value="<?php echo esc_attr( ffb_get_setting( $key ) ); ?>" oninput="document.getElementById('ffb-<?php echo esc_attr( $key ); ?>').value=this.value;"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="ffb-fd">Font Display</label></th>
						<td><input id="ffb-fd" name="ffb[font_display]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'font_display' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fb">Font Body</label></th>
						<td><input id="ffb-fb" name="ffb[font_body]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'font_body' ) ); ?>"></td>
					</tr>
				</table>

				<?php submit_button( 'Speichern' ); ?>
			</form>

			<?php if ( ! empty( $log ) ) : ?>
				<h2>Letzte Fehler</h2>
				<table class="wp-list-table widefat striped">
					<thead><tr><th style="width:170px;">Zeit</th><th>Meldung</th></tr></thead>
					<tbody>
					<?php foreach ( (array) $log as $entry ) : ?>
						<tr><td><?php echo esc_html( $entry['time'] ); ?></td><td><code><?php echo esc_html( $entry['text'] ); ?></code></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
