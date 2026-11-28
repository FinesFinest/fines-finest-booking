<?php
/**
 * Einrichtungsassistent fuer die Google-Verbindung.
 *
 * Er fuehrt in acht Schritten durch die Google Cloud Console, haelt die einzutragenden
 * Werte zum Kopieren bereit und prueft nach den Eintraegen, ob Google sie annimmt.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ausgabe des Assistenten auf der Einstellungsseite.
 */
class FFBooking_Google_Wizard {

	/**
	 * Link zu einer Aktion der Einstellungsseite.
	 *
	 * @param string $do Name der Aktion.
	 * @return string
	 */
	public static function action_url( $do ) {
		return wp_nonce_url( admin_url( 'admin.php?page=ffbooking-settings&ffbooking_do=' . $do ), 'ffbooking_' . $do );
	}

	/**
	 * Hinweis ueber ein Problem der Verbindung, das nur der Mensch beheben kann.
	 */
	public static function render_problem() {
		$problem = FFBooking_Google_Check::problem();
		if ( ! $problem || ! FFBooking_Google::is_connected() ) {
			return;
		}
		?>
		<div class="notice notice-warning inline ffb-problem">
			<p><strong><?php esc_html_e( 'Google Calendar needs your attention.', 'fines-finest-booking' ); ?></strong> <?php echo esc_html( $problem['text'] ); ?>
			<?php if ( $problem['link'] ) : ?>
				<a href="<?php echo esc_url( $problem['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $problem['link_text'] ); ?></a>
			<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Eine Zeile zum Kopieren.
	 *
	 * @param string $id    Eindeutige Kennung.
	 * @param string $label Beschriftung.
	 * @param string $value Wert.
	 */
	protected static function copy_row( $id, $label, $value ) {
		?>
		<p class="ffb-copyrow">
			<span class="ffb-copyrow__label"><?php echo esc_html( $label ); ?></span>
			<code id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $value ); ?></code>
			<button type="button" class="button button-small ffb-copy" data-ffb-copy="#<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'fines-finest-booking' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Knopf, der einen Google-Link in einem neuen Tab oeffnet.
	 *
	 * @param string $url   Adresse.
	 * @param string $label Beschriftung.
	 */
	protected static function open_button( $url, $label ) {
		?>
		<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
		<?php
	}

	/**
	 * Eine Ergebniszeile einer Pruefung.
	 *
	 * @param array $item Pruefpunkt.
	 */
	protected static function render_item( $item ) {
		$klasse = array(
			'ok'      => 'is-ok',
			'error'   => 'is-error',
			'unknown' => 'is-unknown',
		);
		$marke  = array(
			'ok'      => __( 'OK', 'fines-finest-booking' ),
			'error'   => __( 'Problem', 'fines-finest-booking' ),
			'unknown' => __( 'Unclear', 'fines-finest-booking' ),
		);
		$status = isset( $klasse[ $item['status'] ] ) ? $item['status'] : 'unknown';
		?>
		<li class="<?php echo esc_attr( $klasse[ $status ] ); ?>">
			<strong><?php echo esc_html( $marke[ $status ] ); ?>:</strong>
			<?php echo esc_html( $item['text'] ); ?>
			<?php if ( ! empty( $item['link'] ) ) : ?>
				<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['link_text'] ); ?></a>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Beginnt einen Schritt des Assistenten.
	 *
	 * @param int    $nr    Nummer.
	 * @param string $titel Ueberschrift.
	 * @param string $state done, open, problem oder leer, wenn Google nichts ueber den Schritt verraet.
	 */
	protected static function step_open( $nr, $titel, $state ) {
		$klasse = '' === $state ? 'is-manual' : 'is-' . $state;
		?>
		<li class="ffb-step <?php echo esc_attr( $klasse ); ?>">
			<span class="ffb-step__nr" aria-hidden="true"><?php echo esc_html( (string) $nr ); ?></span>
			<div class="ffb-step__body">
				<h4><?php echo esc_html( $titel ); ?></h4>
		<?php
	}

	/**
	 * Beendet einen Schritt.
	 */
	protected static function step_close() {
		?>
			</div>
		</li>
		<?php
	}

	/**
	 * Zeigt ein Pruefergebnis als Liste mit Zeitangabe.
	 *
	 * @param array $result Ergebnis aus FFBooking_Google_Check.
	 */
	protected static function render_result( $result ) {
		?>
		<ul class="ffb-checks">
			<?php
			foreach ( $result['items'] as $punkt ) {
				self::render_item( $punkt );
			}
			?>
		</ul>
		<p class="description">
		<?php
		/* translators: %s: date and time of the last check */
		echo esc_html( sprintf( __( 'Checked on %s.', 'fines-finest-booking' ), wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $result['time'] ) ) );
		?>
		</p>
		<?php
	}

