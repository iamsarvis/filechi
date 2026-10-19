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
	const DB_VERSION = '1.1.0';

	/**
	 * Creates or upgrades custom database tables on activation.
	 */
	public static function activate() {
		self::maybe_upgrade();
		self::set_default_options();
	}

	/**
	 * Checks if schema upgrade is needed and runs upgrade routine behind a lock.
	 *
	 * @return bool True if up to date or upgrade succeeded, false if locked.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option('filechi_db_version', '0.0.0');

		if (version_compare($installed, self::DB_VERSION, '>=')) {
			return true;
		}

		// Concurrency guard: lock prevents parallel requests from executing migrations together
		if (get_transient('filechi_db_upgrading')) {
			return false;
		}

		set_transient('filechi_db_upgrading', time(), 300);

		try {
			self::create_tables();
			self::run_migrations($installed);
			update_option('filechi_db_version', self::DB_VERSION);
			delete_option('filechi_db_upgrade_error');
		} catch (\Throwable $e) {
			update_option('filechi_db_upgrade_error', $e->getMessage());
			if (function_exists('error_log')) {
				error_log('FileChi DB Upgrade Failed: ' . $e->getMessage());
			}
		} finally {
			delete_transient('filechi_db_upgrading');
		}

		return true;
	}

	/**
	 * Runs incremental data migrations between schema versions.
	 *
	 * @param string $installed Currently installed DB version.
	 */
	public static function run_migrations($installed) {
		if (version_compare($installed, '1.1.0', '<')) {
			if (!class_exists('FileChi_DB') && defined('FILECHI_DIR') && file_exists(FILECHI_DIR . 'includes/class-filechi-db.php')) {
				require_once FILECHI_DIR . 'includes/class-filechi-db.php';
			}
			FileChi_DB::run_files_migration();
			do_action('filechi_db_upgrade_1_1_0');
		}
	}

	/**
	 * Registers admin notice if an upgrade failure was recorded.
	 */
	public static function check_upgrade_notice() {
		$error = get_option('filechi_db_upgrade_error');
		if (!empty($error) && is_admin()) {
			add_action('admin_notices', array(__CLASS__, 'render_upgrade_error_notice'));
		}
	}

	/**
	 * Renders admin notice for database upgrade error.
	 */
	public static function render_upgrade_error_notice() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$error = get_option('filechi_db_upgrade_error');
		if (empty($error)) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sprintf(
			/* translators: %s: Error message */
			__('FileChi database upgrade failed: %s. Please check server logs or re-activate the plugin.', 'filechi'),
			$error
		)) . '</p></div>';
	}

	/**
	 * Custom tables creation using dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_providers = $wpdb->prefix . 'filechi_providers';
		$table_logs      = $wpdb->prefix . 'filechi_logs';
		$table_files     = $wpdb->prefix . 'filechi_files';

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

		$sql_files = "CREATE TABLE {$table_files} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  provider_id bigint(20) unsigned NOT NULL,
  role varchar(10) NOT NULL DEFAULT 'primary',
  local_path varchar(500) NOT NULL,
  remote_key varchar(500) NOT NULL,
  size bigint(20) unsigned DEFAULT NULL,
  etag varchar(64) DEFAULT NULL,
  status varchar(20) NOT NULL,
  last_error text NULL,
  failures smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY attachment_provider_local (attachment_id, provider_id, local_path(191)),
  KEY provider_remote_status (provider_id, remote_key(191), status),
  KEY attachment_id (attachment_id),
  KEY status (status)
) {$charset_collate};";

		if (file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta($sql_providers);
		dbDelta($sql_logs);
		dbDelta($sql_files);
	}

	/**
	 * Returns the single source of truth for plugin default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'keep_local_files'         => 1, // Default: keep local file copies (safe default)
			'keep_remote_on_delete'    => 0, // Default: delete remote copy when attachment is deleted
			'remote_path_format'       => 'basedir', // 'basedir' (standard wp-content/uploads layout)
			'url_replacement'          => 1, // Filter attachment URLs to remote
			'wc_signed_downloads'      => 1, // Generate signed time-limited URLs for WC downloads
			'wc_download_expiry'       => 900, // 15 minutes (in seconds)
			'migration_batch_size'     => 10,
			'delete_data_on_uninstall' => 0, // Default: keep all plugin data on uninstall
		);
	}

	/**
	 * Sets sensible default settings on initial activation.
	 */
	private static function set_default_options() {
		if (get_option('filechi_settings') === false) {
			$defaults = self::default_settings();
			add_option('filechi_settings', $defaults, '', false); // do not autoload large blobs
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
			as_unschedule_all_actions('filechi_migrate_files_batch', array(), 'filechi');
		}

		// Clear any scheduled WP-Cron events
		wp_clear_scheduled_hook('filechi_process_migration_batch');
		wp_clear_scheduled_hook('filechi_retry_attachment_offload');
		wp_clear_scheduled_hook('filechi_migrate_files_batch');
	}
}
