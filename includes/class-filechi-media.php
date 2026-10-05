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

		// Hook attachment upload lifecycle
		add_filter('wp_handle_upload', array($this, 'handle_upload'));
		add_filter('wp_handle_sideload', array($this, 'handle_upload'));

		// Offload images after all thumbnails and sub-sizes are generated
		add_filter('wp_generate_attachment_metadata', array($this, 'handle_image_metadata'), 20, 2);

		// Offload non-image attachments
		add_action('add_attachment', array($this, 'handle_non_image_attachment'), 20);

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
	 * Intercepts upload errors or updates context during wp_handle_upload.
	 *
	 * @param array $file Array with 'file', 'url', 'type'.
	 * @return array
	 */
	public function handle_upload($file) {
		// Verify provider exists and is ready
		$provider = FileChi_DB::get_default_provider();
		if (!$provider) {
			return $file;
		}

		return $file;
	}

	/**
	 * Offloads image attachments along with all intermediate sub-sizes.
	 *
	 * @param array $metadata Attachment metadata array.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array
	 */
	public function handle_image_metadata($metadata, $attachment_id) {
		$provider = FileChi_DB::get_default_provider();
		if (!$provider) {
			return $metadata;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return $metadata;
		}

		$upload_dir = wp_upload_dir();
		$basedir    = wp_normalize_path($upload_dir['basedir']);

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attached_file) && !empty($metadata['file'])) {
			$attached_file = $metadata['file'];
		}

		if (empty($attached_file)) {
			return $metadata;
		}

		$settings   = get_option('filechi_settings', array());
		$keep_local = !empty($settings['keep_local_files']);

		$dir_prefix = dirname($attached_file);
		$dir_prefix = ($dir_prefix === '.' || $dir_prefix === '/') ? '' : $dir_prefix . '/';

		// Files to offload: relative_path => absolute_local_path
		$files_to_offload = array();

		// 1. Main image file
		$main_local = $basedir . '/' . $attached_file;
		if (file_exists($main_local)) {
			$files_to_offload[$attached_file] = $main_local;
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
			foreach ($metadata['sizes'] as $size_name => $size_data) {
				if (!empty($size_data['file'])) {
					$size_rel   = $dir_prefix . $size_data['file'];
					$size_local = $basedir . '/' . $size_rel;
					if (file_exists($size_local)) {
						$files_to_offload[$size_rel] = $size_local;
					}
				}
			}
		}

		$all_succeeded = true;

		foreach ($files_to_offload as $rel_path => $abs_path) {
			$filesize = filesize($abs_path);
			$success  = $driver->upload($abs_path, $rel_path);

			if ($success) {
				FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'transferred');

				if (!$keep_local) {
					@unlink($abs_path);
				}
			} else {
				$all_succeeded = false;
				FileChi_DB::log_transfer($attachment_id, $provider['id'], $rel_path, $filesize, 'failed', __('Upload to remote storage failed.', 'filechi'));
			}
		}

		if ($all_succeeded) {
			update_post_meta($attachment_id, '_filechi_offloaded', 1);
			update_post_meta($attachment_id, '_filechi_provider_id', $provider['id']);
		}

		return $metadata;
	}

	/**
	 * Offloads non-image attachments (PDF, videos, zip, documents).
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function handle_non_image_attachment($attachment_id) {
		$mime_type = get_post_mime_type($attachment_id);

		// Images are handled via handle_image_metadata once sizes are generated
		if (strpos($mime_type, 'image/') === 0) {
			return;
		}

		$provider = FileChi_DB::get_default_provider();
		if (!$provider) {
			return;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (empty($attached_file)) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$local_file = wp_normalize_path($upload_dir['basedir']) . '/' . $attached_file;

		if (!file_exists($local_file)) {
			return;
		}

		$settings   = get_option('filechi_settings', array());
		$keep_local = !empty($settings['keep_local_files']);
		$filesize   = filesize($local_file);

		$success = $driver->upload($local_file, $attached_file);

		if ($success) {
			FileChi_DB::log_transfer($attachment_id, $provider['id'], $attached_file, $filesize, 'transferred');
			update_post_meta($attachment_id, '_filechi_offloaded', 1);
			update_post_meta($attachment_id, '_filechi_provider_id', $provider['id']);

			if (!$keep_local) {
				@unlink($local_file);
			}
		} else {
			FileChi_DB::log_transfer($attachment_id, $provider['id'], $attached_file, $filesize, 'failed', __('Upload failed for non-image attachment.', 'filechi'));
		}
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

		foreach ($files_to_delete as $rel_path) {
			$driver->delete($rel_path);
			FileChi_DB::log_transfer($attachment_id, $provider_id, $rel_path, 0, 'deleted');
		}
	}
}
