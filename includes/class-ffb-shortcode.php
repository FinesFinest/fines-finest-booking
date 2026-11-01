<?php
/**
 * Shortcodes und Frontend-Assets.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendert die Buchungsstrecke.
 */
class FFB_Shortcode {

	/**
	 * Wurde ein Shortcode auf der Seite gerendert?
	 *
	 * @var bool
	 */
	protected static $rendered = false;

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_shortcode( 'ff_booking', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_for_content' ) );
		// Prioritaet 1: vor den Weiterleitungen von WordPress, die einen alten Link sonst umbiegen oder verlieren.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_standalone_cancel' ), 1 );
	}

	/**
	 * Faengt jeden Absage-Link ab, der nicht auf der Buchungsseite landet.
	 *
	 * Auf der eingetragenen Buchungsseite rendert der Shortcode die Absage im
	 * Design der Website. Ueberall sonst liefert das Plugin eine eigene, schlanke
	 * Seite aus: wenn die Buchungsseite umbenannt oder geloescht wurde (404), wenn
	 * keine eingetragen ist (Link zeigt auf die Startseite), wenn sie
	 * passwortgeschuetzt ist oder wenn der Link zu keinem Termin passt. Alte Links
	 * aus verschickten Mails laufen so nie ins Leere.
	 */
	public static function maybe_standalone_cancel() {
		if ( is_admin() ) {
			return;
		}

		$token = isset( $_GET['ffb_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['ffb_cancel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $token ) {
			return;
		}

		if ( self::is_booking_page_request() && ! post_password_required() ) {
			return;
		}

		self::render_standalone_cancel( $token );
	}

	/**
	 * Ist die aktuelle Anfrage die in den Einstellungen eingetragene Buchungsseite?
	 *
	 * @return bool
	 */
	protected static function is_booking_page_request() {
		$configured = (string) ffb_get_setting( 'booking_page_url' );
		if ( '' === $configured || is_404() ) {
			return false;
		}

		$page_id = (int) url_to_postid( $configured );
		if ( $page_id > 0 ) {
			return is_singular() && (int) get_queried_object_id() === $page_id;
		}

		// Kein WordPress-Beitrag hinter der Adresse, etwa eine Builder-Vorlage: Pfad vergleichen.
		$current = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( '' === trim( (string) wp_parse_url( $configured, PHP_URL_PATH ), '/' ) ) {
			return is_front_page();
		}

		return self::same_path( $configured, $current );
	}

	/**
	 * Vergleicht zwei Adressen allein anhand des Pfads. Schema, Host, Parameter
	 * und ein Schraegstrich am Ende spielen keine Rolle.
	 *
	 * Eine eingetragene Adresse mit Parametern (etwa ?page_id=4) oder ohne Pfad
	 * (die Startseite) laesst sich so nicht sicher vergleichen und ergibt false.
	 *
	 * @param string $configured Eingetragene Buchungsseite.
	 * @param string $current    Aktuelle Anfrage.
	 * @return bool
	 */
	public static function same_path( $configured, $current ) {
		$a = wp_parse_url( (string) $configured );
		$b = wp_parse_url( (string) $current );

		if ( ! is_array( $a ) || ! is_array( $b ) || ! empty( $a['query'] ) ) {
			return false;
		}

		$path_a = trim( isset( $a['path'] ) ? $a['path'] : '', '/' );
		$path_b = trim( isset( $b['path'] ) ? $b['path'] : '', '/' );

		return '' !== $path_a && $path_a === $path_b;
	}

	/**
	 * Liefert die eigenstaendige Absage-Seite aus und beendet die Anfrage.
	 *
	 * @param string $token Token aus dem Mail-Link.
	 */
	protected static function render_standalone_cancel( $token ) {
		nocache_headers();
		status_header( 200 );

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>
	<?php
	/* translators: %s: site name */
	echo esc_html( sprintf( __( 'Cancel appointment - %s', 'fines-finest-booking' ), get_bloginfo( 'name' ) ) );
	?>
	</title>
	<link rel="stylesheet" href="<?php echo esc_url( FFB_URL . 'assets/ffb-frontend.css?ver=' . FFB_VERSION ); ?>">
	<style>
		body { margin: 0; padding: 3rem 1.25rem; background: <?php echo esc_attr( ffb_get_setting( 'color_bg_deep' ) ); ?>; }
		.ffb { max-width: 560px; }
	</style>
</head>
<body>
	<?php echo self::render_cancel( $token ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<script>window.FFB_CONFIG = <?php echo wp_json_encode( self::frontend_config() ); ?>;</script>
	<script src="<?php echo esc_url( FFB_URL . 'assets/ffb-frontend.js?ver=' . FFB_VERSION ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Assets registrieren. Laeuft frueh auf init, nicht erst in wp_enqueue_scripts.
	 *
	 * Block-Themes rendern den Seiteninhalt, bevor wp_head() und damit
	 * wp_enqueue_scripts laufen. Der Shortcode wuerde dann Konfiguration und
	 * Farben an noch nicht registrierte Handles haengen, und WordPress wirft
	 * beides stillschweigend weg. Mit der fruehen Registrierung klappt es auf
	 * klassischen Themes, in Oxygen-Codebloecken und auf Block-Themes.
	 */
	public static function register_assets() {
		wp_register_style( 'ffb-frontend', FFB_URL . 'assets/ffb-frontend.css', array(), FFB_VERSION );
		wp_register_script( 'ffb-frontend', FFB_URL . 'assets/ffb-frontend.js', array(), FFB_VERSION, true );
	}

	/**
	 * Steht der Shortcode im Beitragsinhalt, laden wir die Assets schon im Kopfbereich.
	 * In Oxygen-Codebloecken greift stattdessen der Aufruf beim Rendern.
	 */
	public static function enqueue_for_content() {
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'ff_booking' ) ) {
			self::enqueue();
		}
	}

	/**
	 * Laedt Stylesheet und Skript samt Konfiguration.
	 */
	protected static function enqueue() {
		if ( ! wp_script_is( 'ffb-frontend', 'registered' ) ) {
			self::register_assets();
		}

		wp_enqueue_style( 'ffb-frontend' );
		wp_enqueue_script( 'ffb-frontend' );
		self::attach_data();
	}

	/**
	 * Haengt Farben und Konfiguration an die Assets. Laeuft nur einmal.
	 */
	protected static function attach_data() {
		if ( self::$rendered ) {
			return;
		}

		wp_add_inline_style( 'ffb-frontend', self::inline_vars() );
		wp_localize_script( 'ffb-frontend', 'FFB_CONFIG', self::frontend_config() );

		self::$rendered = true;
	}

	/**
	 * Konfiguration und Texte fuer das Frontend-Skript.
	 *
	 * Alle sichtbaren Texte kommen von hier, damit sie ueber die normalen
	 * Sprachdateien uebersetzt werden. Monats- und Wochentagsnamen liefert WordPress.
	 *
	 * @return array
	 */
	public static function frontend_config() {
		global $wp_locale;

		$months         = array();
		$months_short   = array();
		$weekdays       = array();
		$weekdays_short = array();

		for ( $month = 1; $month <= 12; $month++ ) {
			$name           = $wp_locale->get_month( $month );
			$months[]       = $name;
			$months_short[] = $wp_locale->get_month_abbrev( $name );
		}
		for ( $day = 0; $day <= 6; $day++ ) {
			$name             = $wp_locale->get_weekday( $day );
			$weekdays[]       = $name;
			$weekdays_short[] = $wp_locale->get_weekday_abbrev( $name );
		}

		return array(
			'root'          => esc_url_raw( rest_url( FFB_Rest::NS ) ),
			'consentText'   => ffb_get_setting( 'consent_text' ),
			'privacyUrl'    => ffb_get_setting( 'privacy_url' ),
			'months'        => $months,
			'monthsShort'   => $months_short,
			'weekdays'      => $weekdays,
			'weekdaysShort' => $weekdays_short,
			'i18n'          => array(
				'loading'         => __( 'Loading appointments …', 'fines-finest-booking' ),
				'genericError'    => __( 'Something went wrong.', 'fines-finest-booking' ),
				/* translators: %d: duration in minutes */
				'minutes'         => _n( '%d minute', '%d minutes', 2, 'fines-finest-booking' ),
				'meet'            => __( 'Google Meet', 'fines-finest-booking' ),
				'phone'           => __( 'Phone', 'fines-finest-booking' ),
				'noSlots'         => __( 'No appointments are available right now. Send me an email and we will find one.', 'fines-finest-booking' ),
				/* translators: %d: number of free slots on a day */
				'slotOne'         => _n( '%d slot', '%d slots', 1, 'fines-finest-booking' ),
				/* translators: %d: number of free slots on a day */
				'slotMany'        => _n( '%d slot', '%d slots', 2, 'fines-finest-booking' ),
				'yourAppointment' => __( 'Your appointment', 'fines-finest-booking' ),
				/* translators: 1: date like "Tuesday, March 3", 2: time like "15:00" */
				'summary'         => __( '%1$s, %2$s', 'fines-finest-booking' ),
				'name'            => __( 'Name', 'fines-finest-booking' ),
				'email'           => __( 'Email', 'fines-finest-booking' ),
				'phoneOptional'   => __( 'Phone (optional)', 'fines-finest-booking' ),
				'consentFallback' => __( 'I agree to the processing of my data.', 'fines-finest-booking' ),
				'privacyPolicy'   => __( 'Privacy policy', 'fines-finest-booking' ),
				'submit'          => __( 'Book appointment', 'fines-finest-booking' ),
				'fillRequired'    => __( 'Please fill in the required fields.', 'fines-finest-booking' ),
				'consentRequired' => __( 'Without your consent to data processing I cannot set up the appointment.', 'fines-finest-booking' ),
				'booking'         => __( 'Booking …', 'fines-finest-booking' ),
				'successTitle'    => __( 'Your appointment is booked.', 'fines-finest-booking' ),
				/* translators: %s: email address */
				'successMail'     => __( 'I have sent a confirmation to %s, with a calendar file to add the appointment.', 'fines-finest-booking' ),
				'toVideoCall'     => __( 'Join video call', 'fines-finest-booking' ),
				'willCall'        => __( 'I will call you at the agreed time.', 'fines-finest-booking' ),
				'cancelling'      => __( 'Cancelling …', 'fines-finest-booking' ),
				'cancelConfirm'   => __( 'Yes, cancel appointment', 'fines-finest-booking' ),
				/* translators: 1: weekday name, 2: day of month, 3: month name */
				'longDate'        => __( '%1$s, %3$s %2$d', 'fines-finest-booking' ),
			),
		);
	}

	/**
	 * Inline-CSS mit den Farbwerten aus den Einstellungen.
	 *
	 * @return string
	 */
	protected static function inline_vars() {
		$map = array(
			'--ffb-accent'       => 'color_accent',
			'--ffb-accent-soft'  => 'color_accent_soft',
			'--ffb-secondary'    => 'color_secondary',
			'--ffb-success'      => 'color_success',
			'--ffb-bg'           => 'color_bg',
			'--ffb-bg-deep'      => 'color_bg_deep',
			'--ffb-surface'      => 'color_surface',
			'--ffb-text'         => 'color_text',
			'--ffb-muted'        => 'color_muted',
			'--ffb-font-display' => 'font_display',
			'--ffb-font-body'    => 'font_body',
		);

		$out = '';
		foreach ( $map as $var => $key ) {
			$out .= $var . ':' . ffb_get_setting( $key ) . ';';
		}

		return '.ffb{' . $out . '}';
	}

	/**
	 * Shortcode-Ausgabe.
	 *
	 * @param array $atts Attribute.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'event'  => '',
				'days'   => 45,
				'quelle' => '',
			),
			$atts,
			'ff_booking'
		);

		self::enqueue();

		// Storno-Ansicht, wenn der Mail-Link aufgerufen wurde.
		$cancel_token = isset( $_GET['ffb_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['ffb_cancel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $cancel_token ) {
			return self::render_cancel( $cancel_token );
		}

		$slug = sanitize_title( $atts['event'] );
		if ( ! $slug ) {
			$active = FFB_Event_Types::all( true );
			if ( empty( $active ) ) {
				return '<div class="ffb ffb-note">' . esc_html__( 'No appointment type can be booked right now.', 'fines-finest-booking' ) . '</div>';
			}
			if ( count( $active ) === 1 ) {
				$slug = $active[0]['slug'];
			} else {
				return self::render_chooser( $active, (int) $atts['days'], $atts['quelle'] );
			}
		}

		$event = FFB_Event_Types::get_by_slug( $slug );
		if ( ! $event || ! $event['active'] ) {
			return '<div class="ffb ffb-note">' . esc_html__( 'This appointment type cannot be booked right now.', 'fines-finest-booking' ) . '</div>';
		}

		return self::render_widget( $event, (int) $atts['days'], $atts['quelle'] );
	}

	/**
	 * Bestimmt die Herkunft einer Buchung.
	 *
	 * Steht im Shortcode ein quelle="..."-Attribut, gilt das. Sonst nimmt das
	 * Plugin den Titel der Seite, auf der das Widget steht.
	 *
	 * @param string $quelle Wert aus dem Shortcode.
	 * @return string
	 */
	protected static function resolve_source( $quelle ) {
		$quelle = trim( (string) $quelle );

		if ( '' === $quelle ) {
			$titel = get_the_title();
			if ( is_string( $titel ) ) {
				$quelle = trim( wp_strip_all_tags( $titel ) );
			}
		}

		return mb_substr( sanitize_text_field( $quelle ), 0, 150 );
	}

	/**
	 * Auswahl mehrerer Termintypen.
	 *
	 * @param array  $events Termintypen.
	 * @param int    $days   Vorschau-Tage.
	 * @param string $quelle Herkunft aus dem Shortcode.
	 * @return string
	 */
	protected static function render_chooser( $events, $days, $quelle = '' ) {
		ob_start();
		?>
		<div class="ffb ffb-chooser">
			<div class="ffb-chooser__grid">
				<?php foreach ( $events as $event ) : ?>
					<button type="button" class="ffb-chooser__card" data-ffb-pick="<?php echo esc_attr( $event['slug'] ); ?>">
						<span class="ffb-chooser__title"><?php echo esc_html( $event['title'] ); ?></span>
						<span class="ffb-chooser__meta">
						<?php
						/* translators: %d: duration in minutes */
						echo esc_html( sprintf( _n( '%d minute', '%d minutes', (int) $event['duration'], 'fines-finest-booking' ), (int) $event['duration'] ) );
						?>
						</span>
						<?php if ( $event['description'] ) : ?>
							<span class="ffb-chooser__desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $event['description'] ), 24 ) ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="ffb-widget" data-ffb-widget data-event="" data-days="<?php echo (int) $days; ?>" data-quelle="<?php echo esc_attr( self::resolve_source( $quelle ) ); ?>" hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Buchungs-Widget.
	 *
	 * @param array  $event  Termintyp.
	 * @param int    $days   Vorschau-Tage.
	 * @param string $quelle Herkunft aus dem Shortcode.
	 * @return string
	 */
	protected static function render_widget( $event, $days, $quelle = '' ) {
		$notify = (string) ffb_get_setting( 'notify_email' );
		$link   = '<a href="mailto:' . esc_attr( $notify ) . '">' . esc_html( $notify ) . '</a>';

		ob_start();
		?>
		<div class="ffb">
			<div class="ffb-widget" data-ffb-widget data-event="<?php echo esc_attr( $event['slug'] ); ?>" data-days="<?php echo (int) max( 7, $days ); ?>" data-quelle="<?php echo esc_attr( self::resolve_source( $quelle ) ); ?>">
				<div class="ffb-loading"><?php echo esc_html__( 'Loading appointments …', 'fines-finest-booking' ); ?></div>
			</div>
			<noscript>
				<p class="ffb-note">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: email address as link */
						__( 'Booking an appointment needs JavaScript. Otherwise just send me an email at %s.', 'fines-finest-booking' ),
						$link
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
				</p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Storno-Ansicht.
	 *
	 * @param string $token Token aus dem Mail-Link.
	 * @return string
	 */
	public static function render_cancel( $token ) {
		$booking = FFB_Bookings::get_by_token( $token );

		if ( ! $booking ) {
			return '<div class="ffb ffb-note">' . esc_html__( 'There is no appointment for this link. Maybe it has already been deleted.', 'fines-finest-booking' ) . '</div>';
		}

		$event = FFB_Event_Types::get( $booking['event_type_id'] );
		$title = $event ? $event['title'] : __( 'Appointment', 'fines-finest-booking' );
		$when  = FFB_Mailer::format_datetime( $booking['start_utc'] );
		$past  = strtotime( $booking['start_utc'] . ' UTC' ) < time();

		ob_start();
		?>
		<div class="ffb">
			<div class="ffb-cancel" data-ffb-cancel data-token="<?php echo esc_attr( $booking['token'] ); ?>">
				<h2 class="ffb-cancel__title"><?php esc_html_e( 'Cancel appointment', 'fines-finest-booking' ); ?></h2>
				<p class="ffb-cancel__meta"><strong><?php echo esc_html( $title ); ?></strong><br><?php echo esc_html( $when ); ?></p>

				<?php if ( 'cancelled' === $booking['status'] ) : ?>
					<p class="ffb-note"><?php esc_html_e( 'This appointment has already been cancelled.', 'fines-finest-booking' ); ?></p>
				<?php elseif ( $past ) : ?>
					<p class="ffb-note"><?php esc_html_e( 'This appointment is in the past.', 'fines-finest-booking' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Do you want to cancel the appointment? The slot becomes available again.', 'fines-finest-booking' ); ?></p>
					<button type="button" class="ffb-btn ffb-btn--primary" data-ffb-cancel-confirm><?php esc_html_e( 'Yes, cancel appointment', 'fines-finest-booking' ); ?></button>
					<div class="ffb-message" data-ffb-cancel-message hidden></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
