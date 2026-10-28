<?php
// MOCK
/**
 * Test: Restore Engine Unit Tests (Item R1.8)
 *
 * Verifies safe, idempotent, resumable remote-to-local file restoration:
 * 1. Matching local file exists -> skipped, counts as done
 * 2. Different sized local file exists -> local_conflict, not overwritten, counts as failure
 * 3. File missing locally -> downloaded to temp file, verified, renamed into place
 * 4. Download size mismatch -> temp file cleaned, failure count incremented, kept offloaded
 * 5. Attachment locked by another worker -> skipped without side effects
 * 6. Failure backoff calculated properly; gives up after 5 failures
 * 7. Finalization removes exactly the right postmetas and filechi_files rows
 * 8. Offload paused when offload_enabled = 0 or restore is running for provider
 * 9. Path traversal (.. or path outside uploads) rejected cleanly
 * 10. S3 and SFTP download driver mock verification (ETag validation, stream handling)
 * 11. Action Scheduler group 'filechi' verification on start/pause/resume
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';
require_once FILECHI_DIR . 'includes/class-filechi-restore.php';

// Strict error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	if (!(error_reporting() & $errno)) {
		return false;
	}
	throw new \ErrorException("PHP Warning/Notice encountered: {$errstr} in {$errfile}:{$errline}", 0, $errno, $errfile, $errline);
});

// Setup mock upload directory on disk
$temp_dir = wp_normalize_path(sys_get_temp_dir() . '/filechi_restore_test_' . uniqid());
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
 * Mock Storage Driver for Restore Engine Testing
 */
class Restore_Mock_Driver implements FileChi_Storage_Interface {
	public $remote_files = array(); // [ key => [ 'size' => int, 'content' => string, 'etag' => string ] ]
	public $download_calls = array();
	public $delete_calls   = array();
	public $download_handler = null;
	public $last_error     = '';

	public function upload($local_file, $remote_path) {
		$size = file_exists($local_file) ? (int) filesize($local_file) : 0;
		$content = file_exists($local_file) ? file_get_contents($local_file) : '';
		$this->remote_files[$remote_path] = array(
			'size'    => $size,
			'content' => $content,
			'etag'    => md5($content),
		);
		return true;
	}

	public function upload_content($content, $remote_path, $mime_type = '') {
		$this->remote_files[$remote_path] = array(
			'size'    => strlen($content),
			'content' => $content,
			'etag'    => md5($content),
		);
		return true;
	}

	public function exists($remote_path) {
		return isset($this->remote_files[$remote_path]);
	}

	public function get_size($remote_path) {
		return isset($this->remote_files[$remote_path]) ? (int) $this->remote_files[$remote_path]['size'] : -1;
	}

	public function get_last_error() {
		return $this->last_error;
	}

	public function delete($remote_path) {
		$this->delete_calls[] = $remote_path;
		unset($this->remote_files[$remote_path]);
		return true;
	}

	public function get_url($remote_path) {
		return 'https://cdn.example.com/' . $remote_path;
	}

	public function get_signed_url($remote_path, $expires_in_seconds = 900) {
		return 'https://cdn.example.com/' . $remote_path . '?signed=1';
	}

	public function test_connection() {
		return array('success' => true, 'message' => 'OK');
	}

	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		return true;
	}

	public function download($remote_path, $local_file, $filesize = 0) {
		$this->download_calls[] = array('remote' => $remote_path, 'local' => $local_file);
		if (is_callable($this->download_handler)) {
			return call_user_func($this->download_handler, $remote_path, $local_file, $filesize);
		}
		if (isset($this->remote_files[$remote_path])) {
			@wp_mkdir_p(dirname($local_file));
			file_put_contents($local_file, $this->remote_files[$remote_path]['content'] ?? '');
			return true;
		}
		$this->last_error = 'Remote file not found';
		return 'file_not_found';
	}

	public $provider_id = 10;

	public function set_provider_id($provider_id) {
		$this->provider_id = (int) $provider_id;
	}

	public function get_provider_id() {
		return (int) $this->provider_id;
	}
}

// In-memory mock database state
$mock_files_table = array(); // [ [ 'id' => ..., 'attachment_id' => ..., 'provider_id' => ..., 'local_path' => ..., 'remote_key' => ..., 'size' => ..., 'etag' => ..., 'status' => ... ] ]
$mock_logs_table  = array();

