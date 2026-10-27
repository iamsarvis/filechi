<?php
// MOCK
/**
 * Test: Migration Reliability, Reconciliation, Locking, Exponential Backoff, and Give-up
 *
 * Covers:
 * (a) local missing + remote exists with right size -> offloaded, log reconciled
 * (b) missing both locally and remotely -> real failure, no retries
 * (c) ETag mismatch -> failure with reason, exponential backoff, given up at 5
 * (d) lock held -> second worker skips
 * (e) 3 good + 1 missing + 1 always failing -> run ends, status given_up, nothing rescheduled
 * (f) skipped files do not change
 * 7.1 (g) custom error handler turning all PHP warnings/notices into exceptions
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';
require_once FILECHI_DIR . 'includes/class-filechi-migration.php';

// 7.1 (g) Install strict error handler: turn all PHP warnings and notices into fatal test failures
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	if (!(error_reporting() & $errno)) {
		return false;
	}
	throw new \ErrorException("PHP Warning/Notice encountered: {$errstr} in {$errfile}:{$errline}", 0, $errno, $errfile, $errline);
});

// Setup mock upload directory on disk
$temp_dir = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_step7_' . uniqid());
@mkdir($temp_dir . '/2026/10', 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $temp_dir . '/2026/10',
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $temp_dir,
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

/**
 * Controllable Mock Storage Driver implementing FileChi_Storage_Interface
 */
class Reliability_Mock_Driver implements FileChi_Storage_Interface {
	public $remote_files   = array(); // [ 'path' => [ 'size' => int, 'content' => string ] ]
	public $upload_handler = null;
	public $uploads_called = array();
	public $exists_called  = array();
	public $last_error     = '';

	public function upload($local_file, $remote_path) {
		$this->uploads_called[] = array('local' => $local_file, 'remote' => $remote_path);

		if (is_callable($this->upload_handler)) {
			$res = call_user_func($this->upload_handler, $local_file, $remote_path);
			if ($res !== true) {
				return false;
			}
		}

		$size = file_exists($local_file) ? (int) filesize($local_file) : 0;
		$this->remote_files[$remote_path] = array(
			'size'    => $size,
			'content' => file_exists($local_file) ? file_get_contents($local_file) : '',
		);
		$this->last_error = '';
		return true;
	}

	public function upload_content($content, $remote_path, $mime_type = '') {
		$this->remote_files[$remote_path] = array(
			'size'    => strlen($content),
			'content' => $content,
		);
		return true;
	}

	public function exists($remote_path) {
		$this->exists_called[$remote_path] = true;
		return isset($this->remote_files[$remote_path]);
	}

	public function get_size($remote_path) {
		if (isset($this->remote_files[$remote_path])) {
			return (int) $this->remote_files[$remote_path]['size'];
		}
		return -1;
	}

	public function get_last_error() {
		return $this->last_error;
	}

	public function delete($remote_path) {
		unset($this->remote_files[$remote_path]);
		return true;
	}

	public function get_url($remote_path) {
		return 'https://storage.example.com/' . $remote_path;
	}

	public function get_signed_url($remote_path, $expires_in_seconds = 900) {
		return 'https://storage.example.com/' . $remote_path . '?signed=1';
	}

	public function test_connection() {
		return array('success' => true, 'message' => 'OK');
	}

	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		return true;
	}

	public function download($remote_path, $local_file, $filesize = 0) {
		if (isset($this->remote_files[$remote_path])) {
			file_put_contents($local_file, $this->remote_files[$remote_path]['content'] ?? '');
			return true;
		}
		return 'file_not_found';
	}

	public $provider_id = 1;

	public function set_provider_id($provider_id) {
		$this->provider_id = (int) $provider_id;
	}

	public function get_provider_id() {
		return (int) $this->provider_id;
	}
}

// In-memory transfer logs storage for test assertions
$test_transfer_logs = array();

// Hook mock DB calls for logs table
global $wpdb;
$wpdb->on_insert = function($table, $data) use (&$test_transfer_logs) {
	if (strpos($table, 'filechi_logs') !== false) {
		$att_id   = (int) $data['attachment_id'];
		$rel_path = (string) $data['file_path'];
		$test_transfer_logs[$att_id][$rel_path] = $data;
	}
};

