<?php
/**
 * FileChi Uninstall Handler
 *
 * Triggered automatically by WordPress when the plugin is deleted via wp-admin.
 * Cleans up scheduled tasks, transients, and optionally database tables and options
 * if the user opted in via 'delete_data_on_uninstall'. Never touches remote storage.
 *
 * @package FileChi
 */

// If uninstall not called from WordPress, exit immediately
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Executes uninstallation tasks for a single WordPress blog/site.
 */
function filechi_uninstall_site() {
	global $wpdb;

	// 1. Cancel background tasks & clear transients
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'filechi_process_migration_batch', array(), 'filechi' );
		as_unschedule_all_actions( 'filechi_retry_attachment_offload', array(), 'filechi' );
	}
	wp_clear_scheduled_hook( 'filechi_process_migration_batch' );
	wp_clear_scheduled_hook( 'filechi_retry_attachment_offload' );

	// Delete all FileChi transients
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of plugin transients on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_filechi_%' OR option_name LIKE '_transient_timeout_filechi_%'" );

	// 2. Check if user enabled "Delete all data on uninstall"
	$settings   = get_option( 'filechi_settings', array() );
	$delete_all = ! empty( $settings['delete_data_on_uninstall'] );

	if ( ! $delete_all ) {
		// Preserving user data: keep custom tables, keep settings, keep postmeta
		return;
	}

	// 3. User opted-in to full data deletion:
	// NOTE: We NEVER touch remote storage! Only local WordPress tables and metadata are removed.

	// Drop custom database tables
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary cleanup on opt-in plugin uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}filechi_logs" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary cleanup on opt-in plugin uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}filechi_providers" );

	// Delete options
	delete_option( 'filechi_settings' );
	delete_option( 'filechi_db_version' );
	delete_option( 'filechi_migration_status' );
	delete_option( 'filechi_protected_path_missing_notice' );

	// Delete attachment post meta (_filechi_*)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of attachment metadata on uninstall.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_filechi_%'" );
}

// Support WordPress Multisite network uninstall
if ( is_multisite() ) {
	$filechi_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $filechi_sites as $filechi_site_id ) {
		switch_to_blog( $filechi_site_id );
		filechi_uninstall_site();
		restore_current_blog();
	}
} else {
	filechi_uninstall_site();
}
