<?php
/**
 * Deinstallation. Loescht nur, wenn das in den Einstellungen erlaubt wurde.
 *
 * Raeumt auch die Namen bis Version 1.2.6 (Praefix ffb) weg, falls eine
 * Installation nie auf 1.3.0 umgestellt wurde.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ffbooking_settings = get_option( 'ffbooking_settings', null );
if ( null === $ffbooking_settings ) {
	$ffbooking_settings = get_option( 'ffb_settings', array() );
}

if ( empty( $ffbooking_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'ffbooking_bookings', 'ffbooking_event_types', 'ffb_bookings', 'ffb_event_types' ) as $ffbooking_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$ffbooking_table}" ); // phpcs:ignore WordPress.DB
}

$ffbooking_options = array( 'settings', 'google_tokens', 'google_log', 'db_version', 'mail_tested', 'busy_cache_version', 'consent_text_refreshed', 'reminders_last_run', 'auto_update_enabled' );
foreach ( $ffbooking_options as $ffbooking_option ) {
	delete_option( 'ffbooking_' . $ffbooking_option );
	delete_option( 'ffb_' . $ffbooking_option );
}
delete_option( 'ffbooking_migration_error' );
delete_option( 'ffbooking_notice_redirect_uri' );

foreach ( array( 'ffbooking_send_reminders', 'ffbooking_cleanup', 'ffb_send_reminders', 'ffb_cleanup' ) as $ffbooking_hook ) {
	wp_clear_scheduled_hook( $ffbooking_hook );
}

// Transients mit neuem oder altem Praefix.
$wpdb->query( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ffbooking_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ffbooking_' ) . '%',
		$wpdb->esc_like( '_transient_ffb_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ffb_' ) . '%'
	)
);

$ffbooking_upload = wp_upload_dir();
$ffbooking_dir    = trailingslashit( $ffbooking_upload['basedir'] ) . 'ffb-tmp';

if ( is_dir( $ffbooking_dir ) ) {
	foreach ( (array) glob( $ffbooking_dir . '/*' ) as $ffbooking_file ) {
		if ( is_file( $ffbooking_file ) ) {
			@unlink( $ffbooking_file ); // phpcs:ignore
		}
	}
	@rmdir( $ffbooking_dir ); // phpcs:ignore
}
