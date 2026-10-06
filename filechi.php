<?php
/**
 * Plugin Name:       FileChi
 * Plugin URI:        https://sobhanaskari.ir
 * Description:       Offload WordPress Media Library and WooCommerce attachments to secure remote storage (SFTP, FTPS, and S3-compatible: AWS S3, ArvanCloud, ParsPack).
 * Version:           1.0.0
 * Author:            Sobhan Askari
 * Author URI:        https://sobhanaskari.ir
 * Text Domain:       filechi
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.htm
 *
 * @package           FileChi
 */

defined('ABSPATH') || exit;

// Define core constants
define('FILECHI_VERSION', '1.0.0');
define('FILECHI_FILE', __FILE__);
define('FILECHI_DIR', plugin_dir_path(__FILE__));
define('FILECHI_URL', plugin_dir_url(__FILE__));
define('FILECHI_MIN_PHP', '7.4');
define('FILECHI_MIN_WP', '6.0');

/**
 * Check PHP and WordPress version requirements before loading.
 */
function filechi_check_requirements() {
	if (version_compare(PHP_VERSION, FILECHI_MIN_PHP, '<')) {
		add_action('admin_notices', function () {
			printf(
				'<div class="error"><p>%s</p></div>',
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__('FileChi requires PHP %1$s or higher. Your server is running PHP %2$s. Please upgrade your PHP version.', 'filechi'),
					FILECHI_MIN_PHP,
					PHP_VERSION
				)
			);
		});
		return false;
	}

	global $wp_version;
	if (version_compare($wp_version, FILECHI_MIN_WP, '<')) {
		add_action('admin_notices', function () {
			printf(
				'<div class="error"><p>%s</p></div>',
				sprintf(
					/* translators: 1: Required WP version */
					esc_html__('FileChi requires WordPress %s or higher. Please update your WordPress installation.', 'filechi'),
					FILECHI_MIN_WP
				)
			);
		});
		return false;
	}

	return true;
}

if (!filechi_check_requirements()) {
	return;
}

// Register activation and deactivation hooks
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
register_activation_hook(FILECHI_FILE, array('FileChi_Activator', 'activate'));
register_deactivation_hook(FILECHI_FILE, array('FileChi_Activator', 'deactivate'));

/**
 * Initializes the FileChi plugin components.
 */
function filechi_init() {
	// Load vendor autoloader for namespace-scoped phpseclib 3.x
	$vendor_autoload = FILECHI_DIR . 'includes/vendor/autoload.php';
	if (file_exists($vendor_autoload)) {
		require_once $vendor_autoload;
	}

	// Core classes
	require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
	require_once FILECHI_DIR . 'includes/class-filechi-db.php';
	require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
	require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
	require_once FILECHI_DIR . 'includes/class-filechi-media.php';
	require_once FILECHI_DIR . 'includes/class-filechi-woocommerce.php';
	require_once FILECHI_DIR . 'includes/class-filechi-migration.php';

	// Load textdomain for i18n
	load_plugin_textdomain('filechi', false, dirname(plugin_basename(FILECHI_FILE)) . '/languages');

	// Instantiate subsystems
	new FileChi_Media();
	new FileChi_WooCommerce();
	new FileChi_Migration();

	// Register REST API routes
	add_action('rest_api_init', function () {
		require_once FILECHI_DIR . 'includes/class-filechi-rest.php';
		$controller = new FileChi_REST();
		$controller->register_routes();
	});

	// Admin UI
	if (is_admin()) {
		require_once FILECHI_DIR . 'includes/class-filechi-admin.php';
		new FileChi_Admin();
	}

	// Handle secure time-limited downloads for SFTP/FTPS
	add_action('init', 'filechi_handle_signed_download', 1);
}
add_action('plugins_loaded', 'filechi_init');

/**
 * Handles signed, time-limited download requests for transports without native pre-signed URLs.
 */
function filechi_handle_signed_download() {
	if (!isset($_GET['filechi_action']) || $_GET['filechi_action'] !== 'download') {
		return;
	}

	$file    = isset($_GET['filechi_file']) ? sanitize_text_field(wp_unslash($_GET['filechi_file'])) : '';
	$expires = isset($_GET['filechi_expires']) ? absint($_GET['filechi_expires']) : 0;
	$token   = isset($_GET['filechi_token']) ? sanitize_text_field(wp_unslash($_GET['filechi_token'])) : '';

	// Clean path and prevent directory traversal
	$file = str_replace(array('../', '..\\', '\\'), array('', '', '/'), rawurldecode($file));
	$file = ltrim($file, '/');

	if (empty($file) || empty($expires) || empty($token)) {
		wp_die(esc_html__('Invalid download request parameters.', 'filechi'), 400);
	}

	// Verify expiration
	if (time() > $expires) {
		wp_die(esc_html__('This download link has expired. Please request a new link from your account.', 'filechi'), 403);
	}

	// Verify HMAC signature
	$expected_token = hash_hmac('sha256', $file . '|' . $expires, wp_salt('nonce'));
	if (!hash_equals($expected_token, $token)) {
		wp_die(esc_html__('Invalid or tampered download signature.', 'filechi'), 403);
	}

	// Retrieve driver
	$provider = FileChi_DB::get_default_provider();
	if (!$provider) {
		wp_die(esc_html__('Remote storage provider not found.', 'filechi'), 500);
	}

	$driver = FileChi_Storage_Factory::create($provider);
	if (!$driver) {
		wp_die(esc_html__('Storage driver unavailable.', 'filechi'), 500);
	}

	// Clear all active output buffers to prevent corruption and reduce memory usage
	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	$filename  = basename($file);
	$file_type = wp_check_filetype($filename);
	$mime_type = !empty($file_type['type']) ? $file_type['type'] : 'application/octet-stream';

	// Send strict streaming download headers
	nocache_headers();
	header('Content-Type: ' . $mime_type);
	header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
	header('Cache-Control: private, no-transform, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	// Stream chunk by chunk directly to output without loading file into memory
	$success = $driver->stream_to_output($file, 1048576);
	if (!$success) {
		wp_die(esc_html__('Failed to stream remote file.', 'filechi'), 500);
	}
	exit;
}
