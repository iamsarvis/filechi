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
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package           FileChi
 */

defined('ABSPATH') || exit;

// Define core constants
if (!defined('FILECHI_VERSION')) {
	define('FILECHI_VERSION', '1.0.0');
}
if (!defined('FILECHI_FILE')) {
	define('FILECHI_FILE', __FILE__);
}
if (!defined('FILECHI_DIR')) {
	define('FILECHI_DIR', function_exists('plugin_dir_path') ? plugin_dir_path(__FILE__) : dirname(__FILE__) . '/');
}
if (!defined('FILECHI_URL')) {
	define('FILECHI_URL', function_exists('plugin_dir_url') ? plugin_dir_url(__FILE__) : '');
}
if (!defined('FILECHI_MIN_PHP')) {
	define('FILECHI_MIN_PHP', '7.4');
}
if (!defined('FILECHI_MIN_WP')) {
	define('FILECHI_MIN_WP', '6.0');
}

/**
 * Check PHP and WordPress version requirements before loading.
 */
function filechi_check_requirements() {
	if (version_compare(PHP_VERSION, FILECHI_MIN_PHP, '<')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p>' . esc_html(sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				__('FileChi requires PHP %1$s or higher. Your server is running PHP %2$s. Please upgrade your PHP version.', 'filechi'),
				FILECHI_MIN_PHP,
				PHP_VERSION
			)) . '</p></div>';
		});
		return false;
	}

	global $wp_version;
	if (!empty($wp_version) && version_compare($wp_version, FILECHI_MIN_WP, '<')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p>' . esc_html(sprintf(
				/* translators: 1: Required WP version */
				__('FileChi requires WordPress %s or higher. Please update your WordPress installation.', 'filechi'),
				FILECHI_MIN_WP
			)) . '</p></div>';
		});
		return false;
	}

	return true;
}

if (!filechi_check_requirements()) {
	return;
}