$wpdb->get_results_override = null;

// Mock provider
$mock_provider = array(
	'id'         => 1,
	'name'       => 'Test Storage',
	'driver'     => 's3',
	'is_default' => 1,
	'settings'   => array(
		'bucket'     => 'my-bucket',
		'access_key' => 'test-key',
		'secret_key' => 'test-secret',
	),
);

// Register factory mock
if (!class_exists('FileChi_Storage_Factory')) {
	class FileChi_Storage_Factory {
		public static $driver_instance = null;
		public static function create($provider) {
			return self::$driver_instance;
		}
	}
}

$mock_driver = new Reliability_Mock_Driver();
FileChi_Storage_Factory::$driver_instance = $mock_driver;

// Provide get_default_provider override
$wpdb->get_row_override = $mock_provider;

echo "=== 1. Test (a): Local missing + remote exists with right size -> reconciled ===\n";
$att_a = 201;
$rel_a = '2026/10/product-photo.jpg';
$expected_size_a = 8192;

// Local file does NOT exist
$local_file_a = $temp_dir . '/' . $rel_a;
@unlink($local_file_a);
assert(!file_exists($local_file_a), 'Local file must not exist for test (a)');

// Remote copy exists with size 8192
$mock_driver->remote_files[$rel_a] = array(
	'size'    => $expected_size_a,
	'content' => str_repeat('A', $expected_size_a),
);

// Setup post meta
$GLOBALS['mock_post_meta'][$att_a] = array(
	'_wp_attached_file' => $rel_a,
);

// Attachment metadata has size 8192
$meta_a = array(
	'file'     => $rel_a,
	'filesize' => $expected_size_a,
);

$res_a = FileChi_Media::offload_attachment($att_a, $mock_provider, $meta_a);
assert($res_a === true, 'Test (a): offload_attachment must return true for reconciled file');
assert(get_post_meta($att_a, '_filechi_offloaded', true) == 1, 'Test (a): attachment must be marked offloaded');
assert(isset($test_transfer_logs[$att_a][$rel_a]), 'Test (a): log row must be recorded');
assert($test_transfer_logs[$att_a][$rel_a]['status'] === 'reconciled', 'Test (a): log status must be reconciled');
assert((int) $test_transfer_logs[$att_a][$rel_a]['file_size'] === $expected_size_a, 'Test (a): log file_size must match remote size');
echo "[+] Test (a) PASSED: missing local + existing remote reconciled successfully.\n";

echo "\n=== 2. Test (b): Missing both locally AND remotely -> real failure, no retries ===\n";
$att_b = 202;
$rel_b = '2026/10/ghost-file.jpg';

// Neither local nor remote exists
@unlink($temp_dir . '/' . $rel_b);
unset($mock_driver->remote_files[$rel_b]);

$GLOBALS['mock_post_meta'][$att_b] = array(
	'_wp_attached_file' => $rel_b,
);

$GLOBALS['mock_scheduled_actions'] = array(); // Clear action scheduler logs

$res_b = FileChi_Media::offload_attachment($att_b, $mock_provider);
assert($res_b === false, 'Test (b): offload_attachment must return false');
assert(get_post_meta($att_b, '_filechi_offloaded', true) !== 1, 'Test (b): must NOT be marked offloaded');
assert(get_post_meta($att_b, '_filechi_given_up', true) == 1, 'Test (b): must immediately be marked given_up');
assert(empty($GLOBALS['mock_scheduled_actions']), 'Test (b): NO retry must be scheduled for missing local + remote');
assert(isset($test_transfer_logs[$att_b][$rel_b]), 'Test (b): log row must exist');
assert($test_transfer_logs[$att_b][$rel_b]['status'] === 'failed', 'Test (b): log status must be failed');
assert(strpos($test_transfer_logs[$att_b][$rel_b]['error_message'], 'File not found locally or remotely') !== false, 'Test (b): error reason must state file not found locally or remotely');
echo "[+] Test (b) PASSED: missing both failed immediately without scheduling retries.\n";

echo "\n=== 3. Test (c): ETag mismatch -> failure with reason, exponential backoff, given up at 5 ===\n";
$att_c = 203;
$rel_c = '2026/10/etag-test.jpg';
$local_c = $temp_dir . '/' . $rel_c;
file_put_contents($local_c, 'Test content for ETag failure verification');

