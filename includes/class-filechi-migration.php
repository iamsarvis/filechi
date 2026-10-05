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
	 * Checks if Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function is_action_scheduler_active() {
		return function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action');
	}

	/**
	 * Starts or resumes the background migration.
	 *
	 * @return bool True if queued, false otherwise.
	 */
	public static function start_migration() {
		update_option('filechi_migration_status', 'running');

		if (self::is_action_scheduler_active()) {
			if (!as_has_scheduled_action(self::ACTION_HOOK)) {
				as_schedule_single_action(time() + 1, self::ACTION_HOOK);
			}
			return true;
		} else {
			// Fallback to standard WP-Cron if Action Scheduler is not present
			if (!wp_next_scheduled(self::ACTION_HOOK)) {
				wp_schedule_single_event(time() + 1, self::ACTION_HOOK);
			}
			return true;
		}
	}

	/**
	 * Pauses the background migration.
	 */
	public static function pause_migration() {
		update_option('filechi_migration_status', 'paused');

		if (self::is_action_scheduler_active()) {
			as_unschedule_all_actions(self::ACTION_HOOK);
		} else {
			wp_clear_scheduled_hook(self::ACTION_HOOK);
		}
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
				// Mark as failed if file meta is missing
				FileChi_DB::log_transfer($attachment_id, $provider['id'], 'unknown', 0, 'failed', __('Missing _wp_attached_file meta.', 'filechi'));
				continue;
			}

			$mime_type = get_post_mime_type($attachment_id);
			$is_image  = (strpos($mime_type, 'image/') === 0);

			$files_to_upload = array();
			$dir_prefix      = dirname($attached_file);
			$dir_prefix      = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

			$main_local = $basedir . '/' . $attached_file;
			if (file_exists($main_local)) {
				$files_to_upload[$attached_file] = $main_local;
			}

			if ($is_image) {
				$metadata = wp_get_attachment_metadata($attachment_id);
				if (!empty($metadata['original_image'])) {
					$orig_rel   = $dir_prefix . $metadata['original_image'];
					$orig_local = $basedir . '/' . $orig_rel;
					if (file_exists($orig_local)) {
						$files_to_upload[$orig_rel] = $orig_local;
					}
				}

				if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
					foreach ($metadata['sizes'] as $size_data) {
						if (!empty($size_data['file'])) {
							$size_rel   = $dir_prefix . $size_data['file'];
							$size_local = $basedir . '/' . $size_rel;
							if (file_exists($size_local)) {
								$files_to_upload[$size_rel] = $size_local;
							}
						}
					}
				}
			}

			if (empty($files_to_upload)) {
				// Local files not found on disk
				FileChi_DB::log_transfer($attachment_id, $provider['id'], $attached_file, 0, 'failed', __('Local file not found on disk.', 'filechi'));
				continue;
			}

			$all_ok = true;

			foreach ($files_to_upload as $rel_path => $abs_path) {
				$filesize = filesize($abs_path);
				$success  = $driver->upload($abs_path, $rel_path);

				if ($success) {
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'transferred');
					if (!$keep_local) {
						@unlink($abs_path);
					}
				} else {
					$all_ok = false;
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'failed', __('Background transfer failed.', 'filechi'));
				}
			}

			if ($all_ok) {
				update_post_meta($attachment_id, '_filechi_offloaded', 1);
				update_post_meta($attachment_id, '_filechi_provider_id', $provider['id']);
			}
		}

		// Schedule next batch immediately if still running
		$remaining = FileChi_DB::get_unmigrated_attachment_ids(1);
		if (!empty($remaining)) {
			if (self::is_action_scheduler_active()) {
				as_schedule_single_action(time() + 1, self::ACTION_HOOK);
			} else {
				wp_schedule_single_event(time() + 2, self::ACTION_HOOK);
			}
		} else {
			update_option('filechi_migration_status', 'completed');
		}
	}
}