// Load Action Scheduler version-safe loader before plugins_loaded
if (file_exists(FILECHI_DIR . 'includes/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
	require_once FILECHI_DIR . 'includes/vendor/woocommerce/action-scheduler/action-scheduler.php';
} elseif (file_exists(FILECHI_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php')) {
	require_once FILECHI_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
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
	require_once FILECHI_DIR . 'includes/class-filechi-path.php';
	require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
	require_once FILECHI_DIR . 'includes/class-filechi-db.php';
	require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
	require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
	require_once FILECHI_DIR . 'includes/class-filechi-media.php';
	require_once FILECHI_DIR . 'includes/class-filechi-woocommerce.php';
	require_once FILECHI_DIR . 'includes/class-filechi-migration.php';

	// Load textdomain for i18n
	load_plugin_textdomain('filechi', false, dirname(plugin_basename(FILECHI_FILE)) . '/languages');

	// Run database upgrade routine if schema is behind
	FileChi_Activator::maybe_upgrade();
	FileChi_Activator::check_upgrade_notice();

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

	// Handle background files migration batches via Action Scheduler
	add_action('filechi_migrate_files_batch', function () {
		FileChi_DB::run_files_migration();
	});

	// Handle secure time-limited downloads for SFTP/FTPS
	add_action('init', 'filechi_handle_signed_download', 1);
}
add_action('plugins_loaded', 'filechi_init');

/**
 * Handles signed, time-limited download requests for transports without native pre-signed URLs.
 */
function filechi_handle_signed_download() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public download link authenticated via HMAC token and expiration timestamp.
	if (!isset($_GET['filechi_action']) || $_GET['filechi_action'] !== 'download') {
		return;
	}

	// Do not use sanitize_text_field() on paths; validate strictly via FileChi_Path::validate().
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path validated strictly via FileChi_Path::validate().
	$raw_file    = isset($_GET['filechi_file']) ? (string) wp_unslash($_GET['filechi_file']) : '';
	$expires     = isset($_GET['filechi_expires']) ? absint($_GET['filechi_expires']) : 0;
	$provider_id = isset($_GET['filechi_provider']) ? absint($_GET['filechi_provider']) : 0;
	$token       = isset($_GET['filechi_token']) ? sanitize_text_field(wp_unslash($_GET['filechi_token'])) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if (empty($raw_file) || empty($expires) || empty($token) || empty($provider_id)) {
		wp_die(esc_html__('Invalid download request parameters.', 'filechi'), 400);
	}

	$file = rawurldecode($raw_file);

	// Verify expiration
	if (time() > $expires) {
		wp_die(esc_html__('This download link has expired. Please request a new link from your account.', 'filechi'), 403);
	}

	// Verify HMAC signature binding provider_id|file|expires
	$expected_token = hash_hmac('sha256', $provider_id . '|' . $file . '|' . $expires, wp_salt('nonce'));
	if (!hash_equals($expected_token, $token)) {
		wp_die(esc_html__('Invalid or tampered download signature.', 'filechi'), 403);
	}

	// Validate path strictly using Step 4 validator (invalid -> 404)
	try {
		$file = FileChi_Path::validate($file);
	} catch (InvalidArgumentException $e) {
		wp_die(esc_html__('File not found.', 'filechi'), 404);
	}

	// Serve only keys that exist as transferred for that provider in filechi_logs
	if (!FileChi_DB::is_file_transferred($provider_id, $file)) {
		wp_die(esc_html__('File not found.', 'filechi'), 404);
	}

	// Retrieve specific provider bound to the download token
	$provider = FileChi_DB::get_provider($provider_id, true);
	if (!$provider) {
		wp_die(esc_html__('Remote storage provider not found.', 'filechi'), 404);
	}

	$driver = FileChi_Storage_Factory::create($provider);
	if (!$driver) {
		wp_die(esc_html__('Storage driver unavailable.', 'filechi'), 500);
	}

	// Verify remote file existence before sending any headers
	try {
		$file_exists = $driver->exists($file);
	} catch (\Exception $e) {
		wp_die(esc_html__('Remote storage connection error.', 'filechi'), 500);
	}

	if (!$file_exists) {
		wp_die(esc_html__('File not found.', 'filechi'), 404);
	}

	// Retrieve file size when protocol provides it cheaply
	$size = -1;
	try {
		$size = $driver->get_size($file);
	} catch (\Exception $e) {
		$size = -1;
	}

	// Clear all active output buffers to prevent corruption and reduce memory usage
	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	$filename = basename($file);
	$headers  = filechi_build_download_headers($filename, $size);

	// Guard time limit and abort handling for large streaming downloads
	if (function_exists('set_time_limit') && false === strpos((string) ini_get('disable_functions'), 'set_time_limit')) {
		@set_time_limit(0);
	}
	if (function_exists('ignore_user_abort')) {
		@ignore_user_abort(true);
	}

	// Send strict streaming download headers
	nocache_headers();
	$GLOBALS['filechi_last_download_headers'] = $headers;
	foreach ($headers as $h_name => $h_val) {
		header($h_name . ': ' . $h_val);
	}

	// Stream chunk by chunk directly to output without loading file into memory
	$success = $driver->stream_to_output($file, 1048576);
	if (!$success) {
		error_log(sprintf('FileChi: Streaming failed for file "%s" from provider ID %d. Error: %s', $file, $provider_id, $driver->get_last_error()));
		exit;
	}
	exit;
}

/**
 * Builds RFC 5987 compliant streaming download headers.
 *
 * @param string $filename  Original file name.
 * @param int    $size      File size in bytes (-1 if unknown).
 * @param string $mime_type Optional MIME content type.
 * @return array<string, string> Array of header name => value.
 */
function filechi_build_download_headers($filename, $size = -1, $mime_type = '') {
	if (empty($mime_type)) {
		$file_type = wp_check_filetype($filename);
		$mime_type = !empty($file_type['type']) ? $file_type['type'] : 'application/octet-stream';
	}

	// Compute ASCII fallback filename for RFC 5987 Content-Disposition
	$ext        = pathinfo($filename, PATHINFO_EXTENSION);
	$base       = pathinfo($filename, PATHINFO_FILENAME);
	$ascii_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $base);
	$ascii_base = trim((string) $ascii_base, '_-');
	if ($ascii_base === '' || !preg_match('/[a-zA-Z0-9]/', $ascii_base)) {
		$ascii_base = 'download';
	}
	$ascii_filename = !empty($ext) ? $ascii_base . '.' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $ext) : $ascii_base;

	$headers = array(
		'Content-Type'           => $mime_type,
		'Content-Disposition'    => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii_filename, rawurlencode($filename)),
		'X-Content-Type-Options' => 'nosniff',
		'Cache-Control'          => 'private, no-transform, no-store, must-revalidate',
		'Pragma'                 => 'no-cache',
		'Expires'                => '0',
	);

	if ($size >= 0) {
		$headers['Content-Length'] = (string) (int) $size;
	}

	return $headers;
}