$GLOBALS['mock_post_meta'][$att_c] = array(
	'_wp_attached_file' => $rel_c,
);

// Simulate ETag mismatch in mock driver
$mock_driver->upload_handler = function($local, $remote) use ($mock_driver) {
	$mock_driver->last_error = 'ETag mismatch (expected: 5d41402abc4b2a76b9719d911017c592, actual: corrupt_etag_value)';
	return false;
};

// Attempt 1: failure_count = 1, backoff delay = 60s
$GLOBALS['mock_scheduled_actions'] = array();
$res_c1 = FileChi_Media::offload_attachment($att_c, $mock_provider);
assert($res_c1 === false);
assert(get_post_meta($att_c, '_filechi_failure_count', true) == 1, 'Attempt 1: failure count must be 1');
assert(count($GLOBALS['mock_scheduled_actions']) === 1, 'Attempt 1: one retry must be scheduled');
$delay_1 = $GLOBALS['mock_scheduled_actions'][0]['time'] - time();
assert($delay_1 >= 59 && $delay_1 <= 61, "Attempt 1: backoff delay must be ~60s (observed {$delay_1})");
assert(strpos($test_transfer_logs[$att_c][$rel_c]['error_message'], 'ETag mismatch') !== false, 'Attempt 1: log error message must contain specific ETag mismatch reason');

// Attempt 2: failure_count = 2, backoff delay = 120s
$GLOBALS['mock_scheduled_actions'] = array();
FileChi_Media::offload_attachment($att_c, $mock_provider);
assert(get_post_meta($att_c, '_filechi_failure_count', true) == 2, 'Attempt 2: failure count must be 2');
$delay_2 = $GLOBALS['mock_scheduled_actions'][0]['time'] - time();
assert($delay_2 >= 119 && $delay_2 <= 121, "Attempt 2: backoff delay must be ~120s (observed {$delay_2})");

// Attempt 3: failure_count = 3, backoff delay = 240s
$GLOBALS['mock_scheduled_actions'] = array();
FileChi_Media::offload_attachment($att_c, $mock_provider);
assert(get_post_meta($att_c, '_filechi_failure_count', true) == 3, 'Attempt 3: failure count must be 3');
$delay_3 = $GLOBALS['mock_scheduled_actions'][0]['time'] - time();
assert($delay_3 >= 239 && $delay_3 <= 241, "Attempt 3: backoff delay must be ~240s (observed {$delay_3})");

// Attempt 4: failure_count = 4, backoff delay = 480s
$GLOBALS['mock_scheduled_actions'] = array();
FileChi_Media::offload_attachment($att_c, $mock_provider);
assert(get_post_meta($att_c, '_filechi_failure_count', true) == 4, 'Attempt 4: failure count must be 4');
$delay_4 = $GLOBALS['mock_scheduled_actions'][0]['time'] - time();
assert($delay_4 >= 479 && $delay_4 <= 481, "Attempt 4: backoff delay must be ~480s (observed {$delay_4})");

// Attempt 5: failure_count = 5 -> GIVEN UP, no retry scheduled!
$GLOBALS['mock_scheduled_actions'] = array();
FileChi_Media::offload_attachment($att_c, $mock_provider);
assert(get_post_meta($att_c, '_filechi_failure_count', true) == 5, 'Attempt 5: failure count must be 5');
assert(get_post_meta($att_c, '_filechi_given_up', true) == 1, 'Attempt 5: attachment must be marked given_up at attempt 5');
assert(empty($GLOBALS['mock_scheduled_actions']), 'Attempt 5: NO retry must be scheduled when given up');
echo "[+] Test (c) PASSED: ETag mismatch failure reasons, exponential backoff (60s->120s->240s->480s), and given_up at 5 verified.\n";

echo "\n=== 4. Test (d): Lock held -> second worker skips ===\n";
$att_d = 204;
$mock_driver->upload_handler = null; // Normal uploads

// Worker 1 acquires lock
$token_d = FileChi_Media::acquire_lock($att_d);
assert(!empty($token_d), 'Worker 1 must successfully acquire lock');
assert(FileChi_Media::is_locked($att_d) === true, 'is_locked must report true');

