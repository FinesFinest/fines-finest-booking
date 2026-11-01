<?php
/**
 * Deinstallation. Loescht nur, wenn das in den Einstellungen erlaubt wurde.
 *
 * @package FinesFinestBooking
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ffb_settings = get_option( 'ffb_settings', array() );

if ( empty( $ffb_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ffb_bookings" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ffb_event_types" ); // phpcs:ignore WordPress.DB

delete_option( 'ffb_settings' );
delete_option( 'ffb_google_tokens' );
delete_option( 'ffb_google_log' );
delete_option( 'ffb_db_version' );
delete_option( 'ffb_mail_tested' );
delete_option( 'ffb_busy_cache_version' );

wp_clear_scheduled_hook( 'ffb_send_reminders' );
wp_clear_scheduled_hook( 'ffb_cleanup' );

$ffb_upload = wp_upload_dir();
$ffb_dir    = trailingslashit( $ffb_upload['basedir'] ) . 'ffb-tmp';

if ( is_dir( $ffb_dir ) ) {
	foreach ( (array) glob( $ffb_dir . '/*' ) as $ffb_file ) {
		if ( is_file( $ffb_file ) ) {
			@unlink( $ffb_file ); // phpcs:ignore
		}
	}
	@rmdir( $ffb_dir ); // phpcs:ignore
}