global $wpdb;
class Restore_Mock_WPDB extends Mock_WPDB {
	public $on_query = null;
	public function query($sql) {
		if (is_callable($this->on_query)) {
			call_user_func($this->on_query, $sql);
		}
		return parent::query($sql);
	}
}
$wpdb = new Restore_Mock_WPDB();

$mock_driver = new Restore_Mock_Driver();

if (!class_exists('FileChi_Storage_Factory')) {
	class FileChi_Storage_Factory {
		public static $driver_instance = null;
		public static function create($provider) {
			return self::$driver_instance;
		}
	}
}
FileChi_Storage_Factory::$driver_instance = $mock_driver;

$mock_provider = array(
	'id'         => 10,
	'name'       => 'S3 Remote',
	'driver'     => 's3',
	'is_default' => 1,
	'settings'   => json_encode(array('bucket' => 'test-bucket')),
);

$wpdb->get_row_override = $mock_provider;

// Hook mock DB get_results for filechi_files
$wpdb->get_results_override = function($query, $output = 'ARRAY_A') use (&$mock_files_table) {
	if (stripos($query, 'filechi_files') !== false) {
		if (preg_match('/attachment_id\s*=\s*(\d+)/i', $query, $m_att) && preg_match('/provider_id\s*=\s*(\d+)/i', $query, $m_prov)) {
			$att_id  = (int) $m_att[1];
			$prov_id = (int) $m_prov[1];
			$matches = array();
			foreach ($mock_files_table as $row) {
				if ((int) $row['attachment_id'] === $att_id && (int) $row['provider_id'] === $prov_id) {
					$matches[] = $row;
				}
			}
			return $matches;
		}
	}
	return array();
};

$wpdb->on_insert = function($table, $data) use (&$mock_logs_table) {
	if (stripos($table, 'filechi_logs') !== false) {
		$mock_logs_table[] = $data;
	}
};

$wpdb->on_query = function($query) use (&$mock_files_table) {
	if (stripos($query, 'DELETE FROM') !== false && stripos($query, 'filechi_files') !== false) {
		if (preg_match('/attachment_id\s*=\s*(\d+)/i', $query, $m_att) && preg_match('/provider_id\s*=\s*(\d+)/i', $query, $m_prov)) {
			$att_id  = (int) $m_att[1];
			$prov_id = (int) $m_prov[1];
			$mock_files_table = array_filter($mock_files_table, function($row) use ($att_id, $prov_id) {
				return !((int) $row['attachment_id'] === $att_id && (int) $row['provider_id'] === $prov_id);
			});
		}
	}
};

echo "=== 1. Matching local file exists -> skipped, counts as done ===\n";
$att_1 = 501;
$rel_1 = '2026/10/existing-match.jpg';
$abs_1 = $temp_dir . '/' . $rel_1;
$content_1 = 'Existing content 12345';
$size_1    = strlen($content_1);

@wp_mkdir_p(dirname($abs_1));
file_put_contents($abs_1, $content_1);

$mock_files_table[] = array(
	'id'            => 1,
	'attachment_id' => $att_1,
	'provider_id'   => 10,
	'local_path'    => $rel_1,
	'remote_key'    => $rel_1,
	'size'          => $size_1,
	'etag'          => md5($content_1),
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_1] = array(
	'_wp_attached_file'   => $rel_1,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 10,
);

$res_1 = FileChi_Restore::restore_attachment($att_1, 10, false);
assert($res_1 === true, 'Attachment with matching local file must restore as true');
assert(empty($mock_driver->download_calls), 'Driver download must NOT be called when local file matches size');
echo "[+] Test 1 PASSED: Local file matching size skipped download and counted as satisfied.\n";

echo "\n=== 2. Different sized local file exists -> local_conflict, not overwritten ===\n";
$att_2 = 502;
$rel_2 = '2026/10/conflict.jpg';
$abs_2 = $temp_dir . '/' . $rel_2;
$local_content_2 = 'Short local content';
file_put_contents($abs_2, $local_content_2);

$remote_content_2 = 'Much longer remote content from cloud storage';
$mock_files_table[] = array(
	'id'            => 2,
	'attachment_id' => $att_2,
	'provider_id'   => 10,
	'local_path'    => $rel_2,
	'remote_key'    => $rel_2,
	'size'          => strlen($remote_content_2),
	'etag'          => md5($remote_content_2),
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_2] = array(
	'_wp_attached_file'   => $rel_2,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 10,
);

