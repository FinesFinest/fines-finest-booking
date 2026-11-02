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
	 * Formatiert einen UTC-Zeitpunkt lesbar in der Sprache und Zeitzone der Website.
	 *
	 * @param string $utc UTC-Zeit.
	 * @return string
	 */
	public static function format_datetime( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		/* translators: PHP date format for a full date with weekday and time, e.g. "Tuesday, March 3, 2026, 3:00 pm". See https://www.php.net/manual/datetime.format.php */
		return wp_date( __( 'l, F j, Y, g:i a', 'fines-finest-booking' ), $timestamp, ffb_timezone() );
	}

	/**
	 * Nur die Uhrzeit, in der Sprache und Zeitzone der Website.
	 *
	 * @param string $utc UTC-Zeit.
	 * @return string
	 */
	public static function time_only( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		/* translators: PHP date format for a time of day, e.g. "3:00 pm". See https://www.php.net/manual/datetime.format.php */
		return wp_date( __( 'g:i a', 'fines-finest-booking' ), $timestamp, ffb_timezone() );
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
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
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
	 * Textlink in der Zweitfarbe.
	 *
	 * @param string $url   Ziel.
	 * @param string $label Beschriftung.
	 * @return string
	 */
	protected static function link( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( ffb_get_setting( 'color_secondary' ) ) . ';">' . esc_html( $label ) . '</a>';
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
			$location = __( 'Phone', 'fines-finest-booking' );
		} elseif ( $event['location_note'] ) {
			$location = $event['location_note'];
		}

		$description = wp_strip_all_tags( (string) $event['description'] );
		if ( ! empty( $booking['meet_url'] ) ) {
			/* translators: %s: video call link */
			$description .= "\n\n" . sprintf( __( 'Video call: %s', 'fines-finest-booking' ), $booking['meet_url'] );
		}

		$from_name = ffb_get_setting( 'from_name', get_bloginfo( 'name' ) );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Fines Finest Booking//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:ffb-' . $booking['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $booking['start_utc'] . ' UTC' ) ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $booking['end_utc'] . ' UTC' ) ),
			/* translators: 1: appointment type, 2: name of the host */
			'SUMMARY:' . self::ics_escape( sprintf( __( '%1$s with %2$s', 'fines-finest-booking' ), $event['title'], $from_name ) ),
			'DESCRIPTION:' . self::ics_escape( $description ),
			'LOCATION:' . self::ics_escape( $location ),
			'ORGANIZER;CN=' . self::ics_escape( $from_name ) . ':mailto:' . ffb_from_email(),
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
	 * Bestaetigung an die Person, die gebucht hat.
	 *
	 * @param array $booking Buchung.
	 * @param array $event   Termintyp.
	 * @return bool Ob wp_mail() den Versand angenommen hat.
	 */
	public static function send_confirmation( $booking, $event ) {
		$when     = self::format_datetime( $booking['start_utc'] );
		$duration = (int) $event['duration'];

		$rows = array(
			__( 'Appointment', 'fines-finest-booking' ) => $event['title'],
			__( 'When', 'fines-finest-booking' )        => $when . ' (' . ffb_timezone()->getName() . ')',
			/* translators: %d: duration in minutes */
			__( 'Duration', 'fines-finest-booking' )    => sprintf( _n( '%d minute', '%d minutes', $duration, 'fines-finest-booking' ), $duration ),
		);

		if ( ! empty( $booking['meet_url'] ) ) {
			$rows[ __( 'Where', 'fines-finest-booking' ) ] = self::link( $booking['meet_url'], 'Google Meet' );
		} elseif ( 'phone' === $event['location'] ) {
			$rows[ __( 'Where', 'fines-finest-booking' ) ] = $booking['phone']
				/* translators: %s: phone number */
				? sprintf( __( 'I will call you: %s', 'fines-finest-booking' ), $booking['phone'] )
				: __( 'I will call you', 'fines-finest-booking' );
		} elseif ( $event['location_note'] ) {
			$rows[ __( 'Where', 'fines-finest-booking' ) ] = $event['location_note'];
		}

		/* translators: %s: first name */
		$content  = '<p>' . esc_html( sprintf( __( 'Hello %s,', 'fines-finest-booking' ), self::first_name( $booking['name'] ) ) ) . '</p>';
		$content .= '<p>' . esc_html__( 'Your appointment is booked. Here are the details:', 'fines-finest-booking' ) . '</p>';
		$content .= self::detail_block( $rows );

		if ( ! empty( $booking['meet_url'] ) ) {
			$content .= self::button( $booking['meet_url'], __( 'Join video call', 'fines-finest-booking' ) );
		}

		$content .= '<p>' . esc_html__( 'The appointment is attached as a calendar file so you can add it to your calendar.', 'fines-finest-booking' ) . '</p>';
		$content .= '<p>' . sprintf(
			/* translators: %s: link with the text "Cancel appointment" */
			esc_html__( 'If it no longer fits, please cancel in time: %s', 'fines-finest-booking' ),
			self::link( self::cancel_url( $booking ), __( 'Cancel appointment', 'fines-finest-booking' ) )
		) . '</p>';
		$content .= '<p>' . esc_html__( 'See you then.', 'fines-finest-booking' ) . '</p>';

		$ics = self::build_ics( $booking, $event, 'REQUEST' );

		$sent = wp_mail(
			$booking['email'],
			/* translators: 1: appointment type, 2: date and time */
			sprintf( __( 'Appointment confirmed: %1$s on %2$s', 'fines-finest-booking' ), $event['title'], $when ),
			self::wrap( __( 'Appointment confirmed', 'fines-finest-booking' ), $content ),
			self::headers(),
			$ics ? array( $ics ) : array()
		);

		self::cleanup( $ics );

		return (bool) $sent;
	}

	/**
	 * Benachrichtigung an die Person, die die Website betreibt.
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
			__( 'Appointment', 'fines-finest-booking' ) => $event['title'],
			__( 'When', 'fines-finest-booking' )        => $when,
			__( 'Name', 'fines-finest-booking' )        => $booking['name'],
			__( 'Email', 'fines-finest-booking' )       => self::link( 'mailto:' . $booking['email'], $booking['email'] ),
			__( 'Phone', 'fines-finest-booking' )       => $booking['phone'],
			__( 'Source', 'fines-finest-booking' )      => isset( $booking['source'] ) ? $booking['source'] : '',
		);

		foreach ( (array) $booking['answers'] as $label => $value ) {
			$rows[ $label ] = nl2br( esc_html( $value ) );
		}

		if ( ! empty( $booking['meet_url'] ) ) {
			$rows['Google Meet'] = self::link( $booking['meet_url'], $booking['meet_url'] );
		}

		$content  = '<p>' . esc_html__( 'New booking through the website.', 'fines-finest-booking' ) . '</p>';
		$content .= self::detail_block( $rows );
		$content .= self::button( admin_url( 'admin.php?page=ffb-bookings' ), __( 'View bookings', 'fines-finest-booking' ) );

		wp_mail(
			$to,
			/* translators: 1: name of the person who booked, 2: date and time */
			sprintf( __( 'New booking: %1$s on %2$s', 'fines-finest-booking' ), $booking['name'], $when ),
			self::wrap( __( 'New booking', 'fines-finest-booking' ), $content ),
			self::headers()
		);
	}

	/**
	 * Erinnerung am Tag vor dem Termin. Laeuft der Cron spaeter, auch am Tag selbst.
	 *
	 * @param array $booking Buchung.
	 * @param array $event   Termintyp.
	 */
	public static function send_reminder( $booking, $event ) {
		$when = self::format_datetime( $booking['start_utc'] );
		$time = self::time_only( $booking['start_utc'] );
		$day  = self::relative_day( $booking['start_utc'] );

		if ( 'today' === $day ) {
			$sentence = __( 'A quick reminder: our appointment is today.', 'fines-finest-booking' );
			$title    = __( 'Today is the day', 'fines-finest-booking' );
			/* translators: 1: appointment type, 2: time of day */
			$subject = sprintf( __( 'Reminder: %1$s today at %2$s', 'fines-finest-booking' ), $event['title'], $time );
		} elseif ( 'tomorrow' === $day ) {
			$sentence = __( 'A quick reminder: our appointment is tomorrow.', 'fines-finest-booking' );
			$title    = __( 'Tomorrow is the day', 'fines-finest-booking' );
			/* translators: 1: appointment type, 2: time of day */
			$subject = sprintf( __( 'Reminder: %1$s tomorrow at %2$s', 'fines-finest-booking' ), $event['title'], $time );
		} else {
			$sentence = __( 'A quick reminder of our appointment.', 'fines-finest-booking' );
			$title    = __( 'Your appointment is coming up', 'fines-finest-booking' );
			/* translators: 1: appointment type, 2: date and time */
			$subject = sprintf( __( 'Reminder: %1$s on %2$s', 'fines-finest-booking' ), $event['title'], $when );
		}

		/* translators: %s: first name */
		$content  = '<p>' . esc_html( sprintf( __( 'Hello %s,', 'fines-finest-booking' ), self::first_name( $booking['name'] ) ) ) . '</p>';
		$content .= '<p>' . esc_html( $sentence ) . '</p>';
		$content .= self::detail_block(
			array(
				__( 'Appointment', 'fines-finest-booking' ) => $event['title'],
				__( 'When', 'fines-finest-booking' )        => $when . ' (' . ffb_timezone()->getName() . ')',
			)
		);

		if ( ! empty( $booking['meet_url'] ) ) {
			$content .= self::button( $booking['meet_url'], __( 'Join video call', 'fines-finest-booking' ) );
		}

		$content .= '<p>' . sprintf(
			/* translators: %s: link with the text "Cancel here" */
			esc_html__( 'Does it no longer fit? %s', 'fines-finest-booking' ),
			self::link( self::cancel_url( $booking ), __( 'Cancel here', 'fines-finest-booking' ) )
		) . '</p>';

		wp_mail( $booking['email'], $subject, self::wrap( $title, $content ), self::headers() );
	}

	/**
	 * Liegt ein Zeitpunkt heute oder morgen? Gerechnet in der Zeitzone der Website.
	 *
	 * @param string   $utc UTC-Zeit.
	 * @param int|null $now Jetzt als Zeitstempel, nur fuer Tests.
	 * @return string 'today', 'tomorrow' oder leer.
	 */
	public static function relative_day( $utc, $now = null ) {
		$tz    = ffb_timezone();
		$day   = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$today = null === $now ? new DateTimeImmutable( 'now', $tz ) : ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );

		if ( $day === $today->format( 'Y-m-d' ) ) {
			return 'today';
		}
		if ( $day === $today->modify( '+1 day' )->format( 'Y-m-d' ) ) {
			return 'tomorrow';
		}

		return '';
	}

	/**
	 * Stornobestaetigung.
	 *
	 * @param array $booking Buchung.
	 */
	public static function send_cancellation( $booking ) {
		$event = FFB_Event_Types::get( $booking['event_type_id'] );
		$title = $event ? $event['title'] : __( 'Appointment', 'fines-finest-booking' );
		$when  = self::format_datetime( $booking['start_utc'] );

		/* translators: %s: first name */
		$content  = '<p>' . esc_html( sprintf( __( 'Hello %s,', 'fines-finest-booking' ), self::first_name( $booking['name'] ) ) ) . '</p>';
		/* translators: %s: date and time */
		$content .= '<p>' . esc_html( sprintf( __( 'Your appointment on %s is cancelled. The slot is available again.', 'fines-finest-booking' ), $when ) ) . '</p>';
		$content .= '<p>' . esc_html__( 'If you would like a new appointment, just book one.', 'fines-finest-booking' ) . '</p>';

		$page = ffb_get_setting( 'booking_page_url' );
		if ( $page ) {
			$content .= self::button( $page, __( 'Book a new appointment', 'fines-finest-booking' ) );
		}

		wp_mail(
			$booking['email'],
			/* translators: %s: appointment type */
			sprintf( __( 'Appointment cancelled: %s', 'fines-finest-booking' ), $title ),
			self::wrap( __( 'Appointment cancelled', 'fines-finest-booking' ), $content ),
			self::headers()
		);

		$to = ffb_get_setting( 'notify_email', get_option( 'admin_email' ) );
		if ( is_email( $to ) ) {
			wp_mail(
				$to,
				/* translators: 1: name of the person who booked, 2: date and time */
				sprintf( __( 'Cancellation: %1$s on %2$s', 'fines-finest-booking' ), $booking['name'], $when ),
				self::wrap(
					__( 'Appointment cancelled', 'fines-finest-booking' ),
					'<p>' . esc_html(
						sprintf(
							/* translators: 1: name, 2: email address, 3: date and time */
							__( '%1$s (%2$s) has cancelled the appointment on %3$s.', 'fines-finest-booking' ),
							$booking['name'],
							$booking['email'],
							$when
						)
					) . '</p>'
				),
				self::headers()
			);
		}
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
