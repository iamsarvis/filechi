<?php
/**
 * FileChi Activator & Schema Migration
 *
 * Handles database installation and table schemas via dbDelta upon plugin activation.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Activator {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Creates or upgrades custom database tables on activation.
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();

		update_option('filechi_db_version', self::DB_VERSION);
	}

	/**
	 * Custom tables creation using dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_providers = $wpdb->prefix . 'filechi_providers';
		$table_logs      = $wpdb->prefix . 'filechi_logs';

		// Note: dbDelta requires two spaces after PRIMARY KEY and specific casing
		$sql_providers = "CREATE TABLE {$table_providers} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  driver varchar(32) NOT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  settings longtext NOT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY driver (driver),
  KEY is_default (is_default)
) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$table_logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  provider_id bigint(20) unsigned NOT NULL,
  file_path varchar(500) NOT NULL,
  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(32) NOT NULL,
  error_message text NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id),
  KEY provider_id (provider_id),
  KEY status (status)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql_providers);
		dbDelta($sql_logs);
	}

	/**
	 * Sets sensible default settings on initial activation.
	 */
	private static function set_default_options() {
		if (get_option('filechi_settings') === false) {
			$defaults = array(
				'keep_local_files'          => 1, // Default: keep local file copies (safe default)
				'keep_remote_on_delete'     => 0, // Default: delete remote copy when attachment is deleted
				'remote_path_format'        => 'basedir', // 'basedir' (standard wp-content/uploads layout)
				'url_replacement'           => 1, // Filter attachment URLs to remote
				'wc_signed_downloads'       => 1, // Generate signed time-limited URLs for WC downloads
				'wc_download_expiry'        => 900, // 15 minutes (in seconds)
				'migration_batch_size'      => 10,
				'delete_data_on_uninstall'  => 0, // Default: keep all plugin data on uninstall
			);
			add_option('filechi_settings', $defaults, '', 'no'); // do not autoload large blobs
		}
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		// Stop any pending Action Scheduler background jobs
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions('filechi_process_migration_batch', array(), 'filechi');
			as_unschedule_all_actions('filechi_retry_attachment_offload', array(), 'filechi');
		}

		// Clear any scheduled WP-Cron events
		wp_clear_scheduled_hook('filechi_process_migration_batch');
		wp_clear_scheduled_hook('filechi_retry_attachment_offload');
	}
}
