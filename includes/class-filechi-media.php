<?php
/**
 * FileChi Media Library Integration
 *
 * Hooks into the native WordPress Media Library upload lifecycle,
 * attachment metadata generation, URL filtering, and deletion handling.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Media {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings = wp_parse_args(get_option('filechi_settings', array()), FileChi_Activator::default_settings());

		// Offload all attachment types after metadata generation at late priority 999
		add_filter('wp_generate_attachment_metadata', array($this, 'handle_attachment_metadata'), 999, 2);

		// Action Scheduler retry hook for failed transfers
		add_action('filechi_retry_attachment_offload', array($this, 'handle_retry_action'));

		// URL rewriting filters
		if (!empty($settings['url_replacement'] ?? 1)) {
			add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 20, 2);
			add_filter('wp_calculate_image_srcset', array($this, 'filter_image_srcset'), 20, 5);
			add_filter('wp_get_attachment_image_src', array($this, 'filter_attachment_image_src'), 20, 4);
		}

		// Attachment deletion
		add_action('delete_attachment', array($this, 'handle_delete_attachment'), 20);
	}

	/**
	 * Normalizes file path relative to uploads directory basedir.
	 *
	 * @param string $file_path Absolute local file path.
	 * @return string Relative path inside uploads.
	 */
	public static function get_relative_upload_path($file_path) {
		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path($upload_dir['basedir']);
		$normalized = wp_normalize_path($file_path);

		if (strpos($normalized, $basedir) === 0) {
			return ltrim(substr($normalized, strlen($basedir)), '/');
		}

		return ltrim($normalized, '/');
	}

	/**
	 * Offloads all attachment types upon metadata generation at late priority 999.
	 * Handles $metadata being empty/false for non-images (PDF, videos, audio, etc.).
	 *
	 * @param array|false $metadata Attachment metadata array or false/empty.
	 * @param int         $attachment_id Attachment post ID.
	 * @return array|false Unmodified metadata.
	 */
	public function handle_attachment_metadata($metadata, $attachment_id) {
		self::offload_attachment($attachment_id, null, is_array($metadata) ? $metadata : array());
		return $metadata;
	}

	/**
	 * Two-phase offload routine for media attachments.
	 * Used for both initial upload lifecycle and bulk migration.
	 *
	 * Phase 1: Upload and verify all files of the attachment.
	 * Phase 2: If all succeed, set offloaded meta and delete local files (if keep_local_files is off).
	 * On any failure: delete nothing locally, do not set offload flag, log error, and schedule one retry.
	 *
	 * @param int            $attachment_id Attachment post ID.
	 * @param array|int|null $provider      Optional provider record or ID. Defaults to active provider.
	 * @param array|null     $metadata      Optional metadata. Fetched if null.
	 * @return bool True if successfully offloaded, false otherwise.
	 */
	public static function offload_attachment($attachment_id, $provider = null, $metadata = null) {
		$attachment_id = absint($attachment_id);
		if (!$attachment_id) {
			return false;
		}

		$lock_token = self::acquire_lock($attachment_id);
		if (!$lock_token) {
			// Attachment is currently locked by another worker: skip
			return false;
		}

		try {
			if ($provider === null) {
				$provider = FileChi_DB::get_default_provider();
			}
			if (is_numeric($provider)) {
				$provider = FileChi_DB::get_provider((int) $provider, true);
			}
			if (!$provider) {
				return false;
			}

		$provider_settings = is_array($provider['settings'] ?? null) ? $provider['settings'] : array();
		if (!empty($provider_settings['_decryption_failed']) || !empty($provider['_decryption_failed'])) {
			$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
			if (empty($attached_file) && is_array($metadata) && !empty($metadata['file'])) {
				$attached_file = $metadata['file'];
			}
			$file_path   = $attached_file ?: 'unknown';
			$provider_id = !empty($provider['id']) ? (int) $provider['id'] : 0;
			FileChi_DB::log_transfer($attachment_id, $provider_id, $file_path, 0, 'failed', __('credentials cannot be decrypted', 'filechi'));
			FileChi_Storage_Factory::create($provider);
			return false;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return false;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attached_file) && is_array($metadata) && !empty($metadata['file'])) {
			$attached_file = $metadata['file'];
		}

		if (empty($attached_file)) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path($upload_dir['basedir']);

		if ($metadata === null) {
			$metadata = wp_get_attachment_metadata($attachment_id);
		}
		if (!is_array($metadata)) {
			$metadata = array();
		}

		$settings   = wp_parse_args(get_option('filechi_settings', array()), FileChi_Activator::default_settings());
		$keep_local = !empty($settings['keep_local_files']);

		$dir_prefix = dirname($attached_file);
		$dir_prefix = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		// Collect all files that belong to this attachment: relative_path => array('abs_path' => ..., 'expected_size' => ...)
		$files_to_process = array();

		// 1. Main file
		$main_local     = $basedir . '/' . $attached_file;
		$main_size_meta = isset($metadata['filesize']) ? absint($metadata['filesize']) : 0;
		$files_to_process[$attached_file] = array(
			'abs_path'      => $main_local,
			'expected_size' => $main_size_meta,
		);

		// 2. Scaled original image (WP 5.3+)
		if (!empty($metadata['original_image'])) {
			$orig_rel   = $dir_prefix . $metadata['original_image'];
			$orig_local = $basedir . '/' . $orig_rel;
			if (!isset($files_to_process[$orig_rel])) {
				$files_to_process[$orig_rel] = array(
					'abs_path'      => $orig_local,
					'expected_size' => 0,
				);
			}
		}

		// 3. Intermediate sub-sizes
		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size_data) {
				if (!empty($size_data['file'])) {
					$size_rel   = $dir_prefix . $size_data['file'];
					$size_local = $basedir . '/' . $size_rel;
					$size_meta  = isset($size_data['filesize']) ? absint($size_data['filesize']) : 0;
					$files_to_process[$size_rel] = array(
						'abs_path'      => $size_local,
						'expected_size' => $size_meta,
					);
				}
			}
		}

		// 4. Edited-image backup sizes (_wp_attachment_backup_sizes)
		$backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
		if (is_array($backup_sizes)) {
			foreach ($backup_sizes as $backup) {
				if (!empty($backup['file'])) {
					$bk_rel   = $dir_prefix . $backup['file'];
					$bk_local = $basedir . '/' . $bk_rel;
					$bk_size  = isset($backup['filesize']) ? absint($backup['filesize']) : 0;
					if (!isset($files_to_process[$bk_rel])) {
						$files_to_process[$bk_rel] = array(
							'abs_path'      => $bk_local,
							'expected_size' => $bk_size,
						);
					}
				}
			}
		}

		// Guard: If any file is protected and provider is SFTP/FTPS without protected_path, refuse offload
		$has_protected = false;
		foreach (array_keys($files_to_process) as $rel_path) {
			if (self::is_protected_file($rel_path, $attachment_id)) {
				$has_protected = true;
				break;
			}
		}

		$provider_driver = $provider['driver'] ?? $provider['type'] ?? '';
		if ($has_protected && ($provider_driver === 'sftp' || $provider_driver === 'ftps')) {
			$protected_path = trim($provider['settings']['protected_path'] ?? '');
			if (empty($protected_path)) {
				update_option('filechi_protected_path_missing_notice', array(
					'attachment_id' => $attachment_id,
					'provider_name' => $provider['name'] ?? $provider_driver,
					'time'          => time(),
				));
				$main_size = file_exists($main_local) ? (int) filesize($main_local) : 0;
				FileChi_DB::log_transfer(
					$attachment_id,
					$provider['id'],
					$attached_file,
					$main_size,
					'failed',
					__('Protected WooCommerce file cannot be offloaded: SFTP/FTPS provider has no protected_path configured.', 'filechi')
				);
				return false;
			}
		}

		// Phase 1: Reconcile or upload every file
		$all_succeeded  = true;
		$is_unretryable = false;
		$uploaded_files = array();

		// Retrieve existing log entries for this attachment and provider
		$existing_logs = FileChi_DB::get_attachment_logs_by_file($attachment_id, $provider['id']);

		foreach ($files_to_process as $rel_path => $file_info) {
			// Item 7.3: Do not redo finished work. Skip files whose log row for this provider
			// is success/reconciled and whose remote copy still exists.
			if (isset($existing_logs[$rel_path])) {
				$prev_status = $existing_logs[$rel_path]['status'];
				if ($prev_status === 'transferred' || $prev_status === 'reconciled') {
					if ($driver->exists($rel_path)) {
						// Already satisfied and remote copy confirmed: skip without re-uploading
						continue;
					}
				}
			}

			$abs_path      = $file_info['abs_path'];
			$expected_size = $file_info['expected_size'];
			$local_exists  = file_exists($abs_path);

			if ($local_exists) {
				$filesize = (int) filesize($abs_path);
				$success  = $driver->upload($abs_path, $rel_path);

				if ($success) {
					$uploaded_files[$rel_path] = $abs_path;
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'transferred');
				} else {
					$all_succeeded = false;
					$reason        = $driver->get_last_error();
					if (empty($reason)) {
						$reason = __('Upload or verification failed for remote storage.', 'filechi');
					}
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'failed', $reason);
					update_post_meta($attachment_id, '_filechi_last_error', $reason);
					break;
				}
			} else {
				// Local file missing: check remote copy
				$remote_exists = $driver->exists($rel_path);
				if ($remote_exists) {
					$remote_size = $driver->get_size($rel_path);
					if ($expected_size > 0 && $remote_size >= 0 && $remote_size !== $expected_size) {
						$all_succeeded = false;
						$reason        = sprintf(
							'Remote size mismatch (expected: %d, actual: %d)',
							$expected_size,
							$remote_size
						);
						FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, max(0, $remote_size), 'failed', $reason);
						update_post_meta($attachment_id, '_filechi_last_error', $reason);
						break;
					}

					// Count file as satisfied and log reconciled
					$logged_size = ($remote_size >= 0) ? $remote_size : $expected_size;
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $logged_size, 'reconciled');
				} else {
					// Missing locally AND remotely: real failure, no retries
					$all_succeeded  = false;
					$is_unretryable = true;
					$reason         = sprintf('File not found locally or remotely: %s', $rel_path);
					FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, 0, 'failed', $reason);
					update_post_meta($attachment_id, '_filechi_last_error', $reason);
					break;
				}
			}
		}

		// Phase 2: If all files succeeded, commit offload metadata and clean up local files (if enabled)
		if ($all_succeeded) {
			update_post_meta($attachment_id, '_filechi_offloaded', 1);
			update_post_meta($attachment_id, '_filechi_provider_id', $provider['id']);
			delete_post_meta($attachment_id, '_filechi_retry_scheduled');
			delete_post_meta($attachment_id, '_filechi_failure_count');
			delete_post_meta($attachment_id, '_filechi_given_up');
			delete_post_meta($attachment_id, '_filechi_last_error');
			delete_post_meta($attachment_id, '_filechi_unretryable');

			if (!$keep_local) {
				foreach ($uploaded_files as $abs_path) {
					if (file_exists($abs_path)) {
						@unlink($abs_path);
					}
				}
			}

			return true;
		}

		// Failure phase: handle failure count, give-up limits, and exponential backoff retry
		self::handle_offload_failure($attachment_id, $is_unretryable);
		return false;
		} finally {
			self::release_lock($attachment_id, $lock_token);
		}
	}

	/**
	 * Acquires a unique per-attachment lock with a 120-second TTL.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string|false Unique token if acquired, false if held.
	 */
	public static function acquire_lock($attachment_id) {
		$lock_key = 'filechi_lock_' . absint($attachment_id);
		$existing = get_transient($lock_key);
		if (!empty($existing)) {
			return false;
		}

		$token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('filechi_lock_', true);
		$set   = set_transient($lock_key, $token, 120);
		return $set ? $token : false;
	}

	/**
	 * Releases the per-attachment lock only if the token matches.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $token Unique lock token.
	 * @return bool
	 */
	public static function release_lock($attachment_id, $token) {
		$lock_key = 'filechi_lock_' . absint($attachment_id);
		$current  = get_transient($lock_key);
		if ($current === $token) {
			return delete_transient($lock_key);
		}
		return false;
	}

	/**
	 * Checks if an attachment is currently locked by a worker.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function is_locked($attachment_id) {
		$lock_key = 'filechi_lock_' . absint($attachment_id);
		return !empty(get_transient($lock_key));
	}

	/**
	 * Handles failure tracking, give-up limits (at 5 failures or unretryable),
	 * and exponential backoff retry scheduling.
	 *
	 * @param int  $attachment_id
	 * @param bool $is_unretryable
	 */
	public static function handle_offload_failure($attachment_id, $is_unretryable = false) {
		$failures = (int) get_post_meta($attachment_id, '_filechi_failure_count', true) + 1;
		update_post_meta($attachment_id, '_filechi_failure_count', $failures);

		if ($is_unretryable || $failures >= 5) {
			// At 5 failures or unretryable, set given_up and do not retry
			update_post_meta($attachment_id, '_filechi_given_up', 1);
			delete_post_meta($attachment_id, '_filechi_retry_scheduled');
			if (function_exists('as_unschedule_action')) {
				as_unschedule_action('filechi_retry_attachment_offload', array('attachment_id' => $attachment_id), 'filechi');
			}
			return;
		}

		// Schedule retry later with exponential backoff: 60s * 2^(failures - 1), capped at 30 minutes (1800s)
		$delay = min(1800, 60 * (int) pow(2, max(0, $failures - 1)));
		self::schedule_retry($attachment_id, $delay);
	}

	/**
	 * Schedules a single retry action via Action Scheduler with backoff delay.
	 *
	 * @param int $attachment_id
	 * @param int $delay Delay in seconds from current time (default 60s).
	 */
	public static function schedule_retry($attachment_id, $delay = 60) {
		if (function_exists('as_unschedule_action')) {
			as_unschedule_action('filechi_retry_attachment_offload', array('attachment_id' => $attachment_id), 'filechi');
		}

		update_post_meta($attachment_id, '_filechi_retry_scheduled', 1);

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, 'filechi_retry_attachment_offload', array('attachment_id' => $attachment_id), 'filechi');
		}
	}

	/**
	 * Handles Action Scheduler retry execution.
	 *
	 * @param int $attachment_id
	 */
	public function handle_retry_action($attachment_id) {
		self::offload_attachment($attachment_id);
	}

	/**
	 * Determines whether a file/attachment is protected (e.g. WooCommerce downloadable product file).
	 *
	 * @param string $path File path or relative key.
	 * @param int    $attachment_id Optional attachment post ID.
	 * @return bool
	 */
	public static function is_protected_file($path, $attachment_id = 0) {
		$clean_path = str_replace('\\', '/', (string) $path);

		// Per AGENTS.md §7: a file is protected if and only if its relative path is under woocommerce_uploads/
		return strpos($clean_path, 'woocommerce_uploads/') !== false || strpos($clean_path, 'woocommerce_uploads') === 0;
	}

	/**
	 * Rewrites attachment URL to remote storage URL.
	 *
	 * @param string $url Original attachment URL.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string Remote URL if offloaded.
	 */
	public function filter_attachment_url($url, $attachment_id) {
		$is_offloaded = get_post_meta($attachment_id, '_filechi_offloaded', true);
		if (!$is_offloaded) {
			return $url;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attached_file)) {
			return $url;
		}

		$provider_id = get_post_meta($attachment_id, '_filechi_provider_id', true);
		$driver      = FileChi_Storage_Factory::create($provider_id ?: FileChi_DB::get_default_provider());

		if (!$driver) {
			return $url;
		}

		// Never expose permanent public URLs for protected WooCommerce downloads
		if (self::is_protected_file($attached_file, $attachment_id)) {
			return $driver->get_signed_url($attached_file);
		}

		return $driver->get_url($attached_file);
	}

	/**
	 * Filters image srcset candidate URLs so all responsive image sizes point to remote storage.
	 *
	 * @param array|false $sources Sources array or false.
	 * @param array  $size_array Size array.
	 * @param string $image_src Image source URL.
	 * @param array  $image_meta Image metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Modified sources.
	 */
	public function filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
		$is_offloaded = get_post_meta($attachment_id, '_filechi_offloaded', true);
		if (!$is_offloaded || !is_array($sources)) {
			return $sources;
		}

		$provider_id = get_post_meta($attachment_id, '_filechi_provider_id', true);
		$driver      = FileChi_Storage_Factory::create($provider_id ?: FileChi_DB::get_default_provider());

		if (!$driver) {
			return $sources;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		$dir_prefix    = dirname($attached_file);
		$dir_prefix    = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		foreach ($sources as &$source) {
			// Extract filename from URL
			$filename = basename(wp_parse_url($source['url'], PHP_URL_PATH));
			$rel_path = $dir_prefix . $filename;
			$source['url'] = $driver->get_url($rel_path);
		}

		return $sources;
	}

	/**
	 * Filters image src attributes for specific size queries (thumbnail, medium, large, etc.).
	 *
	 * @param array|false  $image Array of image data [url, width, height, is_intermediate] or false.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|array $size Requested size.
	 * @param bool         $icon Whether icon is requested.
	 * @return array|false
	 */
	public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
		if (!is_array($image) || empty($image[0])) {
			return $image;
		}

		$is_offloaded = get_post_meta($attachment_id, '_filechi_offloaded', true);
		if (!$is_offloaded) {
			return $image;
		}

		$provider_id = get_post_meta($attachment_id, '_filechi_provider_id', true);
		$driver      = FileChi_Storage_Factory::create($provider_id ?: FileChi_DB::get_default_provider());

		if (!$driver) {
			return $image;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		$dir_prefix    = dirname($attached_file);
		$dir_prefix    = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		$filename = basename(wp_parse_url($image[0], PHP_URL_PATH));
		$image[0] = $driver->get_url($dir_prefix . $filename);

		return $image;
	}

	/**
	 * Cleans up remote files upon attachment deletion in WordPress.
	 *
	 * @param int $attachment_id Attachment ID being deleted.
	 */
	public function handle_delete_attachment($attachment_id) {
		$settings = wp_parse_args(get_option('filechi_settings', array()), FileChi_Activator::default_settings());
		if (!empty($settings['keep_remote_on_delete'])) {
			return; // User configured to preserve remote copies
		}

		$is_offloaded = get_post_meta($attachment_id, '_filechi_offloaded', true);
		if (!$is_offloaded) {
			return;
		}

		$provider_id = get_post_meta($attachment_id, '_filechi_provider_id', true);
		$driver      = FileChi_Storage_Factory::create($provider_id ?: FileChi_DB::get_default_provider());

		if (!$driver) {
			return;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attached_file)) {
			return;
		}

		$metadata   = wp_get_attachment_metadata($attachment_id);
		$dir_prefix = dirname($attached_file);
		$dir_prefix = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		$files_to_delete = array($attached_file);

		if (!empty($metadata['original_image'])) {
			$files_to_delete[] = $dir_prefix . $metadata['original_image'];
		}

		if (!empty($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size_data) {
				if (!empty($size_data['file'])) {
					$files_to_delete[] = $dir_prefix . $size_data['file'];
				}
			}
		}

		// Include edited-image backup_sizes
		$backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
		if (is_array($backup_sizes)) {
			foreach ($backup_sizes as $backup) {
				if (!empty($backup['file'])) {
					$files_to_delete[] = $dir_prefix . $backup['file'];
				}
			}
		}

		$files_to_delete = array_unique($files_to_delete);

		foreach ($files_to_delete as $rel_path) {
			$deleted = $driver->delete($rel_path);
			if ($deleted) {
				FileChi_DB::log_transfer($attachment_id, $provider_id, $rel_path, 0, 'deleted');
			} else {
				FileChi_DB::log_transfer($attachment_id, $provider_id, $rel_path, 0, 'failed', __('Failed to delete remote file.', 'filechi'));
			}
		}
	}
}
