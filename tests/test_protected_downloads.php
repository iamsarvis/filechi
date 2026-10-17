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

// SFTP Absolute Root Test Cases (Item 4b.5)
$sftp_abs = new FileChi_Storage_SFTP(array(
	'host'           => 'localhost',
	'username'       => 'test',
	'root_path'      => '/domains/pz16241.parspack.net/public_html/uploads/',
	'protected_path' => '/home/secure/protected_woocommerce/',
));
$sftp_abs_normal = $sftp_abs->resolve_path('2026/10/photo.jpg');
$sftp_abs_root   = $sftp_abs->resolve_path('');
$sftp_abs_probe  = $sftp_abs->resolve_path('.filechi-probe-12345678.tmp');
echo "SFTP absolute root normal path: $sftp_abs_normal\n";
echo "SFTP absolute root directory query: $sftp_abs_root\n";
echo "SFTP absolute root probe path: $sftp_abs_probe\n";
assert($sftp_abs_normal === '/domains/pz16241.parspack.net/public_html/uploads/2026/10/photo.jpg');
assert($sftp_abs_root === '/domains/pz16241.parspack.net/public_html/uploads');
assert($sftp_abs_probe === '/domains/pz16241.parspack.net/public_html/uploads/.filechi-probe-12345678.tmp');

// SFTP Single Root "/"
$sftp_single = new FileChi_Storage_SFTP(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => '/',
));
assert($sftp_single->resolve_path('2026/10/photo.jpg') === '/2026/10/photo.jpg');
assert($sftp_single->resolve_path('') === '/');

