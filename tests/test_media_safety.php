<?php
// MOCK
/**
 * Test: Media Offload Safety: Two-phase offload, failure handling, and retry scheduling
 */

require_once __DIR__ . '/bootstrap.php';

$temp_upload_dir = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_uploads_' . uniqid());
@mkdir($temp_upload_dir, 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $temp_upload_dir . '/2026/10',
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $temp_upload_dir,
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

if (!class_exists('FileChi_DB')) {
	class FileChi_DB {
		public static function get_default_provider() {
			return filechi_create_test_provider(1, 'mock', array(), 'Mock Provider', 1);
		}
		public static function get_provider($id, $decrypt = false) {
			return filechi_create_test_provider($id, 'mock', array(), 'Mock Provider', 1);
		}
		public static function log_transfer($attachment_id, $provider_id, $file_path, $file_size, $status, $error_message = null) {
			// Mock log
		}
		public static function get_attachment_logs_by_file($attachment_id, $provider_id) {
			return array();
		}
	}
}

class Mock_Storage_Driver {
	public $fail_on_index = null;
	public $call_count    = 0;

	public function upload($local_file, $remote_path) {
		$this->call_count++;
		if ($this->fail_on_index !== null && $this->call_count === $this->fail_on_index) {
			return false;
		}
		return true;
	}

	public function exists($remote_path) {
		return false;
	}

	public function get_last_error() {
		return null;
	}

	public function get_size($remote_path) {
		return -1;
	}

	public function get_url($remote_path) {
		$clean = FileChi_Path::validate($remote_path);
		return 'https://remote.cdn.com/' . $clean;
	}

	public function get_signed_url($remote_path, $expires = 900) {
		$clean = FileChi_Path::validate($remote_path);
		return 'https://remote.cdn.com/signed/' . $clean;
	}
}

class FileChi_Storage_Factory {
	public static $mock_driver = null;
	public static function create($provider) {
		return self::$mock_driver;
	}
}

require_once FILECHI_DIR . 'includes/class-filechi-media.php';

echo "=== 1. Simulate a failing driver for the 3rd of 5 files ===\n";

$attachment_id = 101;
$main_rel      = '2026/10/hero.jpg';
$size1_rel     = '2026/10/hero-300x200.jpg';
$size2_rel     = '2026/10/hero-768x512.jpg';
$size3_rel     = '2026/10/hero-1024x683.jpg';
$size4_rel     = '2026/10/hero-150x150.jpg';

$all_rels = array($main_rel, $size1_rel, $size2_rel, $size3_rel, $size4_rel);

// Create directory and dummy local files
@mkdir($temp_upload_dir . '/2026/10', 0777, true);
foreach ($all_rels as $rel) {
	file_put_contents($temp_upload_dir . '/' . $rel, 'dummy content for ' . $rel);
}

// Set up attachment metadata
update_post_meta($attachment_id, '_wp_attached_file', $main_rel);
$metadata = array(
	'file'  => $main_rel,
	'sizes' => array(
		'thumbnail'    => array('file' => 'hero-150x150.jpg'),
		'medium'       => array('file' => 'hero-300x200.jpg'),
		'medium_large' => array('file' => 'hero-768x512.jpg'),
		'large'        => array('file' => 'hero-1024x683.jpg'),
	),
);

// Configure mock driver to fail on 3rd upload
$mock_driver                 = new Mock_Storage_Driver();
$mock_driver->fail_on_index  = 3;
FileChi_Storage_Factory::$mock_driver = $mock_driver;

$GLOBALS['mock_options']['filechi_settings']['keep_local_files'] = 0; // User wants local files deleted on success

$result = FileChi_Media::offload_attachment($attachment_id, null, $metadata);

echo "[+] offload_attachment return value: " . ($result ? 'true' : 'false') . "\n";
assert($result === false, 'offload_attachment should have returned false on failure!');

// Check local files: all 5 must still be present!
$all_files_present = true;
foreach ($all_rels as $rel) {
	$path = $temp_upload_dir . '/' . $rel;
	if (!file_exists($path)) {
		$all_files_present = false;
		echo "[-] Error: Local file was prematurely deleted: $rel\n";
	}
}
assert($all_files_present, 'Local files were deleted despite failure!');
echo "[+] Verified: Local files are all still present on disk (none deleted)\n";

// Check post meta: _filechi_offloaded must NOT be set
$is_offloaded = get_post_meta($attachment_id, '_filechi_offloaded', true);
assert(empty($is_offloaded), '_filechi_offloaded flag should NOT be set on failure!');
echo "[+] Verified: _filechi_offloaded flag is NOT set\n";

// Check Action Scheduler: retry must be scheduled
assert(!empty($GLOBALS['mock_scheduled_actions']), 'No retry action was scheduled!');
$scheduled = $GLOBALS['mock_scheduled_actions'][0];
assert($scheduled['hook'] === 'filechi_retry_attachment_offload', 'Scheduled hook mismatch');
assert($scheduled['args']['attachment_id'] === $attachment_id, 'Scheduled args mismatch');
echo "[+] Verified: Action Scheduler retry scheduled for attachment ID $attachment_id\n";

echo "\n=== 2. Simulate success with keep_local_files = 0 ===\n";

// Recreate all 5 files
foreach ($all_rels as $rel) {
	file_put_contents($temp_upload_dir . '/' . $rel, 'dummy content for ' . $rel);
}

$mock_driver                 = new Mock_Storage_Driver();
$mock_driver->fail_on_index  = null; // No failures
FileChi_Storage_Factory::$mock_driver = $mock_driver;

$result2 = FileChi_Media::offload_attachment($attachment_id, null, $metadata);

echo "[+] offload_attachment return value: " . ($result2 ? 'true' : 'false') . "\n";
assert($result2 === true, 'offload_attachment should have returned true on success!');

// Check post meta: _filechi_offloaded must be set to 1
$is_offloaded2 = get_post_meta($attachment_id, '_filechi_offloaded', true);
assert($is_offloaded2 == 1, '_filechi_offloaded should be 1 on success!');
echo "[+] Verified: _filechi_offloaded flag is set to 1\n";

// Check local files: must all be removed since keep_local_files = 0
$all_removed = true;
foreach ($all_rels as $rel) {
	$path = $temp_upload_dir . '/' . $rel;
	if (file_exists($path)) {
		$all_removed = false;
		echo "[-] File still exists: $rel\n";
	}
}
assert($all_removed, 'Local files were NOT removed when keep_local_files = 0!');
echo "[+] Verified: All local files removed when keep_local_files is off\n";

echo "\n=== 3. Simulate success with keep_local_files = 1 ===\n";

// Recreate all 5 files
foreach ($all_rels as $rel) {
	file_put_contents($temp_upload_dir . '/' . $rel, 'dummy content for ' . $rel);
}
delete_post_meta($attachment_id, '_filechi_offloaded');
$GLOBALS['mock_options']['filechi_settings']['keep_local_files'] = 1; // User wants local files kept

$mock_driver                 = new Mock_Storage_Driver();
$mock_driver->fail_on_index  = null; // No failures
FileChi_Storage_Factory::$mock_driver = $mock_driver;

$result3 = FileChi_Media::offload_attachment($attachment_id, null, $metadata);
assert($result3 === true, 'offload_attachment should succeed');

// Check post meta: flag set
assert(get_post_meta($attachment_id, '_filechi_offloaded', true) == 1, 'Flag should be set');
echo "[+] Verified: _filechi_offloaded flag is set to 1\n";

// Check local files: all 5 must still be present!
$all_retained = true;
foreach ($all_rels as $rel) {
	if (!file_exists($temp_upload_dir . '/' . $rel)) {
		$all_retained = false;
	}
}
assert($all_retained, 'Local files were unexpectedly removed when keep_local_files = 1!');
echo "[+] Verified: All local files retained on disk when keep_local_files is on\n";

// Cleanup temp dir
foreach ($all_rels as $rel) {
	@unlink($temp_upload_dir . '/' . $rel);
}
@rmdir($temp_upload_dir . '/2026/10');
@rmdir($temp_upload_dir);

echo "\n=== 4. Front-End Safety on Invalid Stored Paths (Item 5.6) ===\n";

$media    = new FileChi_Media();
$bad_path = "2026/10\\bad//image.jpg "; // backslash, double slash, trailing space

$bad_aid = 401;
update_post_meta($bad_aid, '_wp_attached_file', $bad_path);
update_post_meta($bad_aid, '_filechi_offloaded', 1);
update_post_meta($bad_aid, '_filechi_provider_id', 1);

// Test 4.1: filter_attachment_url with bad path
$orig_url = 'https://example.com/wp-content/uploads/2026/10/bad/image.jpg';
$res_url  = $media->filter_attachment_url($orig_url, $bad_aid);
echo "[+] filter_attachment_url returned original URL on invalid key: " . ($res_url === $orig_url ? 'PASS' : 'FAIL') . "\n";
assert($res_url === $orig_url, 'filter_attachment_url must return original URL on invalid key');

// Test 4.2: filter_image_srcset with bad path
$orig_sources = array(
	300 => array(
		'url'        => 'https://example.com/wp-content/uploads/2026/10/bad/image-300x200.jpg',
		'descriptor' => 'w',
		'value'      => 300,
	),
);
$res_srcset = $media->filter_image_srcset($orig_sources, array(300, 200), $orig_url, array(), $bad_aid);
echo "[+] filter_image_srcset preserved original URLs on invalid key: " . ($res_srcset[300]['url'] === $orig_sources[300]['url'] ? 'PASS' : 'FAIL') . "\n";
assert($res_srcset[300]['url'] === $orig_sources[300]['url'], 'filter_image_srcset must preserve original URL on invalid key');

// Test 4.3: filter_attachment_image_src with bad path
$orig_image = array('https://example.com/wp-content/uploads/2026/10/bad/image-150x150.jpg', 150, 150, true);
$res_image  = $media->filter_attachment_image_src($orig_image, $bad_aid, 'thumbnail', false);
echo "[+] filter_attachment_image_src preserved original image URL on invalid key: " . ($res_image[0] === $orig_image[0] ? 'PASS' : 'FAIL') . "\n";
assert($res_image[0] === $orig_image[0], 'filter_attachment_image_src must preserve original image on invalid key');

// Test 4.4: Every driver get_url() and get_signed_url() with invalid key
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-s3.php';

$sftp_driver = new FileChi_Storage_SFTP(array('host' => 'localhost', 'username' => 'u', 'root_path' => '/uploads'));
$sftp_url    = $sftp_driver->get_url($bad_path);
$sftp_signed = $sftp_driver->get_signed_url($bad_path);
assert(!empty($sftp_url), 'SFTP get_url must not fatal on invalid key');
assert(!empty($sftp_signed), 'SFTP get_signed_url must not fatal on invalid key');
echo "[+] SFTP driver get_url & get_signed_url safely returned fallback: PASS\n";

$ftps_driver = new FileChi_Storage_FTPS(array('host' => 'localhost', 'username' => 'u', 'root_path' => '/uploads'));
$ftps_url    = $ftps_driver->get_url($bad_path);
$ftps_signed = $ftps_driver->get_signed_url($bad_path);
assert(!empty($ftps_url), 'FTPS get_url must not fatal on invalid key');
assert(!empty($ftps_signed), 'FTPS get_signed_url must not fatal on invalid key');
echo "[+] FTPS driver get_url & get_signed_url safely returned fallback: PASS\n";

$s3_driver = new FileChi_Storage_S3(array('endpoint' => 'https://s3.example.com', 'bucket' => 'test-bucket', 'access_key' => 'AK', 'secret_key' => 'SK'));
$s3_url    = $s3_driver->get_url($bad_path);
$s3_signed = $s3_driver->get_signed_url($bad_path);
assert(!empty($s3_url), 'S3 get_url must not fatal on invalid key');
assert(!empty($s3_signed), 'S3 get_signed_url must not fatal on invalid key');
echo "[+] S3 driver get_url & get_signed_url safely returned fallback: PASS\n";

echo "\nALL MEDIA OFFLOAD SAFETY TESTS PASSED!\n";
