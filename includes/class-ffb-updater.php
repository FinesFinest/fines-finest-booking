<?php
/**
 * Updates ueber GitHub-Releases.
 *
 * WordPress fragt fuer Plugins mit "Update URI" im Kopf nicht das Verzeichnis,
 * sondern den Filter update_plugins_{host}. Hier liefern wir das neueste
 * Release aus dem GitHub-Repository; den Rest (Herunterladen, Entpacken,
 * automatische Aktualisierung) macht WordPress wie bei jedem anderen Plugin.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verbindet die WordPress-Updateprüfung mit den GitHub-Releases.
 */
class FFB_Updater {

	const REPO       = 'FinesFinest/fines-finest-booking';
	const ASSET      = 'fines-finest-booking.zip';
	const CACHE      = 'ffb_github_release';
	const CACHE_TIME = 6 * HOUR_IN_SECONDS;

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'admin_init', array( __CLASS__, 'enable_auto_update_once' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_clear_cache' ) );
	}

	/**
	 * Plugin-Basename, etwa fines-finest-booking/fines-finest-booking.php.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( FFB_FILE );
	}

	/**
	 * Adresse des Repositories.
	 *
	 * @return string
	 */
	public static function repo_url() {
		return 'https://github.com/' . self::REPO;
	}

	/**
	 * Liefert WordPress die Daten des neuesten Releases.
	 *
	 * @param array|false $update      Bisheriges Ergebnis.
	 * @param array       $plugin_data Kopfdaten des Plugins.
	 * @param string      $plugin_file Basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( is_wp_error( $release ) ) {
			return $update;
		}

		// WordPress vergleicht die Versionen selbst und sortiert in "Update" oder "kein Update".
		return array(
			'id'           => self::repo_url(),
			'slug'         => 'fines-finest-booking',
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * Fuellt das Fenster "Details anzeigen" in der Pluginliste.
	 *
	 * @param false|object|array $result Bisheriges Ergebnis.
	 * @param string             $action Aktion.
	 * @param object             $args   Argumente mit slug.
	 * @return false|object
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'fines-finest-booking' !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Fines Finest Booking';
		$info->slug          = 'fines-finest-booking';
		$info->version       = $release['version'];
		$info->author        = '<a href="https://fines-finest.com">Fines Finest</a>';
		$info->homepage      = self::repo_url();
		$info->requires      = '6.0';
		$info->requires_php  = '7.4';
		$info->last_updated  = $release['published'];
		$info->download_link = $release['package'];
		$info->sections      = array(
			'changelog' => wpautop( esc_html( $release['notes'] ) ),
		);

		return $info;
	}

	/**
	 * Das neueste Release aus GitHub, sechs Stunden zwischengespeichert.
	 *
	 * @param bool $force Cache uebergehen.
	 * @return array|WP_Error version, package, url, notes, published.
	 */
	public static function latest_release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'FinesFinestBooking/' . FFB_VERSION . ' (' . home_url() . ')',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['tag_name'] ) ) {
			$message = isset( $body['message'] ) ? $body['message'] : (string) $code;
			/* translators: %s: error message or HTTP status from GitHub */
			$error = new WP_Error( 'ffb_github_http', sprintf( __( 'GitHub did not deliver a release: %s', 'fines-finest-booking' ), $message ) );
			self::log( $error->get_error_message() );
			return $error;
		}

		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $package ) {
			/* translators: 1: release tag, 2: expected file name */
			$error = new WP_Error( 'ffb_github_asset', sprintf( __( 'Release %1$s has no %2$s attached.', 'fines-finest-booking' ), $body['tag_name'], self::ASSET ) );
			self::log( $error->get_error_message() );
			return $error;
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $body['html_url'] ) ? $body['html_url'] : self::repo_url(),
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_site_transient( self::CACHE, $release, self::CACHE_TIME );

		return $release;
	}

	/**
	 * Sorgt dafuer, dass das entpackte Paket "fines-finest-booking" heisst.
	 *
	 * Unser Release-ZIP ist schon richtig benannt. Laedt jemand stattdessen das
	 * Quell-ZIP von GitHub hoch, heisst der Ordner "fines-finest-booking-1.2.0"
	 * und WordPress wuerde das Plugin daneben installieren statt es zu ersetzen.
	 *
	 * @param string      $source        Entpackter Ordner.
	 * @param string      $remote_source Oberordner.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Kontext.
	 * @return string|WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . 'fines-finest-booking/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted ) ) {
			return $wanted;
		}

		return $source;
	}

	/**
	 * Schaltet die automatische Aktualisierung einmalig ein. Abschalten geht
	 * danach wie bei jedem Plugin in der Pluginliste.
	 */
	public static function enable_auto_update_once() {
		if ( get_option( 'ffb_auto_update_enabled' ) ) {
			return;
		}

		$list = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( self::basename(), $list, true ) ) {
			$list[] = self::basename();
			update_site_option( 'auto_update_plugins', array_values( array_unique( $list ) ) );
		}

		update_option( 'ffb_auto_update_enabled', 1, true );
	}

	/**
	 * Ist die automatische Aktualisierung fuer dieses Plugin an?
	 *
	 * @return bool
	 */
	public static function auto_update_active() {
		return in_array( self::basename(), (array) get_site_option( 'auto_update_plugins', array() ), true );
	}

	/**
	 * "Erneut pruefen" auf der Update-Seite soll auch unseren Cache leeren.
	 */
	public static function maybe_clear_cache() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_site_transient( self::CACHE );
		}
	}

	/**
	 * Schreibt ins Fehlerprotokoll des Plugins.
	 *
	 * @param string $message Meldung.
	 */
	protected static function log( $message ) {
		FFB_Google::log( 'GitHub: ' . $message );
	}
}
