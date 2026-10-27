<?php
/**
 * FileChi WooCommerce Integration
 *
 * Provides compatibility with WooCommerce 10.x & 11.x, declares High-Performance
 * Order Storage (HPOS) support, and generates signed, time-limited URLs for
 * downloadable product files.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_WooCommerce {

	/**
	 * Tracked signed URLs generated in current request.
	 *
	 * @var array<string, bool>
	 */
	private $signed_urls = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Declare HPOS compatibility before WooCommerce initializes
		add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

		// Hook downloadable product file resolution
		add_filter('woocommerce_file_download_path', array($this, 'filter_download_path'), 20, 3);

		// Force redirect mode for offloaded/signed downloads
		add_filter('woocommerce_file_download_method', array($this, 'filter_download_method'), 20, 3);

		// Intercept force downloads before WooCommerce tries local file reading
		add_action('woocommerce_download_file_force', array($this, 'handle_force_download'), 5, 2);
	}

	/**
	 * Explicitly declares compatibility with WooCommerce HPOS (custom order tables).
	 */
	public function declare_hpos_compatibility() {
		if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				FILECHI_FILE,
				true
			);
		}
	}

	/**
	 * Resolves WooCommerce downloadable product paths to signed, time-limited remote URLs.
	 *
	 * Maps the path to an attachment (_wp_attached_file). If _filechi_offloaded is not 1,
	 * returns the original path unchanged. If offloaded, uses that attachment's provider
	 * (_filechi_provider_id), not the default one.
	 *
	 * @param string      $file_path Configured file URL/path.
	 * @param int         $product_id Product ID.
	 * @param string|int  $download_id Download ID.
	 * @return string
	 */
	public function filter_download_path($file_path, $product_id, $download_id) {
		$settings = wp_parse_args(get_option('filechi_settings', array()), FileChi_Activator::default_settings());
		if (empty($settings['wc_signed_downloads'])) {
			return $file_path;
		}

		$expiry = absint($settings['wc_download_expiry']);
		if ($expiry <= 0) {
			$expiry = 900; // 15 minutes default
		}

		// Check if file_path matches an offloaded attachment or remote storage key
		$relative_key = $this->extract_relative_key($file_path);
		if (empty($relative_key)) {
			return $file_path;
		}

		$attachment_id = $this->get_attachment_id_by_key($relative_key);
		if (!$attachment_id) {
			return $file_path;
		}

		$is_offloaded = (int) get_post_meta($attachment_id, '_filechi_offloaded', true);
		if ($is_offloaded !== 1) {
			return $file_path;
		}

		$provider_id = (int) get_post_meta($attachment_id, '_filechi_provider_id', true);
		if (!$provider_id) {
			return $file_path;
		}

		$provider = FileChi_DB::get_provider($provider_id);
		if (!$provider) {
			return $file_path;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return $file_path;
		}

		try {
			// Return time-limited signed download URL
			$signed_url = $driver->get_signed_url($relative_key, $expiry);
			$this->signed_urls[$signed_url] = true;
			return $signed_url;
		} catch (\Exception $e) {
			return $file_path;
		}
	}

	/**
	 * Forces redirect download method for FileChi offloaded and signed downloads.
	 *
	 * Prevents WooCommerce from attempting a local filesystem read (Force Download)
	 * on remote or streaming proxy URLs which results in "File not found".
	 *
	 * @param string     $method     Current download method ('force', 'xsendfile', 'redirect').
	 * @param int|string $product_id Product ID.
	 * @param string     $file_path  File path or URL.
	 * @return string
	 */
	public function filter_download_method($method, $product_id, $file_path) {
		if ($this->is_filechi_download($file_path)) {
			return 'redirect';
		}

		return $method;
	}

	/**
	 * Intercepts forced downloads for FileChi signed or offloaded files to prevent
	 * WooCommerce from treating them as local server files and dying with "File not found".
	 *
	 * @param string $file_path File path or URL.
	 * @param string $filename  File name.
	 * @return void
	 */
	public function handle_force_download($file_path, $filename = '') {
		if ($this->is_filechi_download($file_path)) {
			header('Location: ' . $file_path);
			exit;
		}
	}

	/**
	 * Determines whether a file path/URL represents a FileChi offloaded or signed download.
	 *
	 * @param mixed $file_path File path or URL.
	 * @return bool
	 */
	public function is_filechi_download($file_path) {
		if (!is_string($file_path) || $file_path === '') {
			return false;
		}

		// Tracked URLs generated in this request by filter_download_path
		if (!empty($this->signed_urls[$file_path])) {
			return true;
		}

		// FileChi streaming proxy action
		if (strpos($file_path, 'filechi_action=download') !== false) {
			return true;
		}

		// S3 presigned URLs (AWS / ArvanCloud / ParsPack)
		if ((strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0)
			&& (strpos($file_path, 'X-Amz-Signature=') !== false || strpos($file_path, 'Signature=') !== false)) {
			return true;
		}

		return false;
	}

	/**
	 * Finds an attachment ID by its relative file key (_wp_attached_file).
	 *
	 * @param string $relative_key
	 * @return int Attachment ID or 0 if not found.
	 */
	private function get_attachment_id_by_key($relative_key) {
		global $wpdb;

		if (!isset($wpdb) || !is_object($wpdb)) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe core postmeta table interpolation.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative_key
			)
		);

		return $attachment_id ? (int) $attachment_id : 0;
	}

	/**
	 * Extracts relative upload key from a full URL or local path.
	 *
	 * @param string $path_or_url
	 * @return string|null
	 */
	private function extract_relative_key($path_or_url) {
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = wp_normalize_path($upload_dir['basedir']);

		$normalized = wp_normalize_path($path_or_url);
		$candidate  = null;

		// If URL matches uploads baseurl
		if (strpos($path_or_url, $base_url) === 0) {
			$candidate = ltrim(substr($path_or_url, strlen($base_url)), '/');
		} elseif (strpos($normalized, $base_dir) === 0) {
			// If path matches local uploads basedir
			$candidate = ltrim(substr($normalized, strlen($base_dir)), '/');
		} elseif (strpos($path_or_url, 'http://') === false && strpos($path_or_url, 'https://') === false) {
			// If already a relative path
			$candidate = $path_or_url;
		}

		if ($candidate !== null) {
			try {
				return FileChi_Path::validate($candidate);
			} catch (InvalidArgumentException $e) {
				return null;
			}
		}

		return null;
	}
}
