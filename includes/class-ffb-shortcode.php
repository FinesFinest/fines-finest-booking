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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_standalone_cancel' ) );
	}

	/**
	 * Faengt den Absage-Link ab, wenn die Zielseite ihren Inhalt nicht ausliefert.
	 *
	 * Das passiert zum Beispiel bei passwortgeschuetzten Seiten: Dort wird der
	 * Shortcode nie gerendert, die Absage-Ansicht waere also nicht erreichbar.
	 * In dem Fall liefern wir eine eigenstaendige, schlanke Seite aus.
	 */
	public static function maybe_standalone_cancel() {
		if ( is_admin() ) {
			return;
		}

		$token = isset( $_GET['ffb_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['ffb_cancel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $token ) {
			return;
		}

		// Liefert die Seite ihren Inhalt normal aus, uebernimmt der Shortcode.
		if ( ! post_password_required() ) {
			return;
		}

		$booking = FFB_Bookings::get_by_token( $token );
		if ( ! $booking ) {
			return;
		}

		nocache_headers();
		status_header( 200 );

		$config = array(
			'root'        => esc_url_raw( rest_url( FFB_Rest::NS ) ),
			'consentText' => ffb_get_setting( 'consent_text' ),
			'privacyUrl'  => ffb_get_setting( 'privacy_url' ),
		);

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( 'Termin absagen - ' . get_bloginfo( 'name' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( FFB_URL . 'assets/ffb-frontend.css?ver=' . FFB_VERSION ); ?>">
	<style>
		body { margin: 0; padding: 3rem 1.25rem; background: <?php echo esc_attr( ffb_get_setting( 'color_bg_deep' ) ); ?>; }
		.ffb { max-width: 560px; }
	</style>
</head>
<body>
	<?php echo self::render_cancel( $token ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<script>window.FFB_CONFIG = <?php echo wp_json_encode( $config ); ?>;</script>
	<script src="<?php echo esc_url( FFB_URL . 'assets/ffb-frontend.js?ver=' . FFB_VERSION ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Assets registrieren.
	 */
	public static function register_assets() {
		wp_register_style( 'ffb-frontend', FFB_URL . 'assets/ffb-frontend.css', array(), FFB_VERSION );
		wp_register_script( 'ffb-frontend', FFB_URL . 'assets/ffb-frontend.js', array(), FFB_VERSION, true );

		// Steht der Shortcode im Beitragsinhalt, laden wir frueh. In Oxygen-Codebloecken
		// greift stattdessen der Aufruf beim Rendern weiter unten.
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'ff_booking' ) ) {
			wp_enqueue_style( 'ffb-frontend' );
			wp_enqueue_script( 'ffb-frontend' );
			self::attach_data();
		}
	}

	/**
	 * Haengt Farben und Konfiguration an die Assets. Laeuft nur einmal.
	 */
	protected static function attach_data() {
		if ( self::$rendered ) {
			return;
		}

		wp_add_inline_style( 'ffb-frontend', self::inline_vars() );
		wp_localize_script(
			'ffb-frontend',
			'FFB_CONFIG',
			array(
				'root'        => esc_url_raw( rest_url( FFB_Rest::NS ) ),
				'consentText' => ffb_get_setting( 'consent_text' ),
				'privacyUrl'  => ffb_get_setting( 'privacy_url' ),
			)
		);

		self::$rendered = true;
	}

	/**
	 * Inline-CSS mit den Farbwerten aus den Einstellungen.
	 *
	 * @return string
	 */
	protected static function inline_vars() {
		$map = array(
			'--ffb-accent'      => 'color_accent',
			'--ffb-accent-soft' => 'color_accent_soft',
			'--ffb-secondary'   => 'color_secondary',
			'--ffb-success'     => 'color_success',
			'--ffb-bg'          => 'color_bg',
			'--ffb-bg-deep'     => 'color_bg_deep',
			'--ffb-surface'     => 'color_surface',
			'--ffb-text'        => 'color_text',
			'--ffb-muted'       => 'color_muted',
			'--ffb-font-display' => 'font_display',
			'--ffb-font-body'   => 'font_body',
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

		wp_enqueue_style( 'ffb-frontend' );
		wp_enqueue_script( 'ffb-frontend' );

		self::attach_data();

		// Storno-Ansicht, wenn der Mail-Link aufgerufen wurde.
		$cancel_token = isset( $_GET['ffb_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['ffb_cancel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $cancel_token ) {
			return self::render_cancel( $cancel_token );
		}

		$slug = sanitize_title( $atts['event'] );
		if ( ! $slug ) {
			$active = FFB_Event_Types::all( true );
			if ( empty( $active ) ) {
				return '<div class="ffb ffb-note">Es ist gerade kein Termintyp buchbar.</div>';
			}
			if ( count( $active ) === 1 ) {
				$slug = $active[0]['slug'];
			} else {
				return self::render_chooser( $active, (int) $atts['days'], $atts['quelle'] );
			}
		}

		$event = FFB_Event_Types::get_by_slug( $slug );
		if ( ! $event || ! $event['active'] ) {
			return '<div class="ffb ffb-note">Dieser Termintyp ist gerade nicht buchbar.</div>';
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
	 * @param array $events Termintypen.
	 * @param int   $days   Vorschau-Tage.
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
						<span class="ffb-chooser__meta"><?php echo (int) $event['duration']; ?> Minuten</span>
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
	 * @param array $event Termintyp.
	 * @param int   $days  Vorschau-Tage.
	 * @return string
	 */
	protected static function render_widget( $event, $days, $quelle = '' ) {
		ob_start();
		?>
		<div class="ffb">
			<div class="ffb-widget" data-ffb-widget data-event="<?php echo esc_attr( $event['slug'] ); ?>" data-days="<?php echo (int) max( 7, $days ); ?>" data-quelle="<?php echo esc_attr( self::resolve_source( $quelle ) ); ?>">
				<div class="ffb-loading">Termine werden geladen &hellip;</div>
			</div>
			<noscript>
				<p class="ffb-note">Fuer die Terminbuchung brauchst du JavaScript. Schreib mir sonst einfach eine Mail an
					<a href="mailto:<?php echo esc_attr( ffb_get_setting( 'notify_email' ) ); ?>"><?php echo esc_html( ffb_get_setting( 'notify_email' ) ); ?></a>.</p>
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
			return '<div class="ffb ffb-note">Zu diesem Link gibt es keinen Termin. Vielleicht wurde er schon geloescht.</div>';
		}

		$event = FFB_Event_Types::get( $booking['event_type_id'] );
		$title = $event ? $event['title'] : 'Termin';
		$when  = FFB_Mailer::format_datetime( $booking['start_utc'] );
		$past  = strtotime( $booking['start_utc'] . ' UTC' ) < time();

		ob_start();
		?>
		<div class="ffb">
			<div class="ffb-cancel" data-ffb-cancel data-token="<?php echo esc_attr( $booking['token'] ); ?>">
				<h2 class="ffb-cancel__title">Termin absagen</h2>
				<p class="ffb-cancel__meta"><strong><?php echo esc_html( $title ); ?></strong><br><?php echo esc_html( $when ); ?></p>

				<?php if ( 'cancelled' === $booking['status'] ) : ?>
					<p class="ffb-note">Dieser Termin ist bereits abgesagt.</p>
				<?php elseif ( $past ) : ?>
					<p class="ffb-note">Dieser Termin liegt in der Vergangenheit.</p>
				<?php else : ?>
					<p>Soll ich den Termin absagen? Der Slot wird danach wieder frei.</p>
					<button type="button" class="ffb-btn ffb-btn--primary" data-ffb-cancel-confirm>Ja, Termin absagen</button>
					<div class="ffb-message" data-ffb-cancel-message hidden></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
