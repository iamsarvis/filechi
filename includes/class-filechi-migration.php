<?php
/**
 * FileChi Bulk Migration Manager
 *
 * Coordinates background migration of existing media library attachments
 * to the remote storage provider via Action Scheduler.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Migration {

	/**
	 * Action Hook name for Action Scheduler.
	 */
	const ACTION_HOOK = 'filechi_process_migration_batch';

	/**
	 * Max attempts before marking an item permanently failed.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hook the Action Scheduler batch handler
		add_action(self::ACTION_HOOK, array($this, 'process_batch'));
	}

	/**
	 * Starts or resumes the background migration.
	 *
	 * @return bool True if queued, false otherwise.
	 */
	public static function start_migration() {
		update_option('filechi_migration_status', 'running');

		if (!as_has_scheduled_action(self::ACTION_HOOK)) {
			as_schedule_single_action(time() + 1, self::ACTION_HOOK);
		}
		return true;
	}

	/**
	 * Pauses the background migration.
	 */
	public static function pause_migration() {
		update_option('filechi_migration_status', 'paused');
		as_unschedule_all_actions(self::ACTION_HOOK);
	}

	/**
	 * Resets failed items and resumes migration.
	 */
	public static function retry_failed() {
		FileChi_DB::reset_failed_logs();
		return self::start_migration();
	}

	/**
	 * Processes a batch of attachments in the background.
	 */
	public function process_batch() {
		$status = get_option('filechi_migration_status', 'stopped');
		if ($status === 'paused' || $status === 'stopped') {
			return;
		}

		$provider = FileChi_DB::get_default_provider();
		if (!$provider) {
			update_option('filechi_migration_status', 'error_no_provider');
			return;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			update_option('filechi_migration_status', 'error_driver_init');
			return;
		}

		$settings   = get_option('filechi_settings', array());
		$batch_size = !empty($settings['migration_batch_size']) ? absint($settings['migration_batch_size']) : 10;
		$keep_local = !empty($settings['keep_local_files']);

		$unmigrated_ids = FileChi_DB::get_unmigrated_attachment_ids($batch_size);

		if (empty($unmigrated_ids)) {
			// Migration completed
			update_option('filechi_migration_status', 'completed');
			return;
		}

		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path($upload_dir['basedir']);

		foreach ($unmigrated_ids as $attachment_id) {
			$attachment_id = absint($attachment_id);
			$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);

			if (empty($attached_file)) {
				FileChi_DB::log_transfer($attachment_id, $provider['id'], 'unknown', 0, 'failed', __('Missing _wp_attached_file meta.', 'filechi'));
				continue;
			}

			// Unified offload routine handles all attachment types with two-phase offload and safety
			FileChi_Media::offload_attachment($attachment_id, $provider);
		}

		// Schedule next batch immediately via Action Scheduler if still running
		$remaining = FileChi_DB::get_unmigrated_attachment_ids(1);
		if (!empty($remaining)) {
			as_schedule_single_action(time() + 1, self::ACTION_HOOK);
		} else {
			update_option('filechi_migration_status', 'completed');
		}
	}
}