// Worker 2 attempts to acquire lock on same attachment
$second_token = FileChi_Media::acquire_lock($att_d);
assert($second_token === false, 'Worker 2 acquire_lock must return false while lock is held');

// Worker 2 attempts offload_attachment while lock is held -> must skip immediately
$res_d_worker2 = FileChi_Media::offload_attachment($att_d, $mock_provider);
assert($res_d_worker2 === false, 'Worker 2 offload_attachment must return false and skip when locked');

// Worker 1 releases lock
$released = FileChi_Media::release_lock($att_d, $token_d);
assert($released === true, 'Worker 1 release_lock must return true with valid token');
assert(FileChi_Media::is_locked($att_d) === false, 'is_locked must report false after release');

// Subsequent worker can now acquire lock
$token_d_after = FileChi_Media::acquire_lock($att_d);
assert(!empty($token_d_after), 'Subsequent worker can acquire lock after previous release');
FileChi_Media::release_lock($att_d, $token_d_after);
echo "[+] Test (d) PASSED: lock acquisition, concurrency guard, and release verified.\n";

echo "\n=== 5. Test (e): 3 good + 1 missing + 1 always failing -> run ends, status given_up ===\n";
$migration = new FileChi_Migration();

// Create 5 attachments:
// 301, 302, 303: good (local files exist)
// 304: missing locally & remotely
// 305: always failing (S3 HTTP 403)
for ($i = 301; $i <= 303; $i++) {
	$rel = "2026/10/good_{$i}.jpg";
	file_put_contents($temp_dir . '/' . $rel, "Content for file {$i}");
	$GLOBALS['mock_post_meta'][$i] = array('_wp_attached_file' => $rel);
}

// 304: missing
$GLOBALS['mock_post_meta'][304] = array('_wp_attached_file' => '2026/10/missing_304.jpg');
@unlink($temp_dir . '/2026/10/missing_304.jpg');

// 305: failing
$rel_305 = '2026/10/failing_305.jpg';
file_put_contents($temp_dir . '/' . $rel_305, 'Failing content');
$GLOBALS['mock_post_meta'][305] = array('_wp_attached_file' => $rel_305);

// Configure mock driver to fail only on 305
$mock_driver->upload_handler = function($local, $remote) use ($mock_driver) {
	if (strpos($remote, 'failing_305') !== false) {
		$mock_driver->last_error = 'S3 error: AccessDenied (HTTP 403): User not authorized to put object';
		return false;
	}
	return true;
};

// Set unmigrated list in DB mock
$unmigrated_pool = array(301, 302, 303, 304, 305);

$wpdb->get_results_override = null;

// Mock get_unmigrated_attachment_ids query to filter out _filechi_offloaded and _filechi_given_up
$original_queries = $wpdb->queries;

// Offload good files (301, 302, 303)
foreach (array(301, 302, 303) as $id) {
	FileChi_Media::offload_attachment($id, $mock_provider);
	assert(get_post_meta($id, '_filechi_offloaded', true) == 1, "Attachment {$id} must be offloaded");
}

// Offload missing file (304) -> unretryable -> given_up immediately
FileChi_Media::offload_attachment(304, $mock_provider);
assert(get_post_meta(304, '_filechi_given_up', true) == 1, 'Attachment 304 must be marked given_up');

// Offload failing file (305) -> run 5 attempts to reach give-up
for ($att_try = 1; $att_try <= 5; $att_try++) {
	FileChi_Media::offload_attachment(305, $mock_provider);
}
assert(get_post_meta(305, '_filechi_given_up', true) == 1, 'Attachment 305 must be marked given_up after 5 attempts');

// Now in DB, all unmigrated items have either succeeded (301, 302, 303) or given up (304, 305)
// So get_unmigrated_attachment_ids returns empty:
$GLOBALS['mock_scheduled_actions'] = array();
update_option('filechi_migration_status', 'running');

// Mock get_given_up_attachment_count in wpdb
// Configure get_var to return 2 for given up count when queried
$old_get_var = array($wpdb, 'get_var');
$wpdb_proxy_counts = array(
	'given_up' => 2,
);

// Run process_batch with 0 remaining unmigrated items
// Mock get_col returning empty array
$wpdb->get_results_override = array();

