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

echo "\nALL MEDIA OFFLOAD SAFETY TESTS PASSED!\n";
