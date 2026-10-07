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
		$settings = get_option('filechi_settings', array());

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
	 * @param int        $attachment_id Attachment post ID.
	 * @param array|null $provider      Optional provider record. Defaults to active provider.
	 * @param array|null $metadata      Optional metadata. Fetched if null.
	 * @return bool True if successfully offloaded, false otherwise.
	 */
	public static function offload_attachment($attachment_id, $provider = null, $metadata = null) {
		$attachment_id = absint($attachment_id);
		if (!$attachment_id) {
			return false;
		}

		if ($provider === null) {
			$provider = FileChi_DB::get_default_provider();
		}
		if (!$provider) {
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

		$settings   = get_option('filechi_settings', array());
		$keep_local = !empty($settings['keep_local_files']);

		$dir_prefix = dirname($attached_file);
		$dir_prefix = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		// Collect all files to offload: relative_path => absolute_local_path
		$files_to_offload = array();

		// 1. Main file
		$main_local = $basedir . '/' . $attached_file;
		if (file_exists($main_local)) {
			$files_to_offload[$attached_file] = $main_local;
		} else {
			FileChi_DB::log_transfer($attachment_id, $provider['id'], $attached_file, 0, 'failed', __('Main local file not found on disk.', 'filechi'));
			return false;
		}

		// 2. Scaled original image (WP 5.3+)
		if (!empty($metadata['original_image'])) {
			$orig_rel   = $dir_prefix . $metadata['original_image'];
			$orig_local = $basedir . '/' . $orig_rel;
			if (file_exists($orig_local)) {
				$files_to_offload[$orig_rel] = $orig_local;
			}
		}

		// 3. Intermediate sub-sizes
		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size_data) {
				if (!empty($size_data['file'])) {
					$size_rel   = $dir_prefix . $size_data['file'];
					$size_local = $basedir . '/' . $size_rel;
					if (file_exists($size_local)) {
						$files_to_offload[$size_rel] = $size_local;
					}
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
					if (file_exists($bk_local)) {
						$files_to_offload[$bk_rel] = $bk_local;
					}
				}
			}
		}

		// Guard: If any file is protected and provider is SFTP/FTPS without protected_path, refuse offload
		$has_protected = false;
		foreach ($files_to_offload as $rel_path => $abs_path) {
			if (self::is_protected_file($rel_path, $attachment_id)) {
				$has_protected = true;
				break;
			}
		}

		if ($has_protected && ($provider['type'] === 'sftp' || $provider['type'] === 'ftps')) {
			$protected_path = trim($provider['settings']['protected_path'] ?? '');
			if (empty($protected_path)) {
				update_option('filechi_protected_path_missing_notice', array(
					'attachment_id' => $attachment_id,
					'provider_name' => $provider['name'] ?? $provider['type'],
					'time'          => time(),
				));
				FileChi_DB::log_transfer(
					$attachment_id,
					$provider['id'],
					$attached_file,
					filesize($main_local),
					'failed',
					__('Protected WooCommerce file cannot be offloaded: SFTP/FTPS provider has no protected_path configured.', 'filechi')
				);
				return false;
			}
		}

		// Phase 1: Upload and verify every file
		$all_succeeded  = true;
		$uploaded_files = array();

		foreach ($files_to_offload as $rel_path => $abs_path) {
			$filesize = filesize($abs_path);
			$success  = $driver->upload($abs_path, $rel_path);

			if ($success) {
				$uploaded_files[$rel_path] = $abs_path;
				FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'transferred');
			} else {
				$all_succeeded = false;
				FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'failed', __('Upload or verification failed for remote storage.', 'filechi'));
				break; // Stop immediately on any failure
			}
		}

		// Phase 2: If all files succeeded, commit offload metadata and clean up local files (if enabled)
		if ($all_succeeded) {
			update_post_meta($attachment_id, '_filechi_offloaded', 1);
			update_post_meta($attachment_id, '_filechi_provider_id', $provider['id']);
			delete_post_meta($attachment_id, '_filechi_retry_scheduled');

			if (!$keep_local) {
				foreach ($files_to_offload as $abs_path) {
					if (file_exists($abs_path)) {
						@unlink($abs_path);
					}
				}
			}

			return true;
		}

		// Failure phase: delete nothing locally, do not set flag, schedule one retry
		self::schedule_retry($attachment_id);
		return false;
	}

	/**
	 * Schedules a single retry action via Action Scheduler.
	 *
	 * @param int $attachment_id
	 */
	public static function schedule_retry($attachment_id) {
		if (get_post_meta($attachment_id, '_filechi_retry_scheduled', true)) {
			return; // Only schedule one automatic retry
		}

		update_post_meta($attachment_id, '_filechi_retry_scheduled', 1);

		as_schedule_single_action(time() + 60, 'filechi_retry_attachment_offload', array('attachment_id' => $attachment_id), 'filechi');
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
		$clean_path = str_replace('\\', '/', $path);

		// WooCommerce protected uploads directory
		if (strpos($clean_path, 'woocommerce_uploads') !== false) {
			return true;
		}

		if ($attachment_id > 0) {
			if (get_post_meta($attachment_id, '_filechi_is_protected', true)) {
				return true;
			}

			global $wpdb;
			if (!empty($wpdb)) {
				$filename = basename($clean_path);
				$found    = $wpdb->get_var($wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_downloadable_files' AND meta_value LIKE %s LIMIT 1",
					'%' . $wpdb->esc_like($filename) . '%'
				));
				if (!empty($found)) {
					return true;
				}
			}
		}

		return false;
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
	 * @param array  $sources Sources array.
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
		$settings = get_option('filechi_settings', array());
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

		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
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