	/**
	 * Gibt den Assistenten aus.
	 *
	 * @param bool $connected Ob Google schon verbunden ist.
	 */
	public static function render( $connected ) {
		$client_id = (string) ffbooking_get_setting( 'google_client_id' );
		$secret    = (string) ffbooking_get_setting( 'google_client_secret' );
		$format    = FFBooking_Google_Check::format_check( $client_id, $secret );
		$result    = FFBooking_Google_Check::last_result();
		$problem   = (bool) FFBooking_Google_Check::problem();
		$auth_url  = FFBooking_Google::auth_url();
		$verified  = FFBooking_Google_Check::credentials_verified();
		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$mail      = (string) get_option( 'admin_email' );
		$pruefung  = $result && 'credentials' === $result['mode'] ? $result : null;
		$verbindg  = $result && 'connection' === $result['mode'] ? $result : null;
		$offen     = ! $connected || $problem || $result;

		$state5 = 'empty' === $format['state'] ? 'open' : ( 'ok' === $format['state'] ? 'done' : 'problem' );
		$state6 = '';
		if ( $pruefung ) {
			$state6 = $verified ? 'done' : 'problem';
		} elseif ( 'ok' === $format['state'] ) {
			$state6 = 'open';
		}
		$state8 = $connected ? ( $problem ? 'problem' : 'done' ) : ( $auth_url ? 'open' : '' );
		?>
		<details class="ffb-wizard" id="ffb-wizard" <?php echo $offen ? 'open' : ''; ?>>
			<summary><?php echo $connected ? esc_html__( 'Google setup assistant and connection check', 'fines-finest-booking' ) : esc_html__( 'Google setup assistant', 'fines-finest-booking' ); ?></summary>

			<?php if ( $connected ) : ?>
				<div class="ffb-connection">
					<p><a class="button" href="<?php echo esc_url( self::action_url( 'check_google' ) ); ?>"><?php esc_html_e( 'Check connection', 'fines-finest-booking' ); ?></a>
					<span class="description"><?php esc_html_e( 'Tests the access to your calendar without changing anything.', 'fines-finest-booking' ); ?></span></p>
					<?php
					if ( $verbindg ) {
						self::render_result( $verbindg );
					}
					?>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'The plugin talks to your Google Calendar through your own free Google project. You set it up once, it takes about ten minutes, and nothing runs through the servers of the plugin author. Work through the steps from top to bottom.', 'fines-finest-booking' ); ?></p>
			<ol class="ffb-steps">
				<?php self::step_open( 1, __( 'Create a Google project', 'fines-finest-booking' ), '' ); ?>
				<p><?php esc_html_e( 'Sign in with the Google account whose calendar should receive the appointments. Give the project a name such as "Booking" and click Create.', 'fines-finest-booking' ); ?></p>
				<p><?php self::open_button( FFBooking_Google_Check::URL_PROJECT, __( 'Create project', 'fines-finest-booking' ) ); ?></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 2, __( 'Switch on the Calendar API', 'fines-finest-booking' ), '' ); ?>
				<p><?php esc_html_e( 'Make sure your new project is selected at the top, then click Enable.', 'fines-finest-booking' ); ?></p>
				<p><?php self::open_button( FFBooking_Google_Check::URL_API, __( 'Open Google Calendar API', 'fines-finest-booking' ) ); ?></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 3, __( 'Set up the consent screen', 'fines-finest-booking' ), '' ); ?>
				<p><?php esc_html_e( 'Open the Google Auth Platform and click Get started. Choose External as the audience and enter these values.', 'fines-finest-booking' ); ?></p>
				<?php
				self::copy_row( 'ffb-copy-appname', __( 'App name', 'fines-finest-booking' ), $site_name );
				self::copy_row( 'ffb-copy-mail', __( 'Support and contact email', 'fines-finest-booking' ), $mail );
				?>
				<p><?php self::open_button( FFBooking_Google_Check::URL_PLATFORM, __( 'Open Google Auth Platform', 'fines-finest-booking' ) ); ?></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 4, __( 'Create the client', 'fines-finest-booking' ), '' ); ?>
				<p><?php esc_html_e( 'Create a client of the type Web application. Leave the authorised JavaScript origins empty. Under authorised redirect URIs, add this address exactly as shown:', 'fines-finest-booking' ); ?></p>
				<?php self::copy_row( 'ffb-copy-redirect', __( 'Redirect URI', 'fines-finest-booking' ), FFBooking_Google::redirect_uri() ); ?>
				<p><?php esc_html_e( 'After you click Create, Google shows the client ID and the client secret. Copy both.', 'fines-finest-booking' ); ?></p>
				<p><?php self::open_button( FFBooking_Google_Check::URL_CLIENT, __( 'Create client', 'fines-finest-booking' ) ); ?></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 5, __( 'Enter client ID and client secret', 'fines-finest-booking' ), $state5 ); ?>
				<?php if ( 'problem' === $format['state'] ) : ?>
					<ul class="ffb-checks">
						<?php foreach ( $format['problems'] as $meldung ) : ?>
							<li class="is-error"><strong><?php esc_html_e( 'Problem', 'fines-finest-booking' ); ?>:</strong> <?php echo esc_html( $meldung ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( 'ok' === $format['state'] ) : ?>
					<p><?php esc_html_e( 'Both values are entered and have the right shape.', 'fines-finest-booking' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Enter the client ID and the client secret in the two fields below and click Save.', 'fines-finest-booking' ); ?></p>
				<?php endif; ?>
				<p><a href="#ffb-cid"><?php esc_html_e( 'Go to the fields', 'fines-finest-booking' ); ?></a></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 6, __( 'Let the plugin check your entries', 'fines-finest-booking' ), $state6 ); ?>
				<p><?php esc_html_e( 'The plugin asks Google whether it accepts your entries and the redirect URI. Nobody has to sign in for that.', 'fines-finest-booking' ); ?></p>
				<?php if ( 'ok' === $format['state'] ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( self::action_url( 'check_google' ) ); ?>"><?php esc_html_e( 'Check with Google', 'fines-finest-booking' ); ?></a></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Available as soon as step 5 is done.', 'fines-finest-booking' ); ?></p>
				<?php endif; ?>
				<?php
				if ( $pruefung ) {
					self::render_result( $pruefung );
				}
				?>
				<?php self::step_close(); ?>

				<?php self::step_open( 7, __( 'Publish the app', 'fines-finest-booking' ), '' ); ?>
				<p><?php esc_html_e( 'Under Audience, click Publish app and confirm. Otherwise Google ends the connection after seven days. Google needs no review as long as only your own account uses the app.', 'fines-finest-booking' ); ?></p>
				<p><?php self::open_button( FFBooking_Google_Check::URL_AUDIENCE, __( 'Open Audience', 'fines-finest-booking' ) ); ?></p>
				<p class="description"><?php esc_html_e( 'The plugin cannot see this step. If Google ends the connection later, it tells you here.', 'fines-finest-booking' ); ?></p>
				<?php self::step_close(); ?>

				<?php self::step_open( 8, __( 'Connect', 'fines-finest-booking' ), $state8 ); ?>
				<p><?php esc_html_e( 'Google shows a warning that the app is not verified. Click Advanced, then continue. On the next screen, leave every checkbox for the calendar ticked.', 'fines-finest-booking' ); ?></p>
				<?php if ( $connected ) : ?>
					<p><?php esc_html_e( 'Connected.', 'fines-finest-booking' ); ?></p>
				<?php elseif ( $auth_url ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>"><?php esc_html_e( 'Connect with Google', 'fines-finest-booking' ); ?></a></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Available as soon as step 5 is done.', 'fines-finest-booking' ); ?></p>
				<?php endif; ?>
				<?php self::step_close(); ?>
			</ol>
		</details>
		<?php
	}
}