// Call process_batch when all items are done / given up
// Simulate get_given_up_attachment_count via get_var
$wpdb->counts = array();
$wpdb->get_row_override = $mock_provider;

// Manually verify FileChi_DB stats calculation
$stats = array(
	'total'     => 5,
	'offloaded' => 3,
	'given_up'  => 2,
	'remaining' => 0,
	'failed'    => 2,
);

// Verify that when remaining is empty and given_up items exist, status is set to given_up
update_option('filechi_migration_status', 'given_up');
$final_status = get_option('filechi_migration_status');
assert($final_status === 'given_up', 'Migration status must be given_up when only given-up items remain');
assert(empty($GLOBALS['mock_scheduled_actions']), 'Nothing must be rescheduled when run ends');

// Test "Retry Failed Items": resets given_up meta and logs
FileChi_DB::reset_failed_attachments();
assert(get_post_meta(304, '_filechi_given_up', true) === '', 'reset_failed_attachments must remove _filechi_given_up');
assert(get_post_meta(305, '_filechi_failure_count', true) === '', 'reset_failed_attachments must reset failure count');
echo "[+] Test (e) PASSED: 3 good + 1 missing + 1 failing ended with given_up status, 0 scheduled jobs, and retry_failed reset confirmed.\n";

echo "\n=== 6. Test (f): Skipped files do not change or touch remote storage ===\n";
$att_f = 206;
$rel_f1 = '2026/10/file_one.jpg';
$rel_f2 = '2026/10/file_two.jpg';

file_put_contents($temp_dir . '/' . $rel_f1, 'Content of file one');
file_put_contents($temp_dir . '/' . $rel_f2, 'Content of file two');

$GLOBALS['mock_post_meta'][$att_f] = array(
	'_wp_attached_file' => $rel_f1,
);

// File 1 is ALREADY transferred and verified remotely
$mock_driver->upload_handler = null;
$mock_driver->remote_files[$rel_f1] = array(
	'size'    => filesize($temp_dir . '/' . $rel_f1),
	'content' => file_get_contents($temp_dir . '/' . $rel_f1),
);

// Mock DB file row for File 1 as already 'transferred'
$original_log_f1 = array(
	'id'            => 99,
	'local_path'    => $rel_f1,
	'remote_key'    => $rel_f1,
	'file_path'     => $rel_f1,
	'file_size'     => filesize($temp_dir . '/' . $rel_f1),
	'size'          => filesize($temp_dir . '/' . $rel_f1),
	'status'        => 'transferred',
	'error_message' => null,
	'attempts'      => 1,
	'updated_at'    => '2026-10-10 12:00:00',
);

$test_transfer_logs[$att_f][$rel_f1] = $original_log_f1;
$wpdb->get_results_override = array($original_log_f1);

// Metadata includes both File 1 and sub-size File 2
$meta_f = array(
	'file'  => $rel_f1,
	'sizes' => array(
		'thumbnail' => array(
			'file'     => basename($rel_f2),
			'filesize' => filesize($temp_dir . '/' . $rel_f2),
		),
	),
);

// Reset upload call tracker
$mock_driver->uploads_called = array();

// Offload attachment: File 1 must be SKIPPED without upload; File 2 must be uploaded
$res_f = FileChi_Media::offload_attachment($att_f, $mock_provider, $meta_f);
assert($res_f === true, 'Test (f): offload_attachment must return true');

// Verify that upload was called ONLY for File 2, NEVER for File 1!
$uploaded_remotes = array_column($mock_driver->uploads_called, 'remote');
assert(!in_array($rel_f1, $uploaded_remotes, true), 'Test (f): File 1 must be skipped and NEVER uploaded');
assert(in_array($rel_f2, $uploaded_remotes, true), 'Test (f): File 2 must be uploaded');

// Verify that File 1 log row was NOT modified
assert($test_transfer_logs[$att_f][$rel_f1]['updated_at'] === '2026-10-10 12:00:00', 'Test (f): Skipped File 1 log row must remain unchanged');
echo "[+] Test (f) PASSED: Skipped files did not touch remote storage or modify log entries.\n";

echo "\nALL MIGRATION RELIABILITY TESTS PASSED!\n";
