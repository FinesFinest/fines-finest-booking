<?php
/**
 * Termintypen: lesen, speichern, loeschen.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datenzugriff fuer Termintypen.
 */
class FFB_Event_Types {

	/**
	 * Alle Termintypen.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function all( $only_active = false ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();

		if ( $only_active ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		}

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Einen Termintyp per ID holen.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Einen Termintyp per Slug holen.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Wandelt JSON-Spalten in Arrays.
	 *
	 * @param array $row Datenbankzeile.
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$row['availability'] = self::normalize_availability( json_decode( (string) $row['availability'], true ) );
		$row['blackouts']    = array_values( array_filter( (array) json_decode( (string) $row['blackouts'], true ) ) );
		$row['questions']    = self::normalize_questions( json_decode( (string) $row['questions'], true ) );

		foreach ( array( 'id', 'duration', 'slot_step', 'buffer_before', 'buffer_after', 'min_notice', 'max_days', 'daily_limit', 'active', 'sort_order' ) as $int_key ) {
			$row[ $int_key ] = (int) $row[ $int_key ];
		}

		return $row;
	}

	/**
	 * Bringt die Wochenverfuegbarkeit in eine feste Form.
	 *
	 * @param mixed $raw Rohwert.
	 * @return array
	 */
	public static function normalize_availability( $raw ) {
		$out = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			$key      = (string) $day;
			$windows  = array();
			$provided = isset( $raw[ $key ] ) ? (array) $raw[ $key ] : array();

			foreach ( $provided as $window ) {
				if ( ! is_array( $window ) || count( $window ) < 2 ) {
					continue;
				}
				$start = self::clean_time( $window[0] );
				$end   = self::clean_time( $window[1] );
				if ( '' === $start || '' === $end || $start >= $end ) {
					continue;
				}
				$windows[] = array( $start, $end );
			}

			usort(
				$windows,
				static function ( $a, $b ) {
					return strcmp( $a[0], $b[0] );
				}
			);

			$out[ $key ] = $windows;
		}

		return $out;
	}

	/**
	 * Prueft ein HH:MM-Format.
	 *
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function clean_time( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/**
	 * Bringt die Zusatzfragen in eine feste Form.
	 *
	 * @param mixed $raw Rohwert.
	 * @return array
	 */
	public static function normalize_questions( $raw ) {
		$out   = array();
		$index = 0;

		foreach ( (array) $raw as $question ) {
			if ( empty( $question['label'] ) ) {
				continue;
			}
			$index++;
			$type = isset( $question['type'] ) ? $question['type'] : 'text';
			if ( ! in_array( $type, array( 'text', 'textarea', 'tel', 'url' ), true ) ) {
				$type = 'text';
			}
			$key = isset( $question['key'] ) && $question['key'] ? sanitize_key( $question['key'] ) : 'frage_' . $index;

			$out[] = array(
				'key'      => $key,
				'label'    => sanitize_text_field( $question['label'] ),
				'type'     => $type,
				'required' => ! empty( $question['required'] ) ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * Legt einen Termintyp an oder aktualisiert ihn.
	 *
	 * @param array $data Daten.
	 * @param int   $id   ID, 0 fuer neu.
	 * @return int|WP_Error
	 */
	public static function save( $data, $id = 0 ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();

		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'ffb_no_title', __( 'The appointment type needs a title.', 'fines-finest-booking' ) );
		}

		$slug = isset( $data['slug'] ) && $data['slug'] ? sanitize_title( $data['slug'] ) : sanitize_title( $title );
		$slug = self::unique_slug( $slug, (int) $id );

		$location = isset( $data['location'] ) ? sanitize_key( $data['location'] ) : 'meet';
		if ( ! in_array( $location, array( 'meet', 'phone', 'inperson', 'custom' ), true ) ) {
			$location = 'meet';
		}

		$fields = array(
			'slug'           => $slug,
			'title'          => $title,
			'description'    => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'duration'       => max( 5, (int) ( $data['duration'] ?? 30 ) ),
			'slot_step'      => max( 5, (int) ( $data['slot_step'] ?? 30 ) ),
			'buffer_before'  => max( 0, (int) ( $data['buffer_before'] ?? 0 ) ),
			'buffer_after'   => max( 0, (int) ( $data['buffer_after'] ?? 0 ) ),
			'min_notice'     => max( 0, (int) ( $data['min_notice'] ?? 720 ) ),
			'max_days'       => max( 1, (int) ( $data['max_days'] ?? 45 ) ),
			'daily_limit'    => max( 0, (int) ( $data['daily_limit'] ?? 0 ) ),
			'location'       => $location,
			'location_note'  => isset( $data['location_note'] ) ? sanitize_text_field( $data['location_note'] ) : '',
			'calendar_title' => isset( $data['calendar_title'] ) ? sanitize_text_field( $data['calendar_title'] ) : '',
			'availability'   => wp_json_encode( self::normalize_availability( $data['availability'] ?? array() ) ),
			'blackouts'      => wp_json_encode( self::clean_blackouts( $data['blackouts'] ?? array() ) ),
			'questions'      => wp_json_encode( self::normalize_questions( $data['questions'] ?? array() ) ),
			'active'         => empty( $data['active'] ) ? 0 : 1,
			'sort_order'     => (int) ( $data['sort_order'] ?? 0 ),
		);

		$formats = array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB
			return (int) $id;
		}

		$fields['created_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		$wpdb->insert( $table, $fields, $formats ); // phpcs:ignore WordPress.DB

		return (int) $wpdb->insert_id;
	}

	/**
	 * Sorgt fuer einen eindeutigen Slug.
	 *
	 * @param string $slug Wunsch-Slug.
	 * @param int    $id   Eigene ID.
	 * @return string
	 */
	protected static function unique_slug( $slug, $id ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();

		if ( '' === $slug ) {
			$slug = 'appointment';
		}

		$candidate = $slug;
		$suffix    = 2;

		while ( true ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $candidate, $id ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				return $candidate;
			}
			$candidate = $slug . '-' . $suffix;
			$suffix++;
		}
	}

	/**
	 * Saeubert Sperrtage.
	 *
	 * @param mixed $raw Rohwert.
	 * @return array
	 */
	public static function clean_blackouts( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $date ) {
			$date = trim( (string) $date );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$out[] = $date;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Loescht einen Termintyp.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = FFB_Install::table_event_types();

		return (bool) $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
}