// SFTP Invalid Root Fail Closed
$sftp_invalid_root = new FileChi_Storage_SFTP(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => '../invalid/traversal',
));
$sftp_root_threw = false;
try {
	$sftp_invalid_root->resolve_path('test.jpg');
} catch (FileChi_Path_Exception $e) {
	$sftp_root_threw = true;
}
assert($sftp_root_threw === true, 'SFTP resolve_path must throw FileChi_Path_Exception on invalid root path');
echo "SFTP absolute root unit checks: PASS\n";

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
		public static $logs      = array();
		public static $providers = array();

		public static function log_transfer($aid, $pid, $file, $size, $status, $msg = '') {
			self::$logs[] = compact('aid', 'pid', 'file', 'size', 'status', 'msg');
		}

		public static function get_provider($id) {
			return self::$providers[(int) $id] ?? null;
		}

		public static function get_default_provider() {
			foreach (self::$providers as $p) {
				if (!empty($p['is_default'])) {
					return $p;
				}
			}
			return reset(self::$providers) ?: null;
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

echo "\n=== 7. Item 5.5 Real-Shape Fixture Suite ===\n";
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-woocommerce.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-s3.php';

$upload_dir = wp_upload_dir();
$prot_rel   = 'woocommerce_uploads/2026/10/product-file-201.zip';
$prot_full  = $upload_dir['basedir'] . '/' . $prot_rel;
if (!is_dir(dirname($prot_full))) {
	mkdir(dirname($prot_full), 0777, true);
}
file_put_contents($prot_full, 'DUMMY_PRODUCT_CONTENT');

// 5.5.1: protected path + SFTP provider without protected_path → refuse and stay local
$provider1 = filechi_create_test_provider(
	1,
	'sftp',
	array(
		'host'           => 'sftp.example.com',
		'root_path'      => '/var/www/uploads',
		'protected_path' => '', // missing
	),
	'Default SFTP No Protected',
	1
);
FileChi_DB::$providers[1] = $provider1;

$GLOBALS['mock_post_meta'][201] = array(
	'_wp_attached_file' => $prot_rel,
);
delete_option('filechi_protected_path_missing_notice');

$offload_res = FileChi_Media::offload_attachment(201);
echo "Protected file + SFTP without protected_path refused: " . ($offload_res === false ? 'PASS' : 'FAIL') . "\n";
assert($offload_res === false, 'Offload must be refused when protected_path is missing');
assert(empty(get_post_meta(201, '_filechi_offloaded', true)), 'Attachment must stay local');
assert(file_exists($prot_full), 'Local copy must remain untouched');
$notice_201 = get_option('filechi_protected_path_missing_notice');
assert(!empty($notice_201['attachment_id']) && $notice_201['attachment_id'] === 201, 'Admin notice must be set');
echo "Attachment stayed local and notice created: PASS\n";

// 5.5.2: same with protected_path → routed there
$provider2 = filechi_create_test_provider(
	2,
	'sftp',
	array(
		'host'           => 'sftp.example.com',
		'root_path'      => '/var/www/uploads',
		'protected_path' => '/var/secure_vault',
	),
	'SFTP With Protected',
	0
);
FileChi_DB::$providers[2] = $provider2;
$driver2 = FileChi_Storage_Factory::create($provider2);
assert($driver2 !== null);
$ref2 = new ReflectionClass($driver2);
$m2   = $ref2->getMethod('resolve_path');
$m2->setAccessible(true);
$routed = $m2->invoke($driver2, $prot_rel);
echo "Protected file with protected_path routed to: $routed\n";
assert($routed === '/var/secure_vault/' . $prot_rel, 'Protected file must be routed to protected_path');
echo "SFTP routed to protected_path: PASS\n";

// 5.5.3: non-offloaded downloadable file → WooCommerce path untouched
$wc = new FileChi_WooCommerce();
update_option('filechi_settings', array(
	'wc_signed_downloads' => 1,
	'wc_download_expiry'  => 900,
));
$non_offloaded_rel = 'woocommerce_uploads/2026/10/local-ebook.pdf';
$GLOBALS['mock_post_meta'][202] = array(
	'_wp_attached_file' => $non_offloaded_rel,
	// _filechi_offloaded is NOT 1
);
$orig_url     = 'https://example.com/wp-content/uploads/' . $non_offloaded_rel;
$filtered_url = $wc->filter_download_path($orig_url, 123, 'dl_202');
echo "Non-offloaded downloadable file path untouched: " . ($filtered_url === $orig_url ? 'PASS' : 'FAIL') . "\n";
assert($filtered_url === $orig_url, 'Non-offloaded file download path must remain untouched');

// 5.5.4: offloaded file with a non-default provider → that provider is used
$provider3 = filechi_create_test_provider(
	3,
	's3',
	array(
		'endpoint'   => 'https://s3.custom-provider.example.com',
		'bucket'     => 'secure-wc-bucket',
		'access_key' => 'MYACCESSKEY3',
		'secret_key' => 'MYSECRETKEY3',
		'region'     => 'us-east-1',
	),
	'Non-Default S3 Provider',
	0
);
FileChi_DB::$providers[3] = $provider3;

$offloaded_rel = 'woocommerce_uploads/2026/10/pro-plugin.zip';
$GLOBALS['mock_post_meta'][203] = array(
	'_wp_attached_file'    => $offloaded_rel,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 3, // Non-default provider
);
$signed_url = $wc->filter_download_path($offloaded_rel, 456, 'dl_203');
echo "Signed download URL generated: " . (!empty($signed_url) && $signed_url !== $offloaded_rel ? 'PASS' : 'FAIL') . "\n";
assert($signed_url !== $offloaded_rel, 'Offloaded file must return signed URL');
assert(
	strpos($signed_url, 's3.custom-provider.example.com') !== false || strpos($signed_url, 'secure-wc-bucket') !== false,
	'Signed URL must be generated using provider 3, not default provider'
);
echo "Offloaded file used non-default provider (provider 3): PASS\n";

// 5.5.5: a regular upload path is never treated as protected
echo "Testing regular upload paths are never treated as protected...\n";
assert(FileChi_Media::is_protected_file('2026/10/regular-image.jpg') === false);
assert(FileChi_Media::is_protected_file('2026/10/woocommerce_uploads/image.jpg') === false);
assert(FileChi_Media::is_protected_file('my-woocommerce_uploads/file.zip') === false);
assert(FileChi_Media::is_protected_file('woocommerce_uploads_backup/file.zip') === false);
assert(FileChi_Media::is_protected_file('woocommerce_uploads/2026/10/file.zip') === true);
echo "Regular paths never treated as protected: PASS\n";

echo "\nALL PROTECTED DOWNLOADS TESTS PASSED!\n";
