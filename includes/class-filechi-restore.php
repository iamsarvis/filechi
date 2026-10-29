<?php
/**
 * FileChi Restore Engine
 *
 * Coordinates background restoration of offloaded media files from a remote
 * storage provider back to the local WordPress server.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Restore {

	/**
	 * Action Scheduler hook for processing batches of restore items.
	 */
	const ACTION_HOOK = 'filechi_restore_batch';

	/**
	 * Action Scheduler hook for exponential backoff retries of single attachments.
	 */
	const RETRY_HOOK = 'filechi_retry_restore_attachment';

	/**
	 * WordPress option key storing current restore state.
	 */
	const OPTION_STATE = 'filechi_restore_state';

	/**
	 * Maximum failure attempts before giving up on an attachment.
	 */
	const MAX_FAILURES = 5;

	/**
	 * Constructor.
	 *
	 * Registers Action Scheduler action hooks for restore processing.
	 */
	public function __construct() {
		add_action(self::ACTION_HOOK, array($this, 'process_batch'));
		add_action(self::RETRY_HOOK, array($this, 'handle_retry_action'));
	}

	/**
	 * Checks whether a restore operation is currently running for a specific provider.
	 *
	 * @param int $provider_id Provider ID.
	 * @return bool True if running for this provider, false otherwise.
	 */
	public static function is_restore_running_for_provider($provider_id) {
		$state = get_option(self::OPTION_STATE, array());
		if (empty($state) || !is_array($state)) {
			return false;
		}

		$status = isset($state['status']) ? (string) $state['status'] : '';
		if ($status !== 'running') {
			return false;
		}

		$current_provider = isset($state['provider_id']) ? (int) $state['provider_id'] : 0;
		return $current_provider === (int) $provider_id;
	}

	/**
	 * Starts a restore process for the designated provider.
	 *
	 * @param int  $provider_id   Provider ID to restore files from.
	 * @param bool $delete_remote Whether to delete remote copies after local verification.
	 * @return array<string, mixed>|\WP_Error State array on success, WP_Error on validation failure.
	 */
	public static function start($provider_id, $delete_remote = false) {
		return self::start_restore($provider_id, $delete_remote);
	}

	/**
	 * Starts a restore process for the designated provider (legacy alias).
	 *
	 * @param int  $provider_id   Provider ID to restore files from.
	 * @param bool $delete_remote Whether to delete remote copies after local verification.
	 * @return array<string, mixed>|\WP_Error State array on success, WP_Error on validation failure.
	 */
	public static function start_restore($provider_id, $delete_remote = false) {
		$provider_id = absint($provider_id);
		$provider    = FileChi_DB::get_provider($provider_id, true);

		if (!$provider) {
			return new WP_Error(
				'filechi_provider_not_found',
				__('Specified remote storage provider does not exist.', 'filechi')
			);
		}

		$stats = FileChi_DB::get_provider_files_stats($provider_id);
		$state = array(
			'provider_id'   => $provider_id,
			'status'        => 'running',
			'delete_remote' => (bool) $delete_remote,
			'started_at'    => time(),
			'completed_at'  => null,
			'counts'        => array(
				'total'     => $stats['count'],
				'restored'  => 0,
				'remaining' => $stats['count'],
				'failed'    => 0,
				'given_up'  => 0,
			),
			'bytes'         => array(
				'total'     => $stats['bytes'],
				'restored'  => 0.0,
				'remaining' => $stats['bytes'],
			),
		);

		update_option(self::OPTION_STATE, $state, false);

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + 1, self::ACTION_HOOK, array(), 'filechi');
		}

		return $state;
	}

	/**
	 * Pauses the active restore operation.
	 *
	 * @return array<string, mixed>|\WP_Error Updated state array or error.
	 */
	public static function pause() {
		$state = get_option(self::OPTION_STATE, array());
		if (empty($state) || !is_array($state) || empty($state['provider_id'])) {
			return new WP_Error('no_active_restore', __('No active restore operation found to pause.', 'filechi'), array('status' => 400));
		}

		$state['status'] = 'paused';
		update_option(self::OPTION_STATE, $state, false);

		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::ACTION_HOOK, array(), 'filechi');
		}

		return self::get_status();
	}

	/**
	 * Legacy alias for pause.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function pause_restore() {
		return self::pause();
	}

	/**
	 * Resumes a paused restore operation.
	 *
	 * @return array<string, mixed>|\WP_Error Updated state array or error.
	 */
	public static function resume() {
		$state = get_option(self::OPTION_STATE, array());
		if (empty($state) || !is_array($state) || empty($state['provider_id'])) {
			return new WP_Error('no_active_restore', __('No restore operation found to resume.', 'filechi'), array('status' => 400));
		}

		$state['status'] = 'running';
		update_option(self::OPTION_STATE, $state, false);

		if (function_exists('as_schedule_single_action')) {
			if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action(self::ACTION_HOOK, array(), 'filechi')) {
				as_schedule_single_action(time() + 1, self::ACTION_HOOK, array(), 'filechi');
			}
		}

		return self::get_status();
	}

	/**
	 * Legacy alias for resume.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resume_restore() {
		return self::resume();
	}

	/**
	 * Resets failure and give-up flags and resumes restore.
	 *
	 * @return array<string, mixed>|\WP_Error Count and state array or error.
	 */
	public static function retry_failed() {
		$state = get_option(self::OPTION_STATE, array());
		if (empty($state) || !is_array($state) || empty($state['provider_id'])) {
			return new WP_Error('no_active_restore', __('No restore operation found to retry.', 'filechi'), array('status' => 400));
		}

		$reset_count = FileChi_DB::reset_failed_restore_attachments((int) $state['provider_id']);
		$state['status'] = 'running';
		if (isset($state['counts']['failed'])) {
			$state['counts']['failed'] = 0;
		}
		if (isset($state['counts']['given_up'])) {
			$state['counts']['given_up'] = 0;
		}
		update_option(self::OPTION_STATE, $state, false);

		if (function_exists('as_schedule_single_action')) {
			if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action(self::ACTION_HOOK, array(), 'filechi')) {
				as_schedule_single_action(time() + 1, self::ACTION_HOOK, array(), 'filechi');
			}
		}

		return array(
			'reset_count' => $reset_count,
			'state'       => self::get_status(),
		);
	}

	/**
	 * Retrieves complete current restore status and system disk info for UI/API.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state() {
		return self::get_status();
	}

	/**
	 * Retrieves complete current restore status and system disk info for UI/API.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status() {
		$state = get_option(self::OPTION_STATE, array());

		$upload_dir = wp_upload_dir();
		$basedir    = (string) $upload_dir['basedir'];
		$free_space = null;

		if (!empty($basedir) && function_exists('disk_free_space')) {
			$bytes = @disk_free_space($basedir);
			if ($bytes !== false) {
				$free_space = (double) $bytes;
			}
		}

		$free_space_formatted = 'unknown';
		if ($free_space !== null) {
			if (function_exists('size_format')) {
				$free_space_formatted = size_format((int) $free_space, 2);
			} else {
				$free_space_formatted = number_format($free_space / 1048576, 2) . ' MB';
			}
		}

		if (empty($state) || !is_array($state)) {
			return array(
				'provider'             => null,
				'status'               => 'idle',
				'files'                => array(
					'total'     => 0,
					'restored'  => 0,
					'remaining' => 0,
					'failed'    => 0,
					'given_up'  => 0,
				),
				'bytes'                => array(
					'total'     => 0,
					'restored'  => 0,
					'remaining' => 0,
				),
				'free_disk_space'      => $free_space,
				'free_space_formatted' => $free_space_formatted,
				'started_at'           => null,
			);
		}

		$provider_id = isset($state['provider_id']) ? (int) $state['provider_id'] : 0;
		$provider    = $provider_id > 0 ? FileChi_DB::get_provider($provider_id, false) : null;

		return array(
			'provider'             => $provider,
			'status'               => $state['status'] ?? 'idle',
			'delete_remote'        => !empty($state['delete_remote']),
			'files'                => $state['counts'] ?? array(
				'total'     => 0,
				'restored'  => 0,
				'remaining' => 0,
				'failed'    => 0,
				'given_up'  => 0,
			),
			'bytes'                => $state['bytes'] ?? array(
				'total'     => 0,
				'restored'  => 0,
				'remaining' => 0,
			),
			'free_disk_space'      => $free_space,
			'free_space_formatted' => $free_space_formatted,
			'started_at'           => $state['started_at'] ?? null,
			'completed_at'         => $state['completed_at'] ?? null,
		);
	}

	/**
	 * Action Scheduler worker: processes a batch of attachments to restore.
	 *
	 * @return void
	 */
	public function process_batch() {
		$state = get_option(self::OPTION_STATE, array());
		if (empty($state) || !is_array($state) || ($state['status'] ?? '') !== 'running') {
			return;
		}

		$provider_id = (int) ($state['provider_id'] ?? 0);
		$provider    = FileChi_DB::get_provider($provider_id, true);
		if (!$provider) {
			$state['status'] = 'completed_with_errors';
			update_option(self::OPTION_STATE, $state, false);
			return;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			$state['status'] = 'completed_with_errors';
			update_option(self::OPTION_STATE, $state, false);
			return;
		}

		$delete_remote = !empty($state['delete_remote']);
		$batch_size    = 10;

		$attachment_ids = FileChi_DB::get_unrestored_attachment_ids($provider_id, $batch_size, false);

		if (empty($attachment_ids)) {
			// Check if only given-up attachments remain
			$given_up_ids = FileChi_DB::get_unrestored_attachment_ids($provider_id, 1, true);
			if (!empty($given_up_ids)) {
				$state['status']       = 'completed_with_errors';
				$state['completed_at'] = time();
			} else {
				$state['status']       = 'completed';
				$state['completed_at'] = time();
			}
			update_option(self::OPTION_STATE, $state, false);
			return;
		}

		foreach ($attachment_ids as $attachment_id) {
			$attachment_id = absint($attachment_id);
			self::restore_attachment($attachment_id, $provider, $driver, $delete_remote);
		}

		// Recalculate live counts for restore state
		self::update_state_counts($provider_id);

		// Schedule next batch if more un-restored attachments remain
		$remaining_ids = FileChi_DB::get_unrestored_attachment_ids($provider_id, 1, false);
		if (!empty($remaining_ids)) {
			if (function_exists('as_schedule_single_action')) {
				as_schedule_single_action(time() + 10, self::ACTION_HOOK, array(), 'filechi');
			}
		} else {
			$all_remaining = FileChi_DB::get_unrestored_attachment_ids($provider_id, 1, true);
			$current_state = get_option(self::OPTION_STATE, array());
			if (is_array($current_state)) {
				$current_state['status']       = empty($all_remaining) ? 'completed' : 'completed_with_errors';
				$current_state['completed_at'] = time();
				update_option(self::OPTION_STATE, $current_state, false);
			}
		}
	}

	/**
	 * Updates the counts and byte tallies stored in the restore state.
	 *
	 * @param int $provider_id
	 * @return void
	 */
	public static function update_state_counts($provider_id) {
		$state = get_option(self::OPTION_STATE, array());
		if (!is_array($state)) {
			return;
		}

		$total_files = isset($state['counts']['total']) ? (int) $state['counts']['total'] : 0;
		$total_bytes = isset($state['bytes']['total']) ? (double) $state['bytes']['total'] : 0.0;

		$active_stats = FileChi_DB::get_provider_files_stats($provider_id);
		$remaining    = $active_stats['count'];
		$remain_bytes = $active_stats['bytes'];

		$restored       = max(0, $total_files - $remaining);
		$restored_bytes = max(0.0, $total_bytes - $remain_bytes);

		global $wpdb;
		$given_up = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
				 INNER JOIN {$wpdb->postmeta} pm_give ON (p.ID = pm_give.post_id AND pm_give.meta_key = '_filechi_restore_given_up' AND pm_give.meta_value = '1')
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'",
				$provider_id
			)
		);

		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
				 INNER JOIN {$wpdb->postmeta} pm_fail ON (p.ID = pm_fail.post_id AND pm_fail.meta_key = '_filechi_restore_failure_count' AND pm_fail.meta_value > 0)
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'",
				$provider_id
			)
		);

		$state['counts'] = array(
			'total'     => $total_files,
			'restored'  => $restored,
			'remaining' => $remaining,
			'failed'    => $failed,
			'given_up'  => $given_up,
		);
		$state['bytes']  = array(
			'total'     => $total_bytes,
			'restored'  => $restored_bytes,
			'remaining' => $remain_bytes,
		);

		update_option(self::OPTION_STATE, $state, false);
	}

	/**
	 * Handles retry action triggered by Action Scheduler for a single attachment.
	 *
	 * @param int $attachment_id
	 * @return void
	 */
	public function handle_retry_action($attachment_id) {
		$attachment_id = absint($attachment_id);
		$provider_id   = (int) get_post_meta($attachment_id, '_filechi_provider_id', true);
		if ($provider_id <= 0) {
			return;
		}

		$provider = FileChi_DB::get_provider($provider_id, true);
		if (!$provider) {
			return;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return;
		}

		$state         = get_option(self::OPTION_STATE, array());
		$delete_remote = is_array($state) && !empty($state['delete_remote']);

		self::restore_attachment($attachment_id, $provider, $driver, $delete_remote);

		self::update_state_counts($provider_id);
	}

	/**
	 * Records failure count, applies exponential backoff, and gives up after MAX_FAILURES.
	 *
	 * @param int    $attachment_id
	 * @param string $reason
	 * @return void
	 */
	public static function handle_restore_failure($attachment_id, $reason) {
		$failures = (int) get_post_meta($attachment_id, '_filechi_restore_failure_count', true) + 1;
		update_post_meta($attachment_id, '_filechi_restore_failure_count', $failures);
		update_post_meta($attachment_id, '_filechi_restore_last_error', $reason);

		$is_unretryable = ($reason === 'local_conflict' || $reason === 'invalid_path' || $reason === 'path_traversal');

		if ($is_unretryable || $failures >= self::MAX_FAILURES) {
			update_post_meta($attachment_id, '_filechi_restore_given_up', 1);
			delete_post_meta($attachment_id, '_filechi_restore_retry_scheduled');
			delete_post_meta($attachment_id, '_filechi_remote_prefix');
			if (function_exists('as_unschedule_action')) {
				as_unschedule_action(self::RETRY_HOOK, array('attachment_id' => $attachment_id), 'filechi');
			}
			return;
		}

		// Exponential backoff: 30s * 2^(failures - 1), capped at 30 minutes (1800s)
		$delay = min(1800, 30 * (int) pow(2, max(0, $failures - 1)));
		update_post_meta($attachment_id, '_filechi_restore_retry_scheduled', 1);

		if (function_exists('as_schedule_single_action')) {
			if (function_exists('as_unschedule_action')) {
				as_unschedule_action(self::RETRY_HOOK, array('attachment_id' => $attachment_id), 'filechi');
			}
			as_schedule_single_action(time() + $delay, self::RETRY_HOOK, array('attachment_id' => $attachment_id), 'filechi');
		}
	}

	/**
	 * Restores all primary files of an attachment back to the local uploads directory.
	 *
	 * Implements items R1.3 and R1.4.
	 *
	 * @param int                                  $attachment_id Post ID of the attachment.
	 * @param array<string, mixed>|int|null        $provider      Provider database record or ID.
	 * @param FileChi_Storage_Interface|bool|null  $driver        Optional configured storage driver or delete_remote boolean.
	 * @param bool                                 $delete_remote Whether to delete remote copies after finalization.
	 * @return bool True on success, false on failure or lock.
	 */
	public static function restore_attachment($attachment_id, $provider = null, $driver = null, $delete_remote = false) {
		if (is_bool($driver)) {
			$delete_remote = $driver;
			$driver        = null;
		}

		$attachment_id = absint($attachment_id);
		if (!$attachment_id) {
			return false;
		}

		$lock_token = FileChi_Media::acquire_lock($attachment_id);
		if (!$lock_token) {
			return false;
		}

		try {
			if ($provider === null) {
				$provider = FileChi_DB::get_default_provider();
			}
			if (is_numeric($provider)) {
				$provider = FileChi_DB::get_provider((int) $provider, true);
			}
			if (!is_array($provider) || empty($provider['id'])) {
				self::handle_restore_failure($attachment_id, 'invalid_provider');
				return false;
			}

			if ($driver === null) {
				$driver = FileChi_Storage_Factory::create($provider);
			}
			if (!$driver instanceof FileChi_Storage_Interface) {
				self::handle_restore_failure($attachment_id, 'invalid_driver');
				return false;
			}

			$reason = self::do_restore_attachment_files($attachment_id, $provider, $driver, $delete_remote);
			if ($reason === true) {
				delete_post_meta($attachment_id, '_filechi_restore_failure_count');
				delete_post_meta($attachment_id, '_filechi_restore_given_up');
				delete_post_meta($attachment_id, '_filechi_restore_last_error');
				delete_post_meta($attachment_id, '_filechi_restore_retry_scheduled');
				delete_post_meta($attachment_id, '_filechi_remote_prefix');
				return true;
			}

			self::handle_restore_failure($attachment_id, $reason);
			return false;
		} finally {
			FileChi_Media::release_lock($attachment_id, $lock_token);
		}
	}

	/**
	 * Internal worker restoring all primary files of an attachment.
	 *
	 * @param int                       $attachment_id Post ID of the attachment.
	 * @param array<string, mixed>      $provider      Provider record.
	 * @param FileChi_Storage_Interface $driver        Storage driver.
	 * @param bool                      $delete_remote Whether to delete remote copies.
	 * @return true|string True on success, error reason code string on failure.
	 */
	private static function do_restore_attachment_files($attachment_id, array $provider, FileChi_Storage_Interface $driver, $delete_remote = false) {
		$provider_id = (int) $provider['id'];
		$file_rows   = FileChi_DB::get_attachment_files_by_path($attachment_id, $provider_id);

		if (empty($file_rows)) {
			// No recorded file rows: verify if attachment has _wp_attached_file
			$attached = get_post_meta($attachment_id, '_wp_attached_file', true);
			if (empty($attached)) {
				return 'missing_attached_file';
			}
			$file_rows = array(
				$attached => array(
					'local_path' => $attached,
					'remote_key' => FileChi_Media::get_remote_key($attachment_id, $attached),
					'size'       => null,
					'etag'       => null,
					'status'     => 'transferred',
				),
			);
		}

		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path((string) $upload_dir['basedir']);

		if (empty($basedir) || !is_dir($basedir)) {
			return 'uploads_basedir_missing';
		}

		// Check available disk space before starting
		if (function_exists('disk_free_space')) {
			$free_space = @disk_free_space($basedir);
			$need_space = 0;
			foreach ($file_rows as $row) {
				if (!empty($row['size'])) {
					$need_space += (int) $row['size'];
				}
			}
			if ($free_space !== false && $need_space > 0 && $free_space < ($need_space + 10485760)) {
				return 'insufficient_disk_space';
			}
		}

		$verified_local_files = array();

		// R1.3 Per file validation, download, and atomic verification
		foreach ($file_rows as $rel_path => $file_info) {
			$local_path = (string) ($file_info['local_path'] ?? $rel_path);

			// Validate local_path using FileChi_Path::validate
			try {
				$clean_local = FileChi_Path::validate($local_path);
			} catch (FileChi_Path_Exception $e) {
				return 'path_traversal';
			}

			$target_local = wp_normalize_path($basedir . '/' . $clean_local);
			$parent_dir   = wp_normalize_path(dirname($target_local));

			// Guard: Resolved parent directory MUST reside inside uploads basedir
			if (strpos($parent_dir, $basedir) !== 0) {
				return 'path_traversal';
			}

			$recorded_size = isset($file_info['size']) ? (int) $file_info['size'] : -1;

			// If local file already exists
			if (file_exists($target_local)) {
				$actual_size = (int) filesize($target_local);

				if ($recorded_size >= 0 && $actual_size === $recorded_size) {
					// Recorded size matches local file: counts as done
					$verified_local_files[] = array(
						'remote_key' => $file_info['remote_key'] ?? $clean_local,
						'local_file' => $target_local,
					);
					continue;
				}

				if ($recorded_size >= 0 && $actual_size !== $recorded_size) {
					// Exists with a different size: do NOT overwrite, record local_conflict
					FileChi_DB::log_transfer(
						$attachment_id,
						$provider_id,
						$clean_local,
						$actual_size,
						'failed',
						__('Local file exists with conflicting size; not overwritten.', 'filechi')
					);
					FileChi_DB::record_file_state(
						$attachment_id,
						$provider_id,
						$clean_local,
						'failed',
						array(
							'size'       => $actual_size,
							'last_error' => 'local_conflict',
						)
					);
					return 'local_conflict';
				}

				// If recorded_size is unknown but file exists locally
				$verified_local_files[] = array(
					'remote_key' => $file_info['remote_key'] ?? $clean_local,
					'local_file' => $target_local,
				);
				continue;
			}

			// Local file missing: check disk space before large files (>=10MB)
			if (function_exists('disk_free_space') && $recorded_size >= 10485760) {
				$file_free = @disk_free_space($parent_dir);
				if ($file_free !== false && $file_free < ($recorded_size + 10485760)) {
					return 'insufficient_disk_space';
				}
			}

			// Prepare directory and download
			if (!is_dir($parent_dir)) {
				if (function_exists('wp_mkdir_p')) {
					wp_mkdir_p($parent_dir);
				} else {
					@mkdir($parent_dir, 0755, true);
				}
			}

			// Temporary file in the same folder (.filechi-restore-<random>.tmp)
			$rand_token = function_exists('wp_generate_password') ? wp_generate_password(12, false, false) : bin2hex(random_bytes(6));
			$temp_file  = $parent_dir . '/.filechi-restore-' . $rand_token . '.tmp';

			$remote_key = !empty($file_info['remote_key']) ? (string) $file_info['remote_key'] : FileChi_Media::get_remote_key($attachment_id, $clean_local);
			$dl_res     = $driver->download($remote_key, $temp_file, max(0, $recorded_size));

			if ($dl_res !== true) {
				if (file_exists($temp_file)) {
					@unlink($temp_file);
				}
				return $dl_res;
			}

			// Verify size against recorded size
			if (!file_exists($temp_file)) {
				return 'download_failed';
			}

			$downloaded_size = (int) filesize($temp_file);
			if ($recorded_size >= 0 && $downloaded_size !== $recorded_size) {
				@unlink($temp_file);
				FileChi_DB::log_transfer(
					$attachment_id,
					$provider_id,
					$clean_local,
					$downloaded_size,
					'failed',
					sprintf(__('Downloaded size (%1$d) did not match recorded size (%2$d).', 'filechi'), $downloaded_size, $recorded_size)
				);
				FileChi_DB::record_file_state(
					$attachment_id,
					$provider_id,
					$clean_local,
					'failed',
					array(
						'size'       => $downloaded_size,
						'last_error' => 'size_mismatch',
					)
				);
				return 'size_mismatch';
			}

			// Verify ETag as MD5 for S3 single-part objects
			$etag = isset($file_info['etag']) ? trim((string) $file_info['etag'], '"\'') : '';
			if ($etag !== '' && strpos($etag, '-') === false && strlen($etag) === 32) {
				$file_md5 = md5_file($temp_file);
				if ($file_md5 !== false && strtolower($file_md5) !== strtolower($etag)) {
					@unlink($temp_file);
					FileChi_DB::log_transfer(
						$attachment_id,
						$provider_id,
						$clean_local,
						$downloaded_size,
						'failed',
						sprintf(__('Downloaded file MD5 (%1$s) did not match S3 ETag (%2$s).', 'filechi'), $file_md5, $etag)
					);
					FileChi_DB::record_file_state(
						$attachment_id,
						$provider_id,
						$clean_local,
						'failed',
						array(
							'size'       => $downloaded_size,
							'last_error' => 'etag_mismatch',
						)
					);
					return 'etag_mismatch';
				}
			}

			// Rename into place atomically
			$renamed = @rename($temp_file, $target_local);
			if (!$renamed) {
				@unlink($temp_file);
				return 'rename_failed';
			}

			// Set normal WordPress file permissions
			$chmod_perms = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
			@chmod($target_local, $chmod_perms);

			$verified_local_files[] = array(
				'remote_key' => $remote_key,
				'local_file' => $target_local,
			);
		}

		// R1.4 Finalize attachment only when ALL files are present locally and verified
		if (count($verified_local_files) < count($file_rows)) {
			return 'incomplete_files';
		}

		// Remove attachment offload metadata
		delete_post_meta($attachment_id, '_filechi_offloaded');
		delete_post_meta($attachment_id, '_filechi_provider_id');
		delete_post_meta($attachment_id, '_filechi_failure_count');
		delete_post_meta($attachment_id, '_filechi_given_up');
		delete_post_meta($attachment_id, '_filechi_last_error');
		delete_post_meta($attachment_id, '_filechi_retry_scheduled');
		delete_post_meta($attachment_id, '_filechi_restore_failure_count');
		delete_post_meta($attachment_id, '_filechi_restore_given_up');
		delete_post_meta($attachment_id, '_filechi_restore_last_error');
		delete_post_meta($attachment_id, '_filechi_restore_retry_scheduled');
		delete_post_meta($attachment_id, '_filechi_remote_prefix');

		// Delete its filechi_files rows for that provider
		FileChi_DB::delete_attachment_files_for_provider($attachment_id, $provider_id);

		// Write log event
		$main_file  = get_post_meta($attachment_id, '_wp_attached_file', true) ?: 'attachment';
		$total_size = 0;
		foreach ($verified_local_files as $f) {
			if (file_exists($f['local_file'])) {
				$total_size += (int) filesize($f['local_file']);
			}
		}

		FileChi_DB::log_transfer(
			$attachment_id,
			$provider_id,
			$main_file,
			$total_size,
			'restored',
			__('Attachment files restored to local server.', 'filechi')
		);

		// Optional remote cleanup: delete remote files one by one non-recursively
		if ($delete_remote) {
			foreach ($verified_local_files as $f) {
				$r_key = $f['remote_key'];
				try {
					$clean_r_key = FileChi_Path::validate($r_key);
					$del_ok      = $driver->delete($clean_r_key);
					if (!$del_ok) {
						FileChi_DB::log_transfer(
							$attachment_id,
							$provider_id,
							$clean_r_key,
							0,
							'failed',
							sprintf(__('Remote file deletion failed after restore for %s: %s', 'filechi'), $clean_r_key, $driver->get_last_error())
						);
					}
				} catch (\Exception $e) {
					// A failed remote delete never undoes the restore
					FileChi_DB::log_transfer(
						$attachment_id,
						$provider_id,
						$r_key,
						0,
						'failed',
						sprintf(__('Remote file deletion failed after restore: %s', 'filechi'), $e->getMessage())
					);
				}
			}
		}

		return true;
	}
}
