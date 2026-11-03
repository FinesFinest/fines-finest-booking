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
	 * Wochentage 1 (Montag) bis 7 (Sonntag) in der Sprache der Website.
	 *
	 * @return array
	 */
	public static function weekdays() {
		global $wp_locale;

		$out = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			// WordPress zaehlt 0 = Sonntag bis 6 = Samstag.
			$out[ $day ] = $wp_locale->get_weekday( $day % 7 );
		}

		return $out;
	}

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FFB_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/**
	 * Links "Einstellungen" und "Termintypen" in der Pluginliste.
	 *
	 * @param array $links Bisherige Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=ffb-settings' ) ) . '">' . esc_html__( 'Settings', 'fines-finest-booking' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=ffb-event-types' ) ) . '">' . esc_html__( 'Appointment types', 'fines-finest-booking' ) . '</a>'
		);

		return $links;
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
		wp_localize_script(
			'ffb-admin',
			'FFB_ADMIN',
			array(
				'copied' => __( 'Copied', 'fines-finest-booking' ),
			)
		);
	}

	/**
	 * Menue aufbauen.
	 */
	public static function menu() {
		add_menu_page( __( 'Bookings', 'fines-finest-booking' ), __( 'Bookings', 'fines-finest-booking' ), 'manage_options', 'ffb-bookings', array( __CLASS__, 'page_bookings' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'ffb-bookings', __( 'Bookings', 'fines-finest-booking' ), __( 'Bookings', 'fines-finest-booking' ), 'manage_options', 'ffb-bookings', array( __CLASS__, 'page_bookings' ) );
		add_submenu_page( 'ffb-bookings', __( 'Appointment types', 'fines-finest-booking' ), __( 'Appointment types', 'fines-finest-booking' ), 'manage_options', 'ffb-event-types', array( __CLASS__, 'page_event_types' ) );
		add_submenu_page( 'ffb-bookings', __( 'Settings', 'fines-finest-booking' ), __( 'Settings', 'fines-finest-booking' ), 'manage_options', 'ffb-settings', array( __CLASS__, 'page_settings' ) );
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
				FFB_Bookings::cancel( isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0, true );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-bookings&ffb_notice=cancelled' ) );
				exit;
			}

			if ( 'delete_event_type' === $do ) {
				check_admin_referer( 'ffb_delete_event_type' );
				$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				$offen = FFB_Bookings::count_upcoming( $id );

				// Mit kuenftigen Terminen nicht loeschen: Die Buchungen verloeren sonst still ihre Erinnerung.
				if ( $offen > 0 ) {
					$meldung = sprintf(
						/* translators: %d: number of upcoming appointments */
						_n( 'This appointment type still has %d upcoming appointment.', 'This appointment type still has %d upcoming appointments.', $offen, 'fines-finest-booking' ),
						$offen
					);
					set_transient( 'ffb_admin_error', $meldung . ' ' . __( 'Cancel those appointments first, or untick "Bookable" on the appointment type until they are over.', 'fines-finest-booking' ), 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=not_deleted' ) );
					exit;
				}

				FFB_Event_Types::delete( $id );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=deleted' ) );
				exit;
			}

			if ( 'create_page' === $do ) {
				check_admin_referer( 'ffb_create_page' );
				$title   = __( 'Book an appointment', 'fines-finest-booking' );
				$page_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $title,
						'post_name'    => sanitize_title( $title ),
						'post_content' => '<!-- wp:shortcode -->[ff_booking]<!-- /wp:shortcode -->',
						'post_author'  => get_current_user_id(),
					),
					true
				);

				if ( is_wp_error( $page_id ) ) {
					set_transient( 'ffb_admin_error', $page_id->get_error_message(), 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=error' ) );
					exit;
				}

				// Adresse merken, damit Absage-Links auf diese Seite fuehren.
				$settings                     = (array) get_option( 'ffb_settings', array() );
				$settings['booking_page_url'] = get_permalink( $page_id );
				update_option( 'ffb_settings', $settings );
				delete_transient( 'ffb_shortcode_found' );

				set_transient( 'ffb_admin_error', get_permalink( $page_id ), 60 );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=page_created' ) );
				exit;
			}

			if ( 'resend_confirmation' === $do ) {
				check_admin_referer( 'ffb_resend_confirmation' );
				$booking = FFB_Bookings::get( isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0 );
				$event   = $booking ? FFB_Event_Types::get( $booking['event_type_id'] ) : null;

				if ( ! $booking || ! $event || 'confirmed' !== $booking['status'] ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-bookings&ffb_notice=error' ) );
					exit;
				}

				// Die Mail erwartet die Antworten als Feld, nicht als JSON-Text aus der Datenbank.
				if ( is_string( $booking['answers'] ) ) {
					$booking['answers'] = (array) json_decode( $booking['answers'], true );
				}

				$gesendet = FFB_Mailer::send_confirmation( $booking, $event );
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-bookings&ffb_notice=' . ( $gesendet ? 'resent' : 'resend_error' ) ) );
				exit;
			}

			if ( 'duplicate_event_type' === $do ) {
				check_admin_referer( 'ffb_duplicate_event_type' );
				$quelle = FFB_Event_Types::get( isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0 );

				if ( ! $quelle ) {
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=error' ) );
					exit;
				}

				$daten = $quelle;
				unset( $daten['id'], $daten['created_at'] );
				/* translators: %s: title of the copied appointment type */
				$daten['title']  = sprintf( __( '%s (copy)', 'fines-finest-booking' ), $quelle['title'] );
				$daten['slug']   = '';
				$daten['active'] = 0; // Erst pruefen, dann freischalten.

				$neu = FFB_Event_Types::save( $daten );
				if ( is_wp_error( $neu ) ) {
					set_transient( 'ffb_admin_error', $neu->get_error_message(), 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&ffb_notice=error' ) );
					exit;
				}

				wp_safe_redirect( admin_url( 'admin.php?page=ffb-event-types&edit=' . (int) $neu . '&ffb_notice=duplicated' ) );
				exit;
			}

			if ( 'test_mail' === $do ) {
				check_admin_referer( 'ffb_test_mail' );

				$an       = ffb_get_setting( 'notify_email', get_option( 'admin_email' ) );
				$gesendet = wp_mail(
					$an,
					__( 'Test email from Fines Finest Booking', 'fines-finest-booking' ),
					'<p>' . esc_html__( 'This email comes from the booking plugin. If you can read it, sending works.', 'fines-finest-booking' ) . '</p><p>' . esc_html__( 'Please also check whether it landed in your spam folder.', 'fines-finest-booking' ) . '</p>',
					array( 'Content-Type: text/html; charset=UTF-8', sprintf( 'From: %s <%s>', ffb_get_setting( 'from_name', get_bloginfo( 'name' ) ), ffb_from_email() ) )
				);

				if ( $gesendet ) {
					update_option( 'ffb_mail_tested', time(), false );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=mail_ok' ) );
				} else {
					set_transient( 'ffb_admin_error', __( 'wp_mail() returned false. Check your SMTP setup.', 'fines-finest-booking' ), 60 );
					wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=mail_error' ) );
				}
				exit;
			}

			if ( 'run_reminders' === $do ) {
				check_admin_referer( 'ffb_run_reminders' );
				FFB_Cron::send_reminders();
				FFB_Cron::reschedule_reminders();
				FFB_Cron::schedule();
				wp_safe_redirect( admin_url( 'admin.php?page=ffb-settings&ffb_notice=cron_ok' ) );
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

			// Aktionen aus Zusatzdateien, etwa der Knopf "Jetzt nach Updates suchen" der selbst gehosteten Fassung.
			do_action( 'ffb_admin_action', $do );
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
			self::google_return_error(
				sprintf(
					/* translators: %s: error code reported by Google */
					__( 'Google cancelled the sign-in (%s). Nothing was changed. Click "Connect with Google" again and allow access to the calendar.', 'fines-finest-booking' ),
					$grund
				)
			);
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = (string) get_transient( 'ffb_oauth_state' );

		if ( '' === $state || '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::google_return_error( __( 'The Google sign-in has expired or did not come from this page. Click "Connect with Google" again.', 'fines-finest-booking' ) );
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
		$input = isset( $_POST['ffb'] ) ? wp_unslash( $_POST['ffb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce prueft handle_actions(), die Werte werden unten einzeln bereinigt.

		// Geschaeftszeiten kommen als Von-Bis-Auswahl und werden hier zur gespeicherten Textform.
		foreach ( array_keys( self::weekdays() ) as $ffb_tag ) {
			$ffb_fenster                  = self::windows_from_selects( $input, 'hours_' . $ffb_tag );
			$input[ 'hours_' . $ffb_tag ] = self::windows_to_string( $ffb_fenster );
		}

		// Vorlaufzeit: Zahl mal Einheit.
		if ( isset( $input['global_min_notice'] ) ) {
			$ffb_einheit                = max( 1, (int) ( $input['global_min_notice_unit'] ?? 1 ) );
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
				$ids           = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
				$ids           = array_filter( array_map( 'sanitize_text_field', (array) $ids ) );
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
		$input = isset( $_POST['ffb'] ) ? wp_unslash( $_POST['ffb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce prueft handle_actions(), die Werte werden unten einzeln bereinigt.
		$id    = isset( $_POST['ffb_id'] ) ? (int) $_POST['ffb_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce prueft handle_actions().

		$availability = array();
		foreach ( array_keys( self::weekdays() ) as $day ) {
			$availability[ (string) $day ] = self::windows_from_selects( $input, 'day_' . $day );
		}

		// Vorlaufzeit: Zahl mal Einheit.
		if ( isset( $input['min_notice'] ) ) {
			$einheit             = max( 1, (int) ( $input['min_notice_unit'] ?? 1 ) );
			$input['min_notice'] = max( 0, (int) $input['min_notice'] ) * $einheit;
		}

		$questions = self::parse_questions( isset( $input['questions'] ) ? (string) $input['questions'] : '' );

		$data = array(
			'title'            => $input['title'] ?? '',
			'slug'             => $input['slug'] ?? '',
			'description'      => $input['description'] ?? '',
			'duration'         => $input['duration'] ?? 30,
			'slot_step'        => $input['slot_step'] ?? 30,
			'buffer_before'    => $input['buffer_before'] ?? 0,
			'buffer_after'     => $input['buffer_after'] ?? 0,
			'min_notice'       => $input['min_notice'] ?? 720,
			'max_days'         => $input['max_days'] ?? 45,
			'daily_limit'      => $input['daily_limit'] ?? 0,
			'location'         => $input['location'] ?? 'meet',
			'location_note'    => $input['location_note'] ?? '',
			'calendar_title'   => $input['calendar_title'] ?? '',
			'calendar_buffers' => ! empty( $input['calendar_buffers'] ),
			'availability'     => $availability,
			'blackouts'        => $input['blackouts'] ?? '',
			'questions'        => $questions,
			'active'           => ! empty( $input['active'] ),
			'sort_order'       => $input['sort_order'] ?? 0,
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
	 * Auswahlfeld fuer Minuten im 5-Minuten-Takt, 0 bis 120.
	 *
	 * Ein gespeicherter Wert ausserhalb des Rasters bleibt als eigene Option
	 * erhalten, damit Speichern ihn nicht stillschweigend aendert.
	 *
	 * @param string $name  Feldname.
	 * @param int    $value Aktueller Wert in Minuten.
	 */
	public static function render_minutes_select( $name, $value ) {
		$value  = max( 0, (int) $value );
		$werte  = range( 0, 120, 5 );
		if ( ! in_array( $value, $werte, true ) ) {
			$werte[] = $value;
			sort( $werte );
		}

		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $werte as $minuten ) {
			echo '<option value="' . (int) $minuten . '"' . selected( $value, (int) $minuten, false ) . '>' . (int) $minuten . ' ' . esc_html__( 'min', 'fines-finest-booking' ) . '</option>';
		}
		echo '</select>';
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
		$zeiten  = self::time_options();
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
				<?php echo esc_html_x( 'to', 'between two times of day, e.g. 09:00 to 12:00', 'fines-finest-booking' ); ?>
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
			echo '<span style="color:#996800;">' . esc_html__( 'This day had more than two time windows. On the next save only the first two are kept.', 'fines-finest-booking' ) . '</span>';
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
			1    => __( 'minutes', 'fines-finest-booking' ),
			60   => __( 'hours', 'fines-finest-booking' ),
			1440 => __( 'days', 'fines-finest-booking' ),
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
			$parts = preg_split( '/\s*(?:-|–|bis|to)\s*/u', $chunk );
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
	 * Wort, das im Fragen-Feld eine Pflichtfrage markiert.
	 *
	 * @return string
	 */
	protected static function required_marker() {
		return _x( 'required', 'marker word in the questions field, lowercase', 'fines-finest-booking' );
	}

	/**
	 * Wandelt Fragen-Zeilen in Struktur.
	 *
	 * @param string $raw Eingabe.
	 * @return array
	 */
	public static function parse_questions( $raw ) {
		$out     = array();
		$index   = 0;
		$markers = array_unique( array( 'required', 'yes', '1', 'pflicht', 'ja', strtolower( self::required_marker() ) ) );

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
				'required' => isset( $parts[2] ) && in_array( strtolower( $parts[2] ), $markers, true ) ? 1 : 0,
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
			$lines[] = $question['label'] . ' | ' . $question['type'] . ( $question['required'] ? ' | ' . self::required_marker() : '' );
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
				<strong><?php esc_html_e( 'Fines Finest Booking is active.', 'fines-finest-booking' ); ?></strong>
				<?php esc_html_e( 'Next step: connect your Google Calendar and work through the setup status.', 'fines-finest-booking' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-settings' ) ); ?>"><?php esc_html_e( 'Set up now', 'fines-finest-booking' ); ?></a>
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
	public static function hint( $text, $titel = '' ) {
		static $nr = 0;
		$nr++;
		$id = 'ffb-hint-' . $nr;
		if ( '' === $titel ) {
			$titel = __( 'Show explanation', 'fines-finest-booking' );
		}
		?>
		<button type="button" class="ffb-hint-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>" title="<?php echo esc_attr( $titel ); ?>">?</button>
		<div class="ffb-hint" id="<?php echo esc_attr( $id ); ?>" hidden><?php echo wp_kses_post( $text ); ?></div>
		<?php
	}

	/**
	 * Zeitpunkt der letzten Testmail, lesbar.
	 *
	 * @return string
	 */
	protected static function mail_tested_label() {
		$value = get_option( 'ffb_mail_tested' );
		if ( is_numeric( $value ) ) {
			return wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $value );
		}

		// Aeltere Versionen speicherten einen fertigen Text.
		return (string) $value;
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
		$anzahl        = count( $typen );

		// Zustand der Erinnerungsaufgabe.
		$cron     = FFB_Cron::status();
		$cron_url = '<code>' . esc_html( site_url( 'wp-cron.php' ) ) . '</code>';
		$format   = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
		$next     = $cron['next'] > time()
			/* translators: %s: human readable duration, e.g. "23 minutes" */
			? sprintf( __( 'in %s', 'fines-finest-booking' ), human_time_diff( time(), $cron['next'] ) )
			: __( 'at the next visit to the site', 'fines-finest-booking' );

		if ( $cron['last'] ) {
			/* translators: 1: date and time of the last run, 2: when the next run is due */
			$cron_gut = sprintf( __( 'Runs hourly via WP-Cron. Last run %1$s, next run %2$s.', 'fines-finest-booking' ), wp_date( $format, $cron['last'] ), $next );
		} else {
			/* translators: %s: when the next run is due */
			$cron_gut = sprintf( __( 'Runs hourly via WP-Cron. Not run yet, next run %s.', 'fines-finest-booking' ), $next );
		}

		if ( 'missing' === $cron['state'] ) {
			$cron_schlecht = __( 'The reminder task is not scheduled. Click the button to schedule it again and run it once.', 'fines-finest-booking' );
		} else {
			$cron_schlecht = sprintf(
				/* translators: 1: human readable duration, e.g. "3 hours", 2: URL of wp-cron.php in code formatting */
				__( 'The reminder task was due %1$s ago but has not run. WP-Cron only runs when the site gets visitors. On a quiet site, set up a real cron job at your host that calls %2$s every hour.', 'fines-finest-booking' ),
				human_time_diff( $cron['next'], time() ),
				$cron_url
			);
		}

		if ( $cron['disabled'] ) {
			/* translators: %s: URL of wp-cron.php in code formatting */
			$cron_hinweis   = sprintf( __( 'WP-Cron is switched off in wp-config.php (DISABLE_WP_CRON). A real cron job at your host must call %s regularly, for example every 15 minutes.', 'fines-finest-booking' ), $cron_url );
			$cron_gut      .= ' ' . $cron_hinweis;
			$cron_schlecht .= ' ' . $cron_hinweis;
		}

		return array(
			array(
				'ok'       => FFB_Google::is_connected(),
				'titel'    => __( 'Google Calendar connected', 'fines-finest-booking' ),
				/* translators: %s: email address of the connected Google account */
				'gut'      => sprintf( __( 'Connected as %s', 'fines-finest-booking' ), FFB_Google::connected_account() ),
				'schlecht' => __( 'Without the connection, appointments are not added to your calendar and busy times are not checked.', 'fines-finest-booking' ),
				'link'     => '#ffb-google',
				'aktion'   => __( 'Go to Google setup', 'fines-finest-booking' ),
			),
			array(
				'ok'       => ! empty( $typen ),
				'titel'    => __( 'At least one appointment type active', 'fines-finest-booking' ),
				'gut'      => sprintf(
					/* translators: 1: number of active appointment types, 2: their titles, comma separated */
					_n( '%1$d active: %2$s', '%1$d active: %2$s', $anzahl, 'fines-finest-booking' ),
					$anzahl,
					implode( ', ', wp_list_pluck( $typen, 'title' ) )
				),
				'schlecht' => __( 'Without an active appointment type the booking widget shows nothing.', 'fines-finest-booking' ),
				'link'     => admin_url( 'admin.php?page=ffb-event-types' ),
				'aktion'   => __( 'Open appointment types', 'fines-finest-booking' ),
			),
			array(
				'ok'       => $seite_bekannt,
				'titel'    => __( 'Booking page set up', 'fines-finest-booking' ),
				'gut'      => __( 'Shortcode found or booking page saved.', 'fines-finest-booking' ),
				'schlecht' => __( 'One click creates a page with the booking widget and saves its address. Or put the shortcode on a page of your own and enter its address below.', 'fines-finest-booking' ),
				'link'     => wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=create_page' ), 'ffb_create_page' ),
				'aktion'   => __( 'Create booking page', 'fines-finest-booking' ),
			),
			array(
				'ok'       => (bool) get_option( 'ffb_mail_tested' ),
				'titel'    => __( 'Email sending tested', 'fines-finest-booking' ),
				/* translators: %s: date and time of the last test email */
				'gut'      => sprintf( __( 'Last tested on %s', 'fines-finest-booking' ), esc_html( self::mail_tested_label() ) ),
				'schlecht' => __( 'Untested. Many hosts send emails unreliably, and you would only notice when someone does not show up.', 'fines-finest-booking' ),
				'link'     => '#ffb-mails',
				'aktion'   => __( 'Send test email', 'fines-finest-booking' ),
			),
			array(
				'ok'       => 'ok' === $cron['state'],
				'titel'    => __( 'Reminder task running', 'fines-finest-booking' ),
				'gut'      => $cron_gut,
				'schlecht' => $cron_schlecht,
				'link'     => wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=run_reminders' ), 'ffb_run_reminders' ),
				'aktion'   => __( 'Run now', 'fines-finest-booking' ),
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
			<h2>
			<?php
			if ( $offen ) {
				/* translators: %d: number of open setup steps */
				echo esc_html( sprintf( _n( 'Setup: %d step open', 'Setup: %d steps open', $offen, 'fines-finest-booking' ), $offen ) );
			} else {
				esc_html_e( 'Setup complete', 'fines-finest-booking' );
			}
			?>
			</h2>
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
				<?php esc_html_e( 'Time zone of this website:', 'fines-finest-booking' ); ?> <code><?php echo esc_html( $zeitzone ); ?></code>
				<?php if ( $utc_only ) : ?>
					<span style="color:#b32d2e;"><?php esc_html_e( 'This is a fixed offset. When daylight saving time changes, all appointments shift by one hour. Choose a city under Settings, General, for example Berlin.', 'fines-finest-booking' ); ?></span>
				<?php endif; ?>
			</p>

			<?php
			// Zusatzzeilen aus Zusatzdateien, etwa die Updatezeile der selbst gehosteten Fassung.
			do_action( 'ffb_status_box_extra' );

			$fehlende = FFB_Google::is_connected() ? FFB_Google::unreachable_busy_calendars() : array();
			if ( $fehlende ) :
				?>
				<p class="ffb-status__warn">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: calendar IDs, comma separated */
						__( 'These calendars are configured but not visible to the connected account, so they do not block anything: %s', 'fines-finest-booking' ),
						'<code>' . esc_html( implode( ', ', $fehlende ) ) . '</code>'
					),
					array( 'code' => array() )
				);
				?>
				</p>
			<?php endif; ?>

			<?php if ( FFB_Brevo::is_configured() && ! (int) ffb_get_setting( 'brevo_list_id' ) ) : ?>
				<p class="ffb-status__warn"><?php esc_html_e( 'Brevo has an API key but no list. No contacts are created this way.', 'fines-finest-booking' ); ?></p>
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
			'saved'              => array( 'success', __( 'Saved.', 'fines-finest-booking' ) ),
			'deleted'            => array( 'success', __( 'Appointment type deleted.', 'fines-finest-booking' ) ),
			'not_deleted'        => array( 'error', __( 'Appointment type not deleted.', 'fines-finest-booking' ) ),
			'cancelled'          => array( 'success', __( 'Appointment cancelled.', 'fines-finest-booking' ) ),
			'google_ok'          => array( 'success', __( 'Google Calendar is connected.', 'fines-finest-booking' ) ),
			'disconnected'       => array( 'success', __( 'Google connection removed.', 'fines-finest-booking' ) ),
			'brevo_ok'           => array( 'success', __( 'Brevo responds. The API key is valid.', 'fines-finest-booking' ) ),
			'mail_ok'            => array( 'success', __( 'Test email sent. Check your inbox, including the spam folder.', 'fines-finest-booking' ) ),
			'mail_error'         => array( 'error', __( 'The test email could not be sent.', 'fines-finest-booking' ) ),
			'cron_ok'            => array( 'success', __( 'Reminder task has run.', 'fines-finest-booking' ) ),
			'page_created'       => array( 'success', __( 'Booking page created and saved. Open it from the Pages menu to adjust text and design.', 'fines-finest-booking' ) ),
			'resent'             => array( 'success', __( 'Confirmation email sent again.', 'fines-finest-booking' ) ),
			'resend_error'       => array( 'error', __( 'The confirmation email could not be sent again.', 'fines-finest-booking' ) ),
			'duplicated'         => array( 'success', __( 'Appointment type duplicated. It stays inactive until you tick "Bookable" and save.', 'fines-finest-booking' ) ),
			'google_error'       => array( 'error', __( 'The Google connection did not work.', 'fines-finest-booking' ) ),
			'brevo_error'        => array( 'error', __( 'Brevo rejected the request.', 'fines-finest-booking' ) ),
			'error'              => array( 'error', __( 'That did not work.', 'fines-finest-booking' ) ),
		);

		// Weitere Hinweise aus Zusatzdateien, etwa die Ergebnisse der Updatepruefung der selbst gehosteten Fassung.
		$map = (array) apply_filters( 'ffb_admin_notices', $map );

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$detail = get_transient( 'ffb_admin_error' );
		delete_transient( 'ffb_admin_error' );

		// Zusatzlink hinter dem Hinweis, etwa der Weg zum Einspielen eines gefundenen Updates: (url, text) oder leer.
		$link = (array) apply_filters( 'ffb_admin_notice_link', array(), $notice );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s%s%s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] ),
			$detail ? ' <code>' . esc_html( $detail ) . '</code>' : '',
			$link ? ' <a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>' : ''
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
			<h1><?php esc_html_e( 'Bookings', 'fines-finest-booking' ); ?></h1>
			<?php self::notices(); ?>

			<ul class="subsubsub">
				<?php
				$tabs = array(
					'upcoming'  => __( 'Upcoming', 'fines-finest-booking' ),
					'past'      => __( 'Past', 'fines-finest-booking' ),
					'cancelled' => __( 'Cancelled', 'fines-finest-booking' ),
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
						<th style="width:210px;"><?php esc_html_e( 'Appointment', 'fines-finest-booking' ); ?></th>
						<th><?php esc_html_e( 'Who', 'fines-finest-booking' ); ?></th>
						<th><?php esc_html_e( 'Appointment type', 'fines-finest-booking' ); ?></th>
						<th><?php esc_html_e( 'Source', 'fines-finest-booking' ); ?></th>
						<th><?php esc_html_e( 'Details', 'fines-finest-booking' ); ?></th>
						<th style="width:170px;"><?php esc_html_e( 'Action', 'fines-finest-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No entries.', 'fines-finest-booking' ); ?></td></tr>
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
								<br><a href="<?php echo esc_url( $row['meet_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Meet link', 'fines-finest-booking' ); ?></a>
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
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-bookings&ffb_do=cancel_booking&id=' . (int) $row['id'] ), 'ffb_cancel_booking' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Really cancel this appointment? The person will get an email.', 'fines-finest-booking' ) ); ?>');"><?php echo esc_html_x( 'Cancel', 'cancel an appointment', 'fines-finest-booking' ); ?></a>
								<?php if ( strtotime( $row['start_utc'] . ' UTC' ) > time() ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-bookings&ffb_do=resend_confirmation&id=' . (int) $row['id'] ), 'ffb_resend_confirmation' ) ); ?>" title="<?php esc_attr_e( 'Sends the confirmation email with the calendar file again.', 'fines-finest-booking' ); ?>"><?php esc_html_e( 'Resend email', 'fines-finest-booking' ); ?></a>
								<?php endif; ?>
							<?php else : ?>
								<span class="ffb-badge"><?php esc_html_e( 'cancelled', 'fines-finest-booking' ); ?></span>
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
			<h1><?php esc_html_e( 'Appointment types', 'fines-finest-booking' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&new=1' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'fines-finest-booking' ); ?></a></h1>
			<?php self::notices(); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Title', 'fines-finest-booking' ); ?></th><th><?php esc_html_e( 'Shortcode', 'fines-finest-booking' ); ?></th><th><?php esc_html_e( 'Duration', 'fines-finest-booking' ); ?></th><th><?php esc_html_e( 'Status', 'fines-finest-booking' ); ?></th><th style="width:240px;"><?php esc_html_e( 'Action', 'fines-finest-booking' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( FFB_Event_Types::all() as $event ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&edit=' . $event['id'] ) ); ?>"><?php echo esc_html( $event['title'] ); ?></a></strong></td>
						<td><code id="ffb-sc-<?php echo (int) $event['id']; ?>">[ff_booking event="<?php echo esc_html( $event['slug'] ); ?>"]</code> <button type="button" class="button button-small ffb-copy" data-ffb-copy="#ffb-sc-<?php echo (int) $event['id']; ?>"><?php esc_html_e( 'Copy', 'fines-finest-booking' ); ?></button></td>
						<td>
						<?php
						/* translators: %d: duration in minutes, abbreviated */
						echo esc_html( sprintf( __( '%d min', 'fines-finest-booking' ), (int) $event['duration'] ) );
						?>
						</td>
						<td><?php echo $event['active'] ? esc_html__( 'active', 'fines-finest-booking' ) : esc_html__( 'inactive', 'fines-finest-booking' ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types&edit=' . $event['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'fines-finest-booking' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-event-types&ffb_do=duplicate_event_type&id=' . $event['id'] ), 'ffb_duplicate_event_type' ) ); ?>"><?php esc_html_e( 'Duplicate', 'fines-finest-booking' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-event-types&ffb_do=delete_event_type&id=' . $event['id'] ), 'ffb_delete_event_type' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this appointment type? Past bookings are kept. If it still has upcoming appointments, it will not be deleted.', 'fines-finest-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'fines-finest-booking' ); ?></a>
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
			'title'            => '',
			'slug'             => '',
			'description'      => '',
			'duration'         => 30,
			'slot_step'        => 30,
			'buffer_before'    => 0,
			'buffer_after'     => 15,
			'min_notice'       => 720,
			'max_days'         => 45,
			'daily_limit'      => 0,
			'location'         => 'meet',
			'location_note'    => '',
			'calendar_title'   => '',
			'calendar_buffers' => 0,
			'availability'     => FFB_Event_Types::normalize_availability( array() ),
			'blackouts'        => array(),
			'questions'        => array(),
			'active'           => 1,
			'sort_order'       => 0,
		);

		$event = $event ? array_merge( $defaults, $event ) : $defaults;

		/* translators: default calendar title; keep the placeholders {termintyp} and {name} as they are */
		$default_title = __( '{termintyp} with {name}', 'fines-finest-booking' );
		?>
		<div class="wrap ffb-admin">
			<h1><?php echo $id ? esc_html__( 'Edit appointment type', 'fines-finest-booking' ) : esc_html__( 'New appointment type', 'fines-finest-booking' ); ?></h1>
			<?php self::notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types' ) ); ?>">
				<?php wp_nonce_field( 'ffb_save_event_type' ); ?>
				<input type="hidden" name="ffb_action" value="save_event_type">
				<input type="hidden" name="ffb_id" value="<?php echo (int) $id; ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-title"><?php esc_html_e( 'Title', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-title" name="ffb[title]" type="text" class="regular-text" value="<?php echo esc_attr( $event['title'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-slug"><?php esc_html_e( 'Slug', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-slug" name="ffb[slug]" type="text" class="regular-text" value="<?php echo esc_attr( $event['slug'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used in the shortcode. Leave empty to generate it from the title.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-desc"><?php esc_html_e( 'Description', 'fines-finest-booking' ); ?></label></th>
						<td><textarea id="ffb-desc" name="ffb[description]" rows="4" class="large-text"><?php echo esc_textarea( $event['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Duration and interval', 'fines-finest-booking' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Duration', 'fines-finest-booking' ); ?> <input name="ffb[duration]" type="number" min="5" step="5" value="<?php echo (int) $event['duration']; ?>" class="small-text"> <?php esc_html_e( 'min', 'fines-finest-booking' ); ?></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Interval', 'fines-finest-booking' ); ?> <input name="ffb[slot_step]" type="number" min="5" step="5" value="<?php echo (int) $event['slot_step']; ?>" class="small-text"> <?php esc_html_e( 'min', 'fines-finest-booking' ); ?></label>
							<p class="description"><?php esc_html_e( 'The interval determines how far apart slots are offered, for example every 30 minutes.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Buffer', 'fines-finest-booking' ); ?></th>
						<td>
							<label><?php esc_html_e( 'before', 'fines-finest-booking' ); ?> <?php self::render_minutes_select( 'ffb[buffer_before]', (int) $event['buffer_before'] ); ?></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'after', 'fines-finest-booking' ); ?> <?php self::render_minutes_select( 'ffb[buffer_after]', (int) $event['buffer_after'] ); ?></label>
							<p class="description"><?php esc_html_e( 'The buffer before keeps preparation time free, the buffer after keeps follow-up time free. Between two bookings both add up.', 'fines-finest-booking' ); ?></p>
							<p><label><input name="ffb[calendar_buffers]" type="checkbox" value="1" <?php checked( ! empty( $event['calendar_buffers'] ) ); ?>> <?php esc_html_e( 'Enter the buffers in Google Calendar as blocked time', 'fines-finest-booking' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Creates two extra entries per booking, "Preparation" before and "Follow-up" after the appointment, marked as busy. They are deleted together with the appointment.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Time windows', 'fines-finest-booking' ); ?></th>
						<td>
							<?php foreach ( self::weekdays() as $num => $label ) : ?>
								<p style="margin:0 0 .5em;">
									<label style="display:inline-block;width:110px;vertical-align:middle;"><?php echo esc_html( $label ); ?></label>
									<?php self::render_day_selects( 'day_' . $num, $event['availability'][ (string) $num ] ?? array() ); ?>
								</p>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Two windows per day are possible. Both fields set to "—" means: not bookable on this day.', 'fines-finest-booking' ); ?></p>
							<?php if ( (int) ffb_get_setting( 'hours_enforce' ) ) : ?>
								<p class="description" style="color:#996800;"><?php esc_html_e( 'Note: business hours are enforced in the settings. Anything outside of them is saved here but not offered.', 'fines-finest-booking' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits', 'fines-finest-booking' ); ?></th>
						<td>
							<?php list( $ffb_nwert, $ffb_neinheit ) = self::split_duration( $event['min_notice'] ); ?>
							<label><?php esc_html_e( 'Minimum notice', 'fines-finest-booking' ); ?> <input name="ffb[min_notice]" type="number" min="0" value="<?php echo (int) $ffb_nwert; ?>" class="small-text"></label>
							<?php self::render_unit_select( 'min_notice_unit', $ffb_neinheit ); ?><br>
							<label><?php esc_html_e( 'Bookable up to', 'fines-finest-booking' ); ?> <input name="ffb[max_days]" type="number" min="1" value="<?php echo (int) $event['max_days']; ?>" class="small-text"> <?php esc_html_e( 'days ahead', 'fines-finest-booking' ); ?></label><br>
							<label><?php esc_html_e( 'At most', 'fines-finest-booking' ); ?> <input name="ffb[daily_limit]" type="number" min="0" value="<?php echo (int) $event['daily_limit']; ?>" class="small-text"> <?php esc_html_e( 'appointments of this type per day (0 = no limit)', 'fines-finest-booking' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-caltitle"><?php esc_html_e( 'Title in calendar', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-caltitle" name="ffb[calendar_title]" type="text" class="large-text" value="<?php echo esc_attr( $event['calendar_title'] ); ?>" placeholder="<?php echo esc_attr( $default_title ); ?>">
							<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: list of placeholders, 2: default title */
									__( 'This is the title of the entry in your Google Calendar. Placeholders: %1$s. Leave empty for %2$s.', 'fines-finest-booking' ),
									'<code>{name}</code>, <code>{termintyp}</code>, <code>{quelle}</code>, <code>{datum}</code>, <code>{uhrzeit}</code>, <code>{email}</code>',
									'<code>' . esc_html( $default_title ) . '</code>'
								),
								array( 'code' => array() )
							);
							?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-blackouts"><?php esc_html_e( 'Blocked days', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-blackouts" name="ffb[blackouts]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', (array) $event['blackouts'] ) ); ?>" placeholder="2026-12-24, 2026-12-31">
							<p class="description"><?php esc_html_e( 'Single days in the format YYYY-MM-DD, separated by commas. For holidays, add an all-day event in your Google Calendar instead, it is taken into account automatically.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Location', 'fines-finest-booking' ); ?></th>
						<td>
							<select name="ffb[location]">
								<?php
								$locations = array(
									'meet'     => __( 'Google Meet (link is created automatically)', 'fines-finest-booking' ),
									'phone'    => __( 'Phone (I will call)', 'fines-finest-booking' ),
									'inperson' => __( 'In person', 'fines-finest-booking' ),
									'custom'   => __( 'Custom note', 'fines-finest-booking' ),
								);
								foreach ( $locations as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $event['location'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p><input name="ffb[location_note]" type="text" class="regular-text" value="<?php echo esc_attr( $event['location_note'] ); ?>" placeholder="<?php esc_attr_e( 'Address or note', 'fines-finest-booking' ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-questions"><?php esc_html_e( 'Additional questions', 'fines-finest-booking' ); ?></label></th>
						<td>
							<textarea id="ffb-questions" name="ffb[questions]" rows="5" class="large-text" placeholder="<?php echo esc_attr( __( 'Your website | text', 'fines-finest-booking' ) . "\n" . __( 'What would you like to talk about? | textarea | required', 'fines-finest-booking' ) ); ?>"><?php echo esc_textarea( self::questions_to_string( $event['questions'] ) ); ?></textarea>
							<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: example of the line format */
									__( 'One question per line: %s. Types: text, textarea, tel, url. Name and email are always asked.', 'fines-finest-booking' ),
									'<code>' . esc_html__( 'Label | type | required', 'fines-finest-booking' ) . '</code>'
								),
								array( 'code' => array() )
							);
							?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'fines-finest-booking' ); ?></th>
						<td>
							<label><input type="checkbox" name="ffb[active]" value="1" <?php checked( $event['active'], 1 ); ?>> <?php esc_html_e( 'Bookable', 'fines-finest-booking' ); ?></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Order', 'fines-finest-booking' ); ?> <input name="ffb[sort_order]" type="number" value="<?php echo (int) $event['sort_order']; ?>" class="small-text"></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save', 'fines-finest-booking' ), 'primary', 'submit', false ); ?>
					<a class="button button-secondary" style="margin-left:8px;" href="<?php echo esc_url( admin_url( 'admin.php?page=ffb-event-types' ) ); ?>"><?php echo esc_html_x( 'Cancel', 'abort editing', 'fines-finest-booking' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Hilfetext zur Einrichtung des Google-Projekts.
	 *
	 * @return string HTML.
	 */
	protected static function google_hint_text() {
		$steps = array(
			sprintf(
				/* translators: %s: link to console.cloud.google.com */
				__( 'Sign in at %s with the Google account whose calendar should receive the appointments.', 'fines-finest-booking' ),
				'<a href="https://console.cloud.google.com" target="_blank" rel="noopener">console.cloud.google.com</a>'
			),
			__( 'Create a new project at the top left, the name is up to you.', 'fines-finest-booking' ),
			__( 'On the left under <em>APIs and services, Library</em>, search for <em>Google Calendar API</em> and click <em>Enable</em>.', 'fines-finest-booking' ),
			__( 'On the left under <em>Google Auth Platform</em>, start the configuration: app name, support email, user type <em>External</em>, contact address.', 'fines-finest-booking' ),
			__( 'Under <em>Clients</em>, create a new client of type <em>Web application</em>. Under authorised redirect URIs, enter exactly the address shown below.', 'fines-finest-booking' ),
			__( 'Enter the client ID and client secret below and save. The connect button then appears.', 'fines-finest-booking' ),
			__( 'Under <em>Audience</em>, click <em>Publish app</em>. Otherwise the connection expires after seven days.', 'fines-finest-booking' ),
		);

		$html  = '<p><strong>' . esc_html__( 'Why this is needed:', 'fines-finest-booking' ) . '</strong> ' . esc_html__( 'The plugin talks to your Google Calendar through an interface. For Google to know that your website is allowed to ask, you need a free project in the Google Cloud Console, once. It takes about fifteen minutes and is done after that.', 'fines-finest-booking' ) . '</p>';
		$html .= '<ol>';
		foreach ( $steps as $step ) {
			$html .= '<li>' . $step . '</li>';
		}
		$html .= '</ol>';
		$html .= '<p>' . esc_html__( 'When connecting, Google shows a warning once that the app is not verified. That is normal for an app only you use: click "Advanced" and continue.', 'fines-finest-booking' ) . '</p>';

		return $html;
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
			<h1><?php esc_html_e( 'Settings', 'fines-finest-booking' ); ?></h1>
			<?php self::notices(); ?>
			<?php self::render_status_box(); ?>

			<h2 id="ffb-google"><?php esc_html_e( 'Google Calendar', 'fines-finest-booking' ); ?>
				<?php self::hint( self::google_hint_text(), __( 'Instructions for the Google project', 'fines-finest-booking' ) ); ?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'fines-finest-booking' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p><span class="ffb-dot ffb-dot--on"></span>
							<?php
							if ( FFB_Google::connected_account() ) {
								echo wp_kses(
									sprintf(
										/* translators: %s: email address of the connected Google account */
										__( 'Connected as %s', 'fines-finest-booking' ),
										'<code>' . esc_html( FFB_Google::connected_account() ) . '</code>'
									),
									array( 'code' => array() )
								);
							} else {
								esc_html_e( 'Connected', 'fines-finest-booking' );
							}
							?>
							</p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=disconnect' ), 'ffb_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'fines-finest-booking' ); ?></a>
						<?php elseif ( $auth_url ) : ?>
							<p><span class="ffb-dot"></span> <?php esc_html_e( 'Not connected', 'fines-finest-booking' ); ?></p>
							<a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>"><?php esc_html_e( 'Connect with Google', 'fines-finest-booking' ); ?></a>
						<?php else : ?>
							<p><span class="ffb-dot"></span> <?php esc_html_e( 'Enter the client ID and client secret first and save.', 'fines-finest-booking' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'fines-finest-booking' ); ?></th>
					<td>
						<code id="ffb-redirect"><?php echo esc_html( FFB_Google::redirect_uri() ); ?></code>
						<button type="button" class="button button-small ffb-copy" data-ffb-copy="#ffb-redirect"><?php esc_html_e( 'Copy', 'fines-finest-booking' ); ?></button>
						<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: "www" in code formatting */
								__( 'Exactly this address must be entered in the Google Cloud Console as an authorised redirect URI. A missing %s is enough to break it.', 'fines-finest-booking' ),
								'<code>www</code>'
							),
							array( 'code' => array() )
						);
						?>
						</p>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ffb-settings' ) ); ?>">
				<?php wp_nonce_field( 'ffb_save_settings' ); ?>
				<input type="hidden" name="ffb_action" value="save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-cid"><?php esc_html_e( 'Client ID', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-cid" name="ffb[google_client_id]" type="text" class="large-text" value="<?php echo esc_attr( ffb_get_setting( 'google_client_id' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-cs"><?php esc_html_e( 'Client secret', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-cs" name="ffb[google_client_secret]" type="password" class="large-text" autocomplete="new-password" value="<?php echo esc_attr( ffb_get_setting( 'google_client_secret' ) ); ?>"></td>
					</tr>
					<?php
					$ffb_kalender = $connected ? FFB_Google::get_calendars() : null;
					$ffb_ziel     = (string) ffb_get_setting( 'google_calendar_id', 'primary' );
					$ffb_busy_ids = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) ffb_get_setting( 'google_busy_calendars' ) ) ) );
					?>
					<tr>
						<th scope="row"><label for="ffb-calid"><?php esc_html_e( 'Target calendar', 'fines-finest-booking' ); ?></label></th>
						<td>
							<?php if ( is_array( $ffb_kalender ) && $ffb_kalender ) : ?>
								<select id="ffb-calid" name="ffb[google_calendar_id]">
									<option value="primary" <?php selected( $ffb_ziel, 'primary' ); ?>><?php esc_html_e( 'Primary calendar', 'fines-finest-booking' ); ?></option>
									<?php
									foreach ( $ffb_kalender as $ffb_id => $ffb_cal ) :
										if ( ! $ffb_cal['schreiben'] || $ffb_cal['primaer'] ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $ffb_id ); ?>" <?php selected( $ffb_ziel, $ffb_id ); ?>><?php echo esc_html( $ffb_cal['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Booked appointments go here. Only calendars you can write to are offered.', 'fines-finest-booking' ); ?></p>
							<?php else : ?>
								<input id="ffb-calid" name="ffb[google_calendar_id]" type="text" class="regular-text" value="<?php echo esc_attr( $ffb_ziel ); ?>">
								<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: the word "primary" in code formatting */
										__( '%s is your primary calendar. After connecting you can choose here.', 'fines-finest-booking' ),
										'<code>primary</code>'
									),
									array( 'code' => array() )
								);
								?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Also check these calendars', 'fines-finest-booking' ); ?></th>
						<td>
							<input type="hidden" name="ffb[google_busy_calendars_vorhanden]" value="1">
							<?php if ( is_array( $ffb_kalender ) && $ffb_kalender ) : ?>
								<?php foreach ( $ffb_kalender as $ffb_id => $ffb_cal ) : ?>
									<?php
									if ( $ffb_cal['primaer'] ) {
										continue;
									}
									?>
									<p style="margin:0 0 .4em;">
										<label>
											<input type="checkbox" name="ffb[google_busy_calendars][]" value="<?php echo esc_attr( $ffb_id ); ?>" <?php checked( in_array( (string) $ffb_id, $ffb_busy_ids, true ) ); ?>>
											<?php echo esc_html( $ffb_cal['name'] ); ?>
											<span style="color:#787c82;">&nbsp;<?php echo esc_html( $ffb_id ); ?></span>
										</label>
									</p>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'These calendars additionally block slots, nothing is written to them. The target calendar is always checked.', 'fines-finest-booking' ); ?></p>
								<?php
								$ffb_fehlend = array_diff( $ffb_busy_ids, array_map( 'strval', array_keys( $ffb_kalender ) ) );
								if ( $ffb_fehlend ) :
									?>
									<p class="description" style="color:#b32d2e;">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: calendar IDs, comma separated */
											__( 'Configured but not visible to this Google account: %s', 'fines-finest-booking' ),
											'<code>' . esc_html( implode( ', ', $ffb_fehlend ) ) . '</code>'
										),
										array( 'code' => array() )
									);
									echo '<br>';
									echo wp_kses(
										sprintf(
											/* translators: 1: the word "nothing" in bold, 2: email address of the connected Google account */
											__( 'These calendars block %1$s. To make them count, share them in the other Google account with %2$s, at least with "See only free/busy". They then appear in this list.', 'fines-finest-booking' ),
											'<strong>' . esc_html__( 'nothing', 'fines-finest-booking' ) . '</strong>',
											'<code>' . esc_html( FFB_Google::connected_account() ) . '</code>'
										),
										array(
											'strong' => array(),
											'code'   => array(),
										)
									);
									?>
									</p>
								<?php endif; ?>
							<?php elseif ( is_wp_error( $ffb_kalender ) ) : ?>
								<input name="ffb[google_busy_calendars]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', $ffb_busy_ids ) ); ?>">
								<p class="description" style="color:#b32d2e;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: error message */
										__( 'The calendar list could not be loaded: %s', 'fines-finest-booking' ),
										'<code>' . esc_html( $ffb_kalender->get_error_message() ) . '</code>'
									),
									array( 'code' => array() )
								);
								?>
								</p>
							<?php else : ?>
								<input name="ffb[google_busy_calendars]" type="text" class="large-text" value="<?php echo esc_attr( implode( ', ', $ffb_busy_ids ) ); ?>">
								<p class="description"><?php esc_html_e( 'After connecting you can tick the calendars here.', 'fines-finest-booking' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Limits for all appointment types', 'fines-finest-booking' ); ?></h2>
				<p class="description" style="max-width:46em;"><?php esc_html_e( 'These values apply in addition to the settings of the individual appointment types and can only restrict further, never extend. No appointment type can override them.', 'fines-finest-booking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Business hours', 'fines-finest-booking' ); ?></th>
						<td>
							<label><input type="checkbox" name="ffb[hours_enforce]" value="1" <?php checked( (int) ffb_get_setting( 'hours_enforce' ), 1 ); ?>> <?php esc_html_e( 'Enforce business hours', 'fines-finest-booking' ); ?></label>
							<p class="description"><?php esc_html_e( 'If unticked, only the time windows of the individual appointment types apply.', 'fines-finest-booking' ); ?></p>
							<div style="margin-top:1em;">
							<?php foreach ( self::weekdays() as $ffb_num => $ffb_label ) : ?>
								<p style="margin:0 0 .5em;">
									<label style="display:inline-block;width:110px;vertical-align:middle;"><?php echo esc_html( $ffb_label ); ?></label>
									<?php self::render_day_selects( 'hours_' . $ffb_num, self::parse_windows( ffb_get_setting( 'hours_' . $ffb_num, '' ) ) ); ?>
								</p>
							<?php endforeach; ?>
							</div>
							<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: the sentence "Both fields set to — blocks the whole weekday" in bold */
									__( 'Two windows per day are possible, for example morning and afternoon. %s as soon as the box above is ticked. Incomplete or reversed pairs are discarded.', 'fines-finest-booking' ),
									'<strong>' . esc_html__( 'Both fields set to "—" block the whole weekday', 'fines-finest-booking' ) . '</strong>'
								),
								array( 'strong' => array() )
							);
							?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-globalnotice"><?php esc_html_e( 'Minimum notice', 'fines-finest-booking' ); ?></label></th>
						<td>
							<?php list( $ffb_wert, $ffb_einheit ) = self::split_duration( ffb_get_setting( 'global_min_notice' ) ); ?>
							<input id="ffb-globalnotice" name="ffb[global_min_notice]" type="number" min="0" class="small-text" value="<?php echo (int) $ffb_wert; ?>">
							<?php self::render_unit_select( 'global_min_notice_unit', $ffb_einheit ); ?>
							<p class="description"><?php esc_html_e( 'Lower limit for all appointment types. If an appointment type has a longer notice period, the longer one applies. 0 means no additional limit. Example: 2 days prevents bookings for the next 48 hours.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-globalblackouts"><?php esc_html_e( 'Blocked days', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-globalblackouts" name="ffb[global_blackouts]" type="text" class="large-text" value="<?php echo esc_attr( ffb_get_setting( 'global_blackouts', '' ) ); ?>" placeholder="2026-12-24, 2026-12-25, 2026-12-31">
							<p class="description"><?php esc_html_e( 'Applies to all appointment types, format YYYY-MM-DD. For holidays an all-day event in your Google Calendar is enough, it blocks automatically.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 id="ffb-mails"><?php esc_html_e( 'Emails', 'fines-finest-booking' ); ?>
					<?php
					self::hint(
						'<p>' . wp_kses(
							sprintf(
								/* translators: %s: the function name wp_mail() in code formatting */
								__( 'WordPress sends emails through the %s function. Many hosts deliver them unreliably because the web server is not authorised as a sender for your domain. The result: confirmations land in spam or not at all.', 'fines-finest-booking' ),
								'<code>wp_mail()</code>'
							),
							array( 'code' => array() )
						) . '</p><p>' . esc_html__( 'The solution is an SMTP plugin that routes sending through an email service, for example Brevo. Use the test button below before you make the booking widget public.', 'fines-finest-booking' ) . '</p>',
						__( 'Why emails often do not arrive', 'fines-finest-booking' )
					);
					?>
				</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-notify"><?php esc_html_e( 'Notification to', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-notify" name="ffb[notify_email]" type="email" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'notify_email' ) ); ?>">
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=test_mail' ), 'ffb_test_mail' ) ); ?>"><?php esc_html_e( 'Send test email', 'fines-finest-booking' ); ?></a>
							<p class="description"><?php esc_html_e( 'Save first if you have just changed the address. The test email goes to the saved address.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fromname"><?php esc_html_e( 'Sender name', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-fromname" name="ffb[from_name]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'from_name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fromemail"><?php esc_html_e( 'Sender address', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-fromemail" name="ffb[from_email]" type="email" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'from_email' ) ); ?>" placeholder="<?php echo esc_attr( ffb_from_email() ); ?>">
							<p class="description"><?php esc_html_e( 'Must match your domain, otherwise emails land in spam. With Brevo as SMTP relay you are on the safe side.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-page"><?php esc_html_e( 'Booking page', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-page" name="ffb[booking_page_url]" type="url" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'booking_page_url' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/' . sanitize_title( __( 'Appointment', 'fines-finest-booking' ) ) . '/' ) ); ?>">
							<p class="description"><?php esc_html_e( 'The page with the shortcode. Cancellation links in the emails open it. Without it, or if the page disappears, they open a plain cancellation page instead.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
				</table>

				<h2>Brevo</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-brevo"><?php esc_html_e( 'API key', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-brevo" name="ffb[brevo_api_key]" type="password" class="large-text" autocomplete="new-password" value="<?php echo esc_attr( ffb_get_setting( 'brevo_api_key' ) ); ?>">
							<p class="description"><?php esc_html_e( 'In Brevo: Settings, then SMTP & API, then API keys. Leave empty to switch the integration off.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-brevo-list"><?php esc_html_e( 'List', 'fines-finest-booking' ); ?></label></th>
						<td>
							<?php
							$ffb_gewaehlt = (int) ffb_get_setting( 'brevo_list_id' );
							$ffb_listen   = FFB_Brevo::is_configured() ? FFB_Brevo::get_lists() : null;

							if ( is_array( $ffb_listen ) && $ffb_listen ) :
								?>
								<select id="ffb-brevo-list" name="ffb[brevo_list_id]">
									<option value="0"><?php esc_html_e( '— please choose —', 'fines-finest-booking' ); ?></option>
									<?php foreach ( $ffb_listen as $ffb_id => $ffb_name ) : ?>
										<option value="<?php echo (int) $ffb_id; ?>" <?php selected( $ffb_gewaehlt, (int) $ffb_id ); ?>>
											<?php
											/* translators: 1: list name, 2: list ID */
											echo esc_html( sprintf( __( '%1$s (ID %2$d)', 'fines-finest-booking' ), $ffb_name, $ffb_id ) );
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ffb-settings&ffb_do=test_brevo' ), 'ffb_test_brevo' ) ); ?>"><?php esc_html_e( 'Test connection', 'fines-finest-booking' ); ?></a>
								<?php if ( ! $ffb_gewaehlt ) : ?>
									<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Without a selected list the plugin does not create contacts in Brevo.', 'fines-finest-booking' ); ?></p>
								<?php endif; ?>
							<?php elseif ( is_wp_error( $ffb_listen ) ) : ?>
								<input id="ffb-brevo-list" name="ffb[brevo_list_id]" type="number" class="small-text" value="<?php echo esc_attr( $ffb_gewaehlt ); ?>">
								<p class="description" style="color:#b32d2e;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: error message */
										__( 'The lists could not be loaded: %s Check whether the API key has the "Contacts" permission. Until then you can enter the ID by hand.', 'fines-finest-booking' ),
										'<code>' . esc_html( $ffb_listen->get_error_message() ) . '</code>'
									),
									array( 'code' => array() )
								);
								?>
								</p>
							<?php else : ?>
								<input id="ffb-brevo-list" name="ffb[brevo_list_id]" type="number" class="small-text" value="<?php echo esc_attr( $ffb_gewaehlt ); ?>">
								<p class="description"><?php esc_html_e( 'Enter the API key above and save. Then you can pick the list here.', 'fines-finest-booking' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Contacts go into this list. Note: booking an appointment is not consent to a newsletter. Use a separate list for this.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Privacy', 'fines-finest-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffb-consent"><?php esc_html_e( 'Consent text', 'fines-finest-booking' ); ?></label></th>
						<td><textarea id="ffb-consent" name="ffb[consent_text]" rows="3" class="large-text"><?php echo esc_textarea( ffb_get_setting( 'consent_text' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-privacy"><?php esc_html_e( 'Link to privacy policy', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-privacy" name="ffb[privacy_url]" type="url" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'privacy_url' ) ); ?>" placeholder="<?php echo esc_attr( get_privacy_policy_url() ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to use the privacy policy page from Settings, Privacy.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-retention"><?php esc_html_e( 'Retention', 'fines-finest-booking' ); ?></label></th>
						<td>
							<input id="ffb-retention" name="ffb[retention_days]" type="number" min="0" class="small-text" value="<?php echo (int) ffb_get_setting( 'retention_days' ); ?>"> <?php esc_html_e( 'days', 'fines-finest-booking' ); ?>
							<p class="description"><?php esc_html_e( 'Older bookings are deleted automatically. 0 switches deletion off.', 'fines-finest-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-rate"><?php esc_html_e( 'Bookings per hour and IP address', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-rate" name="ffb[rate_limit]" type="number" min="0" class="small-text" value="<?php echo (int) ffb_get_setting( 'rate_limit' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'fines-finest-booking' ); ?></th>
						<td><label><input type="checkbox" name="ffb[delete_on_uninstall]" value="1" <?php checked( (int) ffb_get_setting( 'delete_on_uninstall' ), 1 ); ?>> <?php esc_html_e( 'Also delete tables and settings', 'fines-finest-booking' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Design', 'fines-finest-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$colors = array(
						'color_accent'      => __( 'Accent', 'fines-finest-booking' ),
						'color_accent_soft' => __( 'Accent light', 'fines-finest-booking' ),
						'color_secondary'   => __( 'Secondary', 'fines-finest-booking' ),
						'color_success'     => __( 'Success', 'fines-finest-booking' ),
						'color_bg'          => __( 'Background', 'fines-finest-booking' ),
						'color_bg_deep'     => __( 'Background dark', 'fines-finest-booking' ),
						'color_surface'     => __( 'Surface', 'fines-finest-booking' ),
						'color_text'        => __( 'Text', 'fines-finest-booking' ),
						'color_muted'       => __( 'Text muted', 'fines-finest-booking' ),
					);
					foreach ( $colors as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ffb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input id="ffb-<?php echo esc_attr( $key ); ?>" name="ffb[<?php echo esc_attr( $key ); ?>]" type="text" class="small-text" value="<?php echo esc_attr( ffb_get_setting( $key ) ); ?>"> <input type="color" value="<?php echo esc_attr( ffb_get_setting( $key ) ); ?>" oninput="document.getElementById('ffb-<?php echo esc_attr( $key ); ?>').value=this.value;"></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="ffb-fd"><?php esc_html_e( 'Display font', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-fd" name="ffb[font_display]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'font_display' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffb-fb"><?php esc_html_e( 'Body font', 'fines-finest-booking' ); ?></label></th>
						<td><input id="ffb-fb" name="ffb[font_body]" type="text" class="regular-text" value="<?php echo esc_attr( ffb_get_setting( 'font_body' ) ); ?>"></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'fines-finest-booking' ) ); ?>
			</form>

			<?php if ( ! empty( $log ) ) : ?>
				<h2><?php esc_html_e( 'Recent errors', 'fines-finest-booking' ); ?></h2>
				<table class="wp-list-table widefat striped">
					<thead><tr><th style="width:170px;"><?php esc_html_e( 'Time', 'fines-finest-booking' ); ?></th><th><?php esc_html_e( 'Message', 'fines-finest-booking' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( (array) $log as $entry ) : ?>
						<tr><td><?php echo esc_html( $entry['time'] ); ?></td><td><code><?php echo esc_html( $entry['text'] ); ?></code></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( FFB_DONATE_URL ) : // Kaffeekasse, nur mit hinterlegter Adresse. ?>
				<p class="ffb-coffee">
					<?php esc_html_e( 'Fines Finest Booking is free. It is built and maintained by Fine, a one-woman business. If the plugin saves you time, you can say thank you with a coffee.', 'fines-finest-booking' ); ?>
					<a href="<?php echo esc_url( FFB_DONATE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Coffee fund via PayPal', 'fines-finest-booking' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
