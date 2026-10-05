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
	 * Constructor.
	 */
	public function __construct() {
		// Declare HPOS compatibility before WooCommerce initializes
		add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

		// Hook downloadable product file resolution
		add_filter('woocommerce_file_download_path', array($this, 'filter_download_path'), 20, 3);
		add_filter('woocommerce_customer_get_downloadable_products', array($this, 'filter_customer_downloadable_products'), 20, 1);
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
	 * @param string      $file_path Configured file URL/path.
	 * @param int         $product_id Product ID.
	 * @param string|int  $download_id Download ID.
	 * @return string
	 */
	public function filter_download_path($file_path, $product_id, $download_id) {
		$settings = get_option('filechi_settings', array());
		if (empty($settings['wc_signed_downloads'] ?? 1)) {
			return $file_path;
		}

		$expiry = absint($settings['wc_download_expiry'] ?? 900);
		if ($expiry <= 0) {
			$expiry = 900; // 15 minutes default
		}

		// Check if file_path matches an offloaded attachment or remote storage key
		$relative_key = $this->extract_relative_key($file_path);
		if (empty($relative_key)) {
			return $file_path;
		}

		$provider = FileChi_DB::get_default_provider();
		if (!$provider) {
			return $file_path;
		}

		$driver = FileChi_Storage_Factory::create($provider);
		if (!$driver) {
			return $file_path;
		}

		// Return time-limited signed download URL
		return $driver->get_signed_url($relative_key, $expiry);
	}

	/**
	 * Filters downloadable product links in the user account / order view.
	 *
	 * @param array $downloads List of downloadable products.
	 * @return array
	 */
	public function filter_customer_downloadable_products($downloads) {
		// Handled at generation time via filter_download_path so signatures stay fresh
		return $downloads;
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

		// If URL matches uploads baseurl
		if (strpos($path_or_url, $base_url) === 0) {
			return ltrim(substr($path_or_url, strlen($base_url)), '/');
		}

		// If path matches local uploads basedir
		if (strpos($normalized, $base_dir) === 0) {
			return ltrim(substr($normalized, strlen($base_dir)), '/');
		}

		// If already a relative path
		if (strpos($path_or_url, 'http://') === false && strpos($path_or_url, 'https://') === false) {
			return ltrim(str_replace('\\', '/', $path_or_url), '/');
		}

		return null;
	}
}