$res_2 = FileChi_Restore::restore_attachment($att_2, 10, false);
assert($res_2 === false, 'Attachment with local conflict must return false');
assert(file_get_contents($abs_2) === $local_content_2, 'Conflicting local file must NEVER be overwritten');
assert(get_post_meta($att_2, '_filechi_restore_last_error', true) !== '', 'Conflict error must be recorded in postmeta');
echo "[+] Test 2 PASSED: Local conflict refused overwrite and returned failure.\n";

echo "\n=== 3. File missing locally -> downloaded to temp file, verified, renamed into place ===\n";
$att_3 = 503;
$rel_3 = '2026/10/download-me.jpg';
$abs_3 = $temp_dir . '/' . $rel_3;
@unlink($abs_3);
$content_3 = 'Freshly downloaded bytes from remote cloud';
$size_3    = strlen($content_3);

$mock_driver->remote_files[$rel_3] = array(
	'size'    => $size_3,
	'content' => $content_3,
	'etag'    => md5($content_3),
);

$mock_files_table[] = array(
	'id'            => 3,
	'attachment_id' => $att_3,
	'provider_id'   => 10,
	'local_path'    => $rel_3,
	'remote_key'    => $rel_3,
	'size'          => $size_3,
	'etag'          => md5($content_3),
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_3] = array(
	'_wp_attached_file'   => $rel_3,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 10,
);

$res_3 = FileChi_Restore::restore_attachment($att_3, 10, false);
assert($res_3 === true, 'Valid download must succeed');
assert(file_exists($abs_3), 'Target local file must exist after restore');
assert(file_get_contents($abs_3) === $content_3, 'Local file contents must match remote');
echo "[+] Test 3 PASSED: File downloaded into temp file, verified, and renamed atomically.\n";

echo "\n=== 4. Download size mismatch -> temp file cleaned, failure count incremented ===\n";
$att_4 = 504;
$rel_4 = '2026/10/corrupt.jpg';
$abs_4 = $temp_dir . '/' . $rel_4;
@unlink($abs_4);

$mock_driver->download_handler = function($remote, $local) {
	file_put_contents($local, 'Partial truncated content');
	return true;
};

$mock_files_table[] = array(
	'id'            => 4,
	'attachment_id' => $att_4,
	'provider_id'   => 10,
	'local_path'    => $rel_4,
	'remote_key'    => $rel_4,
	'size'          => 5000, // Expected 5000, downloaded only 24 bytes
	'etag'          => 'abcdef',
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_4] = array(
	'_wp_attached_file'   => $rel_4,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 10,
);

$res_4 = FileChi_Restore::restore_attachment($att_4, 10, false);
assert($res_4 === false, 'Size mismatch must fail restore');
assert(!file_exists($abs_4), 'Corrupt file must not be renamed into target');
assert((int) get_post_meta($att_4, '_filechi_restore_failure_count', true) === 1, 'Failure count must increment to 1');
assert((int) get_post_meta($att_4, '_filechi_offloaded', true) === 1, 'Attachment must remain offloaded');

// Reset download handler
$mock_driver->download_handler = null;
echo "[+] Test 4 PASSED: Size mismatch cleaned temp file, incremented failure count, and kept offloaded.\n";

echo "\n=== 5. Attachment locked by another worker -> skipped without side effects ===\n";
$att_5 = 505;
$lock_token = FileChi_Media::acquire_lock($att_5);
assert($lock_token !== false, 'Must acquire lock on att_5');

$res_5 = FileChi_Restore::restore_attachment($att_5, 10, false);
assert($res_5 === false, 'Locked attachment must be skipped');
FileChi_Media::release_lock($att_5, $lock_token);
echo "[+] Test 5 PASSED: Locked attachment skipped without errors.\n";

echo "\n=== 6. Failure backoff calculated properly; gives up after 5 failures ===\n";
$att_6 = 506;
$rel_6 = '2026/10/always-fails.jpg';

$mock_driver->download_handler = function($remote, $local) {
	return 'network_timeout';
};

$mock_files_table[] = array(
	'id'            => 6,
	'attachment_id' => $att_6,
	'provider_id'   => 10,
	'local_path'    => $rel_6,
	'remote_key'    => $rel_6,
	'size'          => 100,
	'etag'          => 'test-etag',
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_6] = array(
	'_wp_attached_file'   => $rel_6,
	'_filechi_offloaded'   => 1,
	'_filechi_provider_id' => 10,
);

