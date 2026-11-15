<?php
/**
 * CSS-sichere Werte aus den Einstellungen.
 *
 * Farben und Schriften landen in Stilregeln und Mails. Darum kommt nur an, was in
 * einen Farb- oder Schriftwert gehoert. Alles andere faellt weg, schon beim Speichern
 * und noch einmal beim Ausgeben.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prueft Farben und Schriftlisten.
 */
class FFBooking_Css {

	/**
	 * Mehr als so viele Schriften in einer Liste braucht niemand.
	 */
	const MAX_FAMILIES = 8;

	/**
	 * Schriftliste fuer font-family.
	 *
	 * Erlaubt sind Buchstaben, Ziffern, Leerzeichen, Bindestrich und Unterstrich in jedem
	 * Schriftnamen, dazu ein einzelnes var(--name) fuer eine CSS-Variable des Themes. Namen mit
	 * Leerzeichen oder einer Ziffer am Anfang bekommen Anfuehrungszeichen. Bleibt nichts
	 * Brauchbares uebrig, gilt inherit.
	 *
	 * @param mixed $value Eingabe aus den Einstellungen.
	 * @return string
	 */
	public static function font( $value ) {
		$value = trim( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 200, 'UTF-8' );
		} else {
			$value = substr( $value, 0, 200 );
		}

		if ( '' === $value || 0 === strcasecmp( $value, 'inherit' ) ) {
			return 'inherit';
		}

		$families = array();

		foreach ( explode( ',', $value ) as $part ) {
			if ( preg_match( '/^\s*var\(\s*(--[A-Za-z0-9_-]+)\s*\)\s*$/', $part, $match ) ) {
				$families[] = 'var(' . $match[1] . ')';
				continue;
			}

			$name = preg_replace( '/[^\p{L}\p{N} _-]+/u', '', $part );
			if ( ! is_string( $name ) ) {
				continue; // Kein gueltiges UTF-8.
			}

			$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
			if ( '' === $name ) {
				continue;
			}

			if ( false !== strpos( $name, ' ' ) || preg_match( '/^\d/', $name ) ) {
				$name = '"' . $name . '"';
			}

			$families[] = $name;

			if ( count( $families ) >= self::MAX_FAMILIES ) {
				break;
			}
		}

		return $families ? implode( ', ', $families ) : 'inherit';
	}

	/**
	 * Farbe als Hex-Wert.
	 *
	 * @param mixed  $value    Eingabe aus den Einstellungen.
	 * @param string $fallback Ersatz, wenn die Eingabe keine Hex-Farbe ist.
	 * @return string
	 */
	public static function color( $value, $fallback ) {
		$color = sanitize_hex_color( (string) $value );

		return $color ? $color : $fallback;
	}
}
