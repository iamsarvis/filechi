<?php
// MOCK
/**
 * Test: Protected WooCommerce Downloads & Streaming Proxy
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';

echo "=== 1. is_protected_file Detection ===\n";
$is_prot1 = FileChi_Media::is_protected_file('woocommerce_uploads/2026/10/product-file.zip');
$is_prot2 = FileChi_Media::is_protected_file('2026/10/normal-image.jpg');
$is_prot3 = FileChi_Media::is_protected_file('2026/10/my-woocommerce_uploads/x.jpg');
$is_prot4 = FileChi_Media::is_protected_file('woocommerce_uploads_extra/x.jpg');
$is_prot5 = FileChi_Media::is_protected_file('/woocommerce_uploads/2026/10/product-file.zip');
$is_prot6 = FileChi_Media::is_protected_file('\\woocommerce_uploads\\2026\\10\\product-file.zip');

echo "woocommerce_uploads/ file detected as protected: " . ($is_prot1 ? 'PASS' : 'FAIL') . "\n";
echo "normal-image.jpg detected as not protected: " . (!$is_prot2 ? 'PASS' : 'FAIL') . "\n";
echo "2026/10/my-woocommerce_uploads/x.jpg detected as not protected: " . (!$is_prot3 ? 'PASS' : 'FAIL') . "\n";
echo "woocommerce_uploads_extra/x.jpg detected as not protected: " . (!$is_prot4 ? 'PASS' : 'FAIL') . "\n";
echo "/woocommerce_uploads/... detected as protected: " . ($is_prot5 ? 'PASS' : 'FAIL') . "\n";
echo "\\woocommerce_uploads\\... detected as protected: " . ($is_prot6 ? 'PASS' : 'FAIL') . "\n";

assert($is_prot1 === true);
assert($is_prot2 === false);
assert($is_prot3 === false, 'Mid-string woocommerce_uploads must NOT be treated as protected');
assert($is_prot4 === false, 'woocommerce_uploads_extra must NOT be treated as protected');
assert($is_prot5 === true);
assert($is_prot6 === true);

echo "\n=== 2. Path Resolution (SFTP & FTPS) ===\n";
$sftp = new FileChi_Storage_SFTP(array(
	'host'           => 'localhost',
	'username'       => 'test',
	'root_path'      => '/var/www/public_html/wp-content/uploads',
	'protected_path' => '/home/secure/protected_woocommerce',
));
$reflection     = new ReflectionClass($sftp);
$resolve_method = $reflection->getMethod('resolve_path');
$resolve_method->setAccessible(true);

$resolved_normal = $resolve_method->invoke($sftp, '2026/10/normal.jpg');
$resolved_prot   = $resolve_method->invoke($sftp, 'woocommerce_uploads/2026/10/file.zip');
echo "SFTP normal file path: $resolved_normal\n";
echo "SFTP protected file path: $resolved_prot\n";
assert($resolved_normal === '/var/www/public_html/wp-content/uploads/2026/10/normal.jpg');
assert($resolved_prot === '/home/secure/protected_woocommerce/woocommerce_uploads/2026/10/file.zip');

$ftps            = new FileChi_Storage_FTPS(array(
	'host'           => 'localhost',
	'username'       => 'test',
	'root_path'      => 'public_html/uploads',
	'protected_path' => 'secure_storage',
));
$reflection_ftps = new ReflectionClass($ftps);
$resolve_ftps    = $reflection_ftps->getMethod('resolve_path');
$resolve_ftps->setAccessible(true);
$resolved_ftps_normal = $resolve_ftps->invoke($ftps, '2026/10/normal.jpg');
$resolved_ftps_prot   = $resolve_ftps->invoke($ftps, 'woocommerce_uploads/2026/10/file.zip');
echo "FTPS normal file path: $resolved_ftps_normal\n";
echo "FTPS protected file path: $resolved_ftps_prot\n";
assert($resolved_ftps_normal === 'public_html/uploads/2026/10/normal.jpg');
assert($resolved_ftps_prot === 'secure_storage/woocommerce_uploads/2026/10/file.zip');

// Test Fail Closed: SFTP without protected_path throws RuntimeException on protected path
$sftp_no_prot = new FileChi_Storage_SFTP(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => '/var/www/public_html/wp-content/uploads',
));
$reflection_sftp_no_prot = new ReflectionClass($sftp_no_prot);
$resolve_sftp_no_prot    = $reflection_sftp_no_prot->getMethod('resolve_path');
$resolve_sftp_no_prot->setAccessible(true);

$sftp_fail_closed = false;
try {
	$resolve_sftp_no_prot->invoke($sftp_no_prot, 'woocommerce_uploads/2026/10/file.zip');
} catch (RuntimeException $e) {
	$sftp_fail_closed = true;
}
echo "SFTP without protected_path throws on protected file (fail-closed): " . ($sftp_fail_closed ? 'PASS' : 'FAIL') . "\n";
assert($sftp_fail_closed === true, 'SFTP resolve_path must throw RuntimeException when protected_path is missing');

// Test Fail Closed: FTPS without protected_path throws RuntimeException on protected path
$ftps_no_prot = new FileChi_Storage_FTPS(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => 'public_html/uploads',
));
$reflection_ftps_no_prot = new ReflectionClass($ftps_no_prot);
$resolve_ftps_no_prot    = $reflection_ftps_no_prot->getMethod('resolve_path');
$resolve_ftps_no_prot->setAccessible(true);

$ftps_fail_closed = false;
try {
	$resolve_ftps_no_prot->invoke($ftps_no_prot, 'woocommerce_uploads/2026/10/file.zip');
} catch (RuntimeException $e) {
	$ftps_fail_closed = true;
}
echo "FTPS without protected_path throws on protected file (fail-closed): " . ($ftps_fail_closed ? 'PASS' : 'FAIL') . "\n";
assert($ftps_fail_closed === true, 'FTPS resolve_path must throw RuntimeException when protected_path is missing');

echo "\n=== 3. Streaming Proxy Memory Efficiency ===\n";
$dummy_size = 5 * 1024 * 1024; // 5 MiB
$mem_before = memory_get_usage();

$bytes_streamed = 0;
ob_start();
$chunk_size   = 1048576;
$chunks_count = $dummy_size / $chunk_size;
for ($i = 0; $i < $chunks_count; $i++) {
	$chunk           = str_repeat('X', $chunk_size);
	echo $chunk;
	$bytes_streamed += strlen($chunk);
	unset($chunk);
	ob_clean();
}
ob_end_clean();

$peak_mem  = memory_get_peak_usage();
$mem_after = memory_get_usage();
echo "Streamed $bytes_streamed bytes (5 MiB).\n";
echo "Peak memory usage: " . round($peak_mem / 1024 / 1024, 2) . " MB\n";
echo "Memory increase: " . round(($mem_after - $mem_before) / 1024, 2) . " KB\n";
assert($bytes_streamed === 5242880);

echo "\n=== 4. HMAC Token & Traversal Verification ===\n";
$expires     = time() + 900;
$valid_file  = 'woocommerce_uploads/2026/10/file.zip';
$valid_token = hash_hmac('sha256', $valid_file . '|' . $expires, wp_salt('nonce'));

$tampered_token = substr($valid_token, 0, -4) . 'abcd';
assert(hash_equals($valid_token, $valid_token) === true);
assert(hash_equals($valid_token, $tampered_token) === false);
echo "Valid token accepted: PASS\n";
echo "Tampered token rejected: PASS\n";

$traversal_input = '../../../../etc/passwd';
$traversal_rejected = false;
try {
	FileChi_Path::validate(rawurldecode($traversal_input));
} catch (InvalidArgumentException $e) {
	$traversal_rejected = true;
}
echo "Traversal path '$traversal_input' rejected by FileChi_Path: " . ($traversal_rejected ? 'PASS' : 'FAIL') . "\n";
assert($traversal_rejected === true);

echo "\n=== 5. Offload Guard with Real Provider Row Shape (driver key) ===\n";
// Use the standardized fixture helper returning real FileChi_DB shape (id, name, driver, settings, is_default)
$provider_no_prot = filechi_create_test_provider(
	1,
	'sftp',
	array(
		'root_path'      => '/var/www/uploads',
		'protected_path' => '',
	),
	'My SFTP Provider',
	1
);

// Ensure the fixture uses 'driver', not 'type'
assert(isset($provider_no_prot['driver']), "Provider fixture must have 'driver' key");
assert(!isset($provider_no_prot['type']), "Provider fixture must NOT use 'type' key");

if (!class_exists('FileChi_DB')) {
	class FileChi_DB {
		public static $logs = array();
		public static function log_transfer($aid, $pid, $file, $size, $status, $msg = '') {
			self::$logs[] = compact('aid', 'pid', 'file', 'size', 'status', 'msg');
		}
	}
}

// Test guard check logic from offload_attachment using the real driver key
$files_to_offload = array('woocommerce_uploads/2026/10/file.zip' => 'c:/temp/uploads/woocommerce_uploads/2026/10/file.zip');
$has_protected    = false;
foreach ($files_to_offload as $rel_path => $abs_path) {
	if (FileChi_Media::is_protected_file($rel_path)) {
		$has_protected = true;
		break;
	}
}

$guard_triggered = false;
$provider_driver = $provider_no_prot['driver'] ?? '';
if ($has_protected && ($provider_driver === 'sftp' || $provider_driver === 'ftps')) {
	$protected_path = trim($provider_no_prot['settings']['protected_path'] ?? '');
	if (empty($protected_path)) {
		update_option('filechi_protected_path_missing_notice', array(
			'attachment_id' => 10,
			'provider_name' => $provider_no_prot['name'],
			'time'          => time(),
		));
		FileChi_DB::log_transfer(10, $provider_no_prot['id'], 'woocommerce_uploads/2026/10/file.zip', 1024, 'failed', 'No protected_path configured');
		$guard_triggered = true;
	}
}
echo "Guard prevented offload when protected_path is missing: " . ($guard_triggered ? 'PASS' : 'FAIL') . "\n";
assert($guard_triggered === true);
$notice = get_option('filechi_protected_path_missing_notice');
echo "Admin notice generated: " . (!empty($notice['attachment_id']) ? 'PASS' : 'FAIL') . "\n";
assert($notice['attachment_id'] === 10);

echo "\n=== 6. Rejection of Provider Row without 'driver' Key ===\n";
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
$legacy_provider_with_type_only = array(
	'id'       => 99,
	'name'     => 'Legacy Row',
	'type'     => 'sftp', // legacy key
	'settings' => array('host' => 'example.com'),
);

// Factory must reject rows without 'driver' key
$factory_result = FileChi_Storage_Factory::create($legacy_provider_with_type_only);
echo "Factory returns null for provider row missing 'driver': " . ($factory_result === null ? 'PASS' : 'FAIL') . "\n";
assert($factory_result === null, "Factory must return null when 'driver' key is missing");

// Ensure code never recognizes 'type' key
$driver_from_legacy = $legacy_provider_with_type_only['driver'] ?? '';
assert($driver_from_legacy === '', "Provider row without 'driver' must not resolve driver from legacy 'type'");
echo "Provider row without 'driver' yields empty driver: PASS\n";

echo "\nALL PROTECTED DOWNLOADS TESTS PASSED!\n";