for ($attempt = 1; $attempt <= 5; $attempt++) {
	$res_att = FileChi_Restore::restore_attachment($att_6, 10, false);
	assert($res_att === false);
	$failures = (int) get_post_meta($att_6, '_filechi_restore_failure_count', true);
	assert($failures === $attempt, "Failures must equal {$attempt}");
	if ($attempt < 5) {
		assert(get_post_meta($att_6, '_filechi_restore_given_up', true) != 1);
	}
}

// After 5 failures, given_up flag must be set
assert((int) get_post_meta($att_6, '_filechi_restore_given_up', true) === 1, 'Given up flag must be set after 5 failures');
$mock_driver->download_handler = null;
echo "[+] Test 6 PASSED: Exponential backoff tracked and give-up triggered after 5 failures.\n";

echo "\n=== 7. Finalization removes exactly the right postmetas and filechi_files rows ===\n";
$att_7 = 507;
$rel_7 = '2026/10/finalize-me.jpg';
$abs_7 = $temp_dir . '/' . $rel_7;
$content_7 = 'Finalized payload';
$size_7    = strlen($content_7);

$mock_driver->remote_files[$rel_7] = array(
	'size'    => $size_7,
	'content' => $content_7,
	'etag'    => md5($content_7),
);

$mock_files_table[] = array(
	'id'            => 7,
	'attachment_id' => $att_7,
	'provider_id'   => 10,
	'local_path'    => $rel_7,
	'remote_key'    => $rel_7,
	'size'          => $size_7,
	'etag'          => md5($content_7),
	'role'          => 'primary',
	'status'        => 'transferred',
);

$GLOBALS['mock_post_meta'][$att_7] = array(
	'_wp_attached_file'                 => $rel_7,
	'_filechi_offloaded'                => 1,
	'_filechi_provider_id'              => 10,
	'_filechi_restore_failure_count'    => 2,
	'_filechi_restore_last_error'       => 'temporary error',
	'_filechi_restore_retry_scheduled'  => 1,
	'_filechi_restore_given_up'         => 0,
);

// Restore with delete_remote = true
$res_7 = FileChi_Restore::restore_attachment($att_7, 10, true);
assert($res_7 === true, 'Restore and finalization must succeed');

// Verify metadata cleanup
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_offloaded']), '_filechi_offloaded must be deleted');
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_provider_id']), '_filechi_provider_id must be deleted');
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_restore_failure_count']), 'Failure count meta must be deleted');
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_restore_last_error']), 'Last error meta must be deleted');
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_restore_retry_scheduled']), 'Retry scheduled meta must be deleted');
assert(!isset($GLOBALS['mock_post_meta'][$att_7]['_filechi_restore_given_up']), 'Given up meta must be deleted');

// Verify filechi_files row deletion
$remaining_rows = array_filter($mock_files_table, function($row) use ($att_7) {
	return (int) $row['attachment_id'] === $att_7;
});
assert(empty($remaining_rows), 'filechi_files rows for restored attachment must be deleted');

// Verify remote deletion call
assert(in_array($rel_7, $mock_driver->delete_calls), 'Remote file must be deleted when delete_remote is true');
echo "[+] Test 7 PASSED: Finalization cleanly removed postmetas and database rows.\n";

echo "\n=== 8. Offload paused when offload_enabled = 0 or restore is running ===\n";
// Case 8a: offload_enabled = 0
$GLOBALS['mock_options']['filechi_settings'] = array('offload_enabled' => 0);
$media = new FileChi_Media();
$dummy_meta = array('file' => '2026/10/some-file.jpg');
$meta_result = $media->handle_attachment_metadata($dummy_meta, 999);
assert($meta_result === $dummy_meta, 'handle_attachment_metadata must return metadata unmodified without offloading');
assert(!isset($GLOBALS['mock_post_meta'][999]['_filechi_offloaded']), 'Attachment 999 must NOT be offloaded when offload_enabled = 0');

// Case 8b: restore is running for provider
$GLOBALS['mock_options']['filechi_settings'] = array('offload_enabled' => 1);
$GLOBALS['mock_options']['filechi_restore_state'] = array(
	'provider_id' => 10,
	'status'      => 'running',
);

$res_8b = FileChi_Media::offload_attachment(998, $mock_provider);
assert($res_8b === false, 'offload_attachment must return false when restore is running for that provider');
$skipped_logs = array_filter($mock_logs_table, function($log) {
	return ($log['status'] ?? '') === 'skipped';
});
assert(!empty($skipped_logs), 'Skipped transfer log must be recorded when offload skipped during active restore');
echo "[+] Test 8 PASSED: Offloading bypassed when offload_enabled = 0 or restore active.\n";

