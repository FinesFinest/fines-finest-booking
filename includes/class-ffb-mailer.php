<?php
/**
 * Mailversand: Bestaetigung, Benachrichtigung, Erinnerung, Storno.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Baut und verschickt die Mails inklusive ICS-Anhang.
 */
class FFB_Mailer {

	/**
	 * Standard-Header.
	 *
	 * @return array
	 */
	protected static function headers() {
		$from_name = ffb_get_setting( 'from_name', get_bloginfo( 'name' ) );

		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, ffb_from_email() ),
		);
	}

	/**
	 * Haengt an jede HTML-Mail eine Nur-Text-Fassung.
	 *
	 * Reines HTML ohne Textteil kostet bei Spamfiltern Punkte. Greift nur, wenn
	 * WordPress tatsaechlich ueber PHPMailer verschickt; ersetzt ein Mail-Plugin
	 * den Versand komplett, bleibt der Hook wirkungslos.
	 *
	 * @param PHPMailer $mailer Mailer-Objekt.
	 */
	public static function add_plain_text( $mailer ) {
		if ( ! is_object( $mailer ) || empty( $mailer->Body ) ) {
			return;
		}
		if ( ! empty( $mailer->AltBody ) ) {
			return;
		}
		if ( 'text/html' !== strtolower( (string) $mailer->ContentType ) ) {
			return;
		}

		$text = preg_replace( '#<(style|script)[^>]*>.*?</\1>#is', '', $mailer->Body );
		$text = preg_replace( '#<(br|/p|/tr|/h[1-6])[^>]*>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		$mailer->AltBody = trim( $text );
	}

	/**
	 * Formatiert einen UTC-Zeitpunkt in lesbarer deutscher Form.
	 *
	 * @param string $utc UTC-Zeit.
	 * @return string
	 */
	public static function format_datetime( $utc ) {
		$date = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		$date = $date->setTimezone( ffb_timezone() );

		$days   = array( 'Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag' );
		$months = array( '', 'Januar', 'Februar', 'Maerz', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );

		return sprintf(
			'%s, %d. %s %s, %s Uhr',
			$days[ (int) $date->format( 'w' ) ],
			(int) $date->format( 'j' ),
			$months[ (int) $date->format( 'n' ) ],
			$date->format( 'Y' ),
			$date->format( 'H:i' )
		);
	}

	/**
	 * Rahmen fuer alle HTML-Mails.
	 *
	 * @param string $title   Ueberschrift.
	 * @param string $content Inhalt.
	 * @return string
	 */
	protected static function wrap( $title, $content ) {
		$accent  = ffb_get_setting( 'color_accent' );
		$bg      = ffb_get_setting( 'color_bg_deep' );
		$surface = ffb_get_setting( 'color_bg' );
		$text    = ffb_get_setting( 'color_text' );
		$muted   = ffb_get_setting( 'color_muted' );
		$site    = get_bloginfo( 'name' );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $bg ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $bg ); ?>;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:<?php echo esc_attr( $surface ); ?>;border-radius:8px;overflow:hidden;">
<tr><td style="height:4px;background:<?php echo esc_attr( $accent ); ?>;"></td></tr>
<tr><td style="padding:32px 32px 8px;">
<h1 style="margin:0 0 16px;font-family:Helvetica,Arial,sans-serif;font-size:22px;line-height:1.3;color:<?php echo esc_attr( $text ); ?>;"><?php echo esc_html( $title ); ?></h1>
</td></tr>
<tr><td style="padding:0 32px 32px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:<?php echo esc_attr( $muted ); ?>;">
<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</td></tr>
<tr><td style="padding:16px 32px 24px;border-top:1px solid rgba(255,255,255,0.08);font-family:Helvetica,Arial,sans-serif;font-size:12px;color:<?php echo esc_attr( $muted ); ?>;">
<?php echo esc_html( $site ); ?> &middot; <a href="<?php echo esc_url( home_url() ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Baut einen Datenblock.
	 *
	 * @param array $rows Zeilen als Label => Wert.
	 * @return string
	 */
	protected static function detail_block( $rows ) {
		$text  = ffb_get_setting( 'color_text' );
		$muted = ffb_get_setting( 'color_muted' );
		$out   = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0 24px;">';

		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$out .= '<tr><td style="padding:6px 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:' . esc_attr( $muted ) . ';width:38%;vertical-align:top;">' . esc_html( $label ) . '</td>';
			$out .= '<td style="padding:6px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;color:' . esc_attr( $text ) . ';">' . wp_kses_post( $value ) . '</td></tr>';
		}

		return $out . '</table>';
	}

	/**
	 * Baut einen Button.
	 *
	 * @param string $url   Ziel.
	 * @param string $label Beschriftung.
	 * @return string
	 */
	protected static function button( $url, $label ) {
		$accent = ffb_get_setting( 'color_accent' );

		return '<p style="margin:0 0 20px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 26px;background:' . esc_attr( $accent ) . ';color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;text-decoration:none;border-radius:4px;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Storno-Link einer Buchung.
	 *
	 * @param array $booking Buchung.
	 * @return string
	 */
	public static function cancel_url( $booking ) {
		$base = ffb_get_setting( 'booking_page_url' );
		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg( 'ffb_cancel', $booking['token'], $base );
	}

	/**
	 * Erzeugt eine ICS-Datei und gibt den Pfad zurueck.
	 *
	 * @param array  $booking Buchung.
	 * @param array  $event   Termintyp.
	 * @param string $method  REQUEST oder CANCEL.
	 * @return string Pfad oder leerer String.
	 */
	protected static function build_ics( $booking, $event, $method = 'REQUEST' ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'ffb-tmp';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Verzeichnis vor direktem Zugriff schuetzen.
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n" ); // phpcs:ignore
		}

		$location = '';
		if ( ! empty( $booking['meet_url'] ) ) {
			$location = $booking['meet_url'];
		} elseif ( 'phone' === $event['location'] ) {
			$location = 'Telefon';
		} elseif ( $event['location_note'] ) {
			$location = $event['location_note'];
		}

		$description = wp_strip_all_tags( (string) $event['description'] );
		if ( ! empty( $booking['meet_url'] ) ) {
			$description .= "\\n\\nVideocall: " . $booking['meet_url'];
		}

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Fines Finest Booking//DE',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:ffb-' . $booking['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $booking['start_utc'] . ' UTC' ) ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $booking['end_utc'] . ' UTC' ) ),
			'SUMMARY:' . self::ics_escape( $event['title'] . ' mit ' . ffb_get_setting( 'from_name', get_bloginfo( 'name' ) ) ),
			'DESCRIPTION:' . self::ics_escape( $description ),
			'LOCATION:' . self::ics_escape( $location ),
			'ORGANIZER;CN=' . self::ics_escape( ffb_get_setting( 'from_name', get_bloginfo( 'name' ) ) ) . ':mailto:' . ffb_from_email(),
			'STATUS:' . ( 'CANCEL' === $method ? 'CANCELLED' : 'CONFIRMED' ),
			'SEQUENCE:' . ( 'CANCEL' === $method ? '1' : '0' ),
			'END:VEVENT',
			'END:VCALENDAR',
		);

		$file = $dir . '/termin-' . (int) $booking['id'] . '-' . substr( md5( $booking['token'] . $method ), 0, 8 ) . '.ics';

		if ( false === file_put_contents( $file, implode( "\r\n", $lines ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}

		return $file;
	}

	/**
	 * Escaped Text fuer ICS.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function ics_escape( $text ) {
		$text = str_replace( array( "\\", ';', ',' ), array( "\\\\", '\\;', '\\,' ), (string) $text );
		$text = str_replace( array( "\r\n", "\n", "\r" ), '\\n', $text );

		return $text;
	}

	/**
	 * Loescht eine temporaere ICS-Datei.
	 *
	 * @param string $file Pfad.
	 */
	protected static function cleanup( $file ) {
		if ( $file && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}

	/**
	 * Bestaetigung an die Interessentin.
	 *
	 * @param array $booking Buchung.
	 * @param array $event   Termintyp.
	 */
	public static function send_confirmation( $booking, $event ) {
		$when = self::format_datetime( $booking['start_utc'] );

		$rows = array(
			'Termin' => $event['title'],
			'Wann'   => $when . ' (' . ffb_timezone()->getName() . ')',
			'Dauer'  => (int) $event['duration'] . ' Minuten',
		);

		if ( ! empty( $booking['meet_url'] ) ) {
			$rows['Wo'] = '<a href="' . esc_url( $booking['meet_url'] ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">Google Meet</a>';
		} elseif ( 'phone' === $event['location'] ) {
			$rows['Wo'] = 'Ich rufe dich an' . ( $booking['phone'] ? ': ' . $booking['phone'] : '' );
		} elseif ( $event['location_note'] ) {
			$rows['Wo'] = $event['location_note'];
		}

		$content  = '<p>Hallo ' . esc_html( self::first_name( $booking['name'] ) ) . ',</p>';
		$content .= '<p>dein Termin steht. Hier die Eckdaten:</p>';
		$content .= self::detail_block( $rows );

		if ( ! empty( $booking['meet_url'] ) ) {
			$content .= self::button( $booking['meet_url'], 'Zum Videocall' );
		}

		$content .= '<p>Den Termin findest du auch im Anhang zum Eintragen in deinen Kalender.</p>';
		$content .= '<p>Wenn es doch nicht passt, sag rechtzeitig ab: <a href="' . esc_url( self::cancel_url( $booking ) ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">Termin absagen</a></p>';
		$content .= '<p>Bis dann.</p>';

		$ics = self::build_ics( $booking, $event, 'REQUEST' );

		wp_mail(
			$booking['email'],
			'Termin bestaetigt: ' . $event['title'] . ' am ' . $when,
			self::wrap( 'Termin bestaetigt', $content ),
			self::headers(),
			$ics ? array( $ics ) : array()
		);

		self::cleanup( $ics );
	}

	/**
	 * Benachrichtigung an die Betreiberin.
	 *
	 * @param array $booking Buchung.
	 * @param array $event   Termintyp.
	 */
	public static function send_notification( $booking, $event ) {
		$to = ffb_get_setting( 'notify_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}

		$when = self::format_datetime( $booking['start_utc'] );

		$rows = array(
			'Termin'  => $event['title'],
			'Wann'    => $when,
			'Name'    => $booking['name'],
			'E-Mail'  => '<a href="mailto:' . esc_attr( $booking['email'] ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">' . esc_html( $booking['email'] ) . '</a>',
			'Telefon' => $booking['phone'],
			'Quelle'  => isset( $booking['source'] ) ? $booking['source'] : '',
		);

		foreach ( (array) $booking['answers'] as $label => $value ) {
			$rows[ $label ] = nl2br( esc_html( $value ) );
		}

		if ( ! empty( $booking['meet_url'] ) ) {
			$rows['Meet'] = '<a href="' . esc_url( $booking['meet_url'] ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">' . esc_html( $booking['meet_url'] ) . '</a>';
		}

		$content  = '<p>Neue Buchung ueber die Website.</p>';
		$content .= self::detail_block( $rows );
		$content .= self::button( admin_url( 'admin.php?page=ffb-bookings' ), 'Buchungen ansehen' );

		wp_mail( $to, 'Neue Buchung: ' . $booking['name'] . ' am ' . $when, self::wrap( 'Neue Buchung', $content ), self::headers() );
	}

	/**
	 * Erinnerung 24 Stunden vorher.
	 *
	 * @param array $booking Buchung.
	 * @param array $event   Termintyp.
	 */
	public static function send_reminder( $booking, $event ) {
		$when = self::format_datetime( $booking['start_utc'] );

		$content  = '<p>Hallo ' . esc_html( self::first_name( $booking['name'] ) ) . ',</p>';
		$content .= '<p>kurze Erinnerung: Unser Termin ist morgen.</p>';
		$content .= self::detail_block(
			array(
				'Termin' => $event['title'],
				'Wann'   => $when . ' (' . ffb_timezone()->getName() . ')',
			)
		);

		if ( ! empty( $booking['meet_url'] ) ) {
			$content .= self::button( $booking['meet_url'], 'Zum Videocall' );
		}

		$content .= '<p>Passt es doch nicht? <a href="' . esc_url( self::cancel_url( $booking ) ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">Hier absagen</a></p>';

		wp_mail( $booking['email'], 'Erinnerung: ' . $event['title'] . ' morgen um ' . self::time_only( $booking['start_utc'] ) . ' Uhr', self::wrap( 'Morgen ist es soweit', $content ), self::headers() );
	}

	/**
	 * Stornobestaetigung.
	 *
	 * @param array $booking Buchung.
	 */
	public static function send_cancellation( $booking ) {
		$event = FFB_Event_Types::get( $booking['event_type_id'] );
		$title = $event ? $event['title'] : 'Termin';
		$when  = self::format_datetime( $booking['start_utc'] );

		$content  = '<p>Hallo ' . esc_html( self::first_name( $booking['name'] ) ) . ',</p>';
		$content .= '<p>dein Termin am ' . esc_html( $when ) . ' ist abgesagt. Der Slot ist wieder frei.</p>';
		$content .= '<p>Wenn du einen neuen Termin willst, buch dir einfach einen.</p>';

		$page = ffb_get_setting( 'booking_page_url' );
		if ( $page ) {
			$content .= self::button( $page, 'Neuen Termin buchen' );
		}

		wp_mail( $booking['email'], 'Termin abgesagt: ' . $title, self::wrap( 'Termin abgesagt', $content ), self::headers() );

		$to = ffb_get_setting( 'notify_email', get_option( 'admin_email' ) );
		if ( is_email( $to ) ) {
			wp_mail(
				$to,
				'Absage: ' . $booking['name'] . ' am ' . $when,
				self::wrap( 'Termin abgesagt', '<p>' . esc_html( $booking['name'] ) . ' (' . esc_html( $booking['email'] ) . ') hat den Termin am ' . esc_html( $when ) . ' abgesagt.</p>' ),
				self::headers()
			);
		}
	}

	/**
	 * Nur die Uhrzeit.
	 *
	 * @param string $utc UTC-Zeit.
	 * @return string
	 */
	protected static function time_only( $utc ) {
		$date = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );

		return $date->setTimezone( ffb_timezone() )->format( 'H:i' );
	}

	/**
	 * Vorname aus dem vollen Namen.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	protected static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );

		return $parts ? $parts[0] : $name;
	}
}