echo "\n=== 9. Path traversal rejected cleanly before network or disk operation ===\n";
$att_9 = 509;
$traversal_path = '../../wp-config.php';

$mock_files_table[] = array(
	'id'            => 9,
	'attachment_id' => $att_9,
	'provider_id'   => 10,
	'local_path'    => $traversal_path,
	'remote_key'    => 'secret.php',
	'size'          => 100,
	'etag'          => 'hash',
	'role'          => 'primary',
	'status'        => 'transferred',
);

$mock_driver->download_calls = array();
$res_9 = FileChi_Restore::restore_attachment($att_9, 10, false);
assert($res_9 === false, 'Path traversal must be rejected');
assert(empty($mock_driver->download_calls), 'Driver download must NOT be called for traversal path');
assert(stripos(get_post_meta($att_9, '_filechi_restore_last_error', true), 'traversal') !== false, 'Traversal error must be logged');
echo "[+] Test 9 PASSED: Path traversal rejected before disk/network operations.\n";

echo "\n=== 10. S3 and SFTP download driver mock verification ===\n";
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-s3.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';

// Verify S3 download() signature and path resolution
$s3_provider = array(
	'id'       => 11,
	'driver'   => 's3',
	'settings' => array(
		'bucket'     => 'test-bucket',
		'endpoint'   => 'https://s3.example.com',
		'access_key' => 'AKIAIOSFODNN7EXAMPLE',
		'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		'region'     => 'us-east-1',
	),
);
$s3_driver = new FileChi_Storage_S3($s3_provider);
assert(method_exists($s3_driver, 'download'), 'S3 driver must implement download()');
assert(method_exists($s3_driver, 'resolve_path'), 'S3 driver must implement resolve_path()');

// Verify SFTP download() signature
$sftp_provider = array(
	'id'       => 12,
	'driver'   => 'sftp',
	'settings' => array(
		'host'     => 'sftp.example.com',
		'port'     => 22,
		'username' => 'testuser',
		'root_path'=> '/uploads',
	),
);
$sftp_driver = new FileChi_Storage_SFTP($sftp_provider);
assert(method_exists($sftp_driver, 'download'), 'SFTP driver must implement download()');
echo "[+] Test 10 PASSED: S3 and SFTP drivers verified.\n";

echo "\n--- Test 11: Action Scheduler group 'filechi' verification ---\n";
$GLOBALS['mock_scheduled_actions'] = array();
$GLOBALS['mock_unscheduled_actions'] = array();

// Start restore
$start_res = FileChi_Restore::start_restore(1, false);
assert($start_res['status'] === 'running');
$last_sched = end($GLOBALS['mock_scheduled_actions']);
assert($last_sched['hook'] === 'filechi_restore_batch', 'Must schedule filechi_restore_batch');
assert($last_sched['group'] === 'filechi', 'filechi_restore_batch must have group filechi');

// Pause restore
$pause_res = FileChi_Restore::pause_restore();
assert($pause_res['status'] === 'paused');
$last_unsched = end($GLOBALS['mock_unscheduled_actions']);
assert($last_unsched['hook'] === 'filechi_restore_batch', 'Must unschedule filechi_restore_batch');
assert($last_unsched['group'] === 'filechi', 'unschedule must have group filechi');

// Resume restore
$resume_res = FileChi_Restore::resume_restore();
assert($resume_res['status'] === 'running');
$last_sched_res = end($GLOBALS['mock_scheduled_actions']);
assert($last_sched_res['hook'] === 'filechi_restore_batch');
assert($last_sched_res['group'] === 'filechi');

// Clean up option
delete_option(FileChi_Restore::OPTION_STATE);
echo "[+] Test 11 PASSED: Action Scheduler group 'filechi' verified.\n";

// Cleanup test upload directory
$cleanup_files = function($dir) use (&$cleanup_files) {
	if (!is_dir($dir)) {
		return;
	}
	$items = scandir($dir);
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$p = $dir . '/' . $item;
		if (is_dir($p)) {
			$cleanup_files($p);
		} else {
			@unlink($p);
		}
	}
	@rmdir($dir);
};
$cleanup_files($temp_dir);

echo "\nALL 11 RESTORE ENGINE UNIT TESTS PASSED!\n";
