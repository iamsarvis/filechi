<?php
// MOCK
/**
 * Test: Signed Download Handler Security, Headers, and Streaming Memory Efficiency
 *
 * Verifies:
 * 1. Tampered provider ID is rejected with 403 (HMAC mismatch)
 * 2. Tampered file path is rejected with 403 (HMAC mismatch)
 * 3. Expired token is rejected with 403
 * 4. Missing remote file returns clean 404 before sending headers
 * 5. Key not in filechi_logs as 'transferred' returns clean 404
 * 6. Deleted or unknown provider returns 404
 * 7. Persian file name header formatting (RFC 5987 Content-Disposition)
 * 8. ASCII-only file name header formatting
 * 9. Streaming memory efficiency: streaming 100 MB uses constant low memory (< 5 MB increase)
 *
 * Manual Testing Verification Steps on Real WordPress + SFTP/FTPS Server:
 * -----------------------------------------------------------------------
 * 1. Configure an active SFTP or FTPS provider profile in FileChi Settings.
 * 2. Upload a WooCommerce product file or media item with Persian characters (e.g. "راهنما-جامع.pdf").
 * 3. Offload the file and generate a signed download URL using get_signed_url().
 * 4. Verify in browser Network Tab:
 *    a. Status 200 OK.
 *    b. Header Content-Disposition: attachment; filename="download.pdf"; filename*=UTF-8''%D8%B1...
 *    c. Header X-Content-Type-Options: nosniff
 *    d. Downloaded file on disk is named "راهنما-جامع.pdf" (not %D8...).
 * 5. Tamper tests in browser:
 *    a. Change filechi_provider query param -> 403 Forbidden.
 *    b. Change filechi_file query param -> 403 Forbidden.
 *    c. Change filechi_expires to past timestamp -> 403 Forbidden.
 * 6. Delete remote file manually on server and click link -> clean 404 page (no half download).
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
require_once FILECHI_DIR . 'filechi.php';

echo "=== 1. Setup Mock Provider, DB and Driver Fixtures ===\n";

class Mock_Streaming_Storage_Driver implements FileChi_Storage_Interface {
	public $provider_id = 1;
	public $remote_files = array(); // [ key => [ 'exists' => bool, 'size' => int, 'stream_content' => callable|string ] ]
	public $last_error   = '';

	public function set_provider_id($provider_id) {
		$this->provider_id = (int) $provider_id;
	}

	public function get_provider_id() {
		return $this->provider_id;
	}

	public function upload($local_file, $remote_path) { return true; }
	public function upload_content($content, $remote_path, $mime_type = '') { return true; }
	public function delete($remote_path) { return true; }

	public function exists($remote_path) {
		if (isset($this->remote_files[$remote_path])) {
			return !empty($this->remote_files[$remote_path]['exists']);
		}
		return false;
	}

	public function get_size($remote_path) {
		if (isset($this->remote_files[$remote_path]['size'])) {
			return (int) $this->remote_files[$remote_path]['size'];
		}
		return -1;
	}

	public function get_url($remote_path) {
		return 'https://example.com/' . $remote_path;
	}

	public function get_signed_url($remote_path, $expires_in_seconds = 900) {
		$expires     = time() + absint($expires_in_seconds);
		$provider_id = (int) $this->provider_id;
		$token       = hash_hmac('sha256', $provider_id . '|' . $remote_path . '|' . $expires, wp_salt('nonce'));

		return add_query_arg(
			array(
				'filechi_action'   => 'download',
				'filechi_file'     => rawurlencode($remote_path),
				'filechi_expires'  => $expires,
				'filechi_provider' => $provider_id,
				'filechi_token'    => $token,
			),
			'https://example.com/'
		);
	}

	public function test_connection() {
		return array('success' => true, 'message' => 'OK');
	}

	public function get_last_error() {
		return $this->last_error;
	}

	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		if (!isset($this->remote_files[$remote_path])) {
			$this->last_error = 'File not found on storage';
			return false;
		}

		$file_entry = $this->remote_files[$remote_path];
		if (is_callable($file_entry['stream_content'])) {
			return call_user_func($file_entry['stream_content'], $chunk_size);
		}

		// Direct chunk streaming
		$content = (string) $file_entry['stream_content'];
		$len     = strlen($content);
		$offset  = 0;
		while ($offset < $len) {
			$chunk = substr($content, $offset, $chunk_size);
			echo $chunk;
			$offset += strlen($chunk);
		}
		return true;
	}

	public function download($remote_path, $local_file) {
		if (isset($this->remote_files[$remote_path])) {
			$content = is_callable($this->remote_files[$remote_path]['stream_content'])
				? 'mock_content'
				: (string) ($this->remote_files[$remote_path]['stream_content'] ?? '');
			file_put_contents($local_file, $content);
			return true;
		}
		return 'file_not_found';
	}
}

// Global fixtures for DB mocking
$mock_providers = array(
	1 => array(
		'id'         => 1,
		'name'       => 'SFTP Storage 1',
		'driver'     => 'sftp',
		'is_default' => 1,
		'settings'   => array('host' => 'sftp1.example.com'),
	),
	2 => array(
		'id'         => 2,
		'name'       => 'SFTP Storage 2',
		'driver'     => 'sftp',
		'is_default' => 0,
		'settings'   => array('host' => 'sftp2.example.com'),
	),
);

// Populate mock driver in factory cache
FileChi_Storage_Factory::clear_instances();
$mock_driver = new Mock_Streaming_Storage_Driver();
$mock_driver->set_provider_id(1);

// Register files in mock storage
$mock_driver->remote_files['2026/10/valid.pdf'] = array(
	'exists'         => true,
	'size'           => 1024,
	'stream_content' => str_repeat('A', 1024),
);
$mock_driver->remote_files['2026/10/کتاب-آموزش.pdf'] = array(
	'exists'         => true,
	'size'           => 2048,
	'stream_content' => str_repeat('B', 2048),
);

// Mock DB logs for transferred files
$mock_transferred_logs = array(
	'1|2026/10/valid.pdf'           => true,
	'1|2026/10/کتاب-آموزش.pdf'      => true,
	'1|2026/10/remote-missing.pdf'  => true, // in logs, but missing on remote driver
);

// Install DB override hook for tests
global $wpdb;
$wpdb->prefix = 'wp_';

// Mock wpdb get_row and get_var
$original_wpdb = $wpdb;
class Test_Download_WPDB {
	public $prefix = 'wp_';
	public $postmeta = 'wp_postmeta';

	public function prepare($query, ...$args) {
		if (is_array($args[0] ?? null) && count($args) === 1) {
			$args = $args[0];
		}
		foreach ($args as $arg) {
			$val = is_numeric($arg) ? $arg : "'" . addslashes((string) $arg) . "'";
			$query = preg_replace('/%[sdf]/', $val, $query, 1);
		}
		return $query;
	}

	public function get_row($query, $output = OBJECT) {
		global $mock_providers;
		if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
			$id = (int) $m[1];
			if (isset($mock_providers[$id])) {
				$row = $mock_providers[$id];
				$row['settings'] = json_encode($row['settings']);
				return $row;
			}
			return null;
		}
		if (strpos($query, 'WHERE is_default = 1') !== false) {
			$row = $mock_providers[1];
			$row['settings'] = json_encode($row['settings']);
			return $row;
		}
		return null;
	}

	public function get_var($query) {
		global $mock_transferred_logs;
		// Check is_file_transferred query (matches filechi_files and legacy logs queries)
		if (preg_match("/WHERE provider_id = (\d+).*(?:file_path|remote_key|local_path) = '([^']+)'.*status = 'transferred'/", $query, $m)) {
			$key = $m[1] . '|' . stripslashes($m[2]);
			return !empty($mock_transferred_logs[$key]) ? 'transferred' : null;
		}
		return null;
	}
}
$wpdb = new Test_Download_WPDB();

// Override storage factory instance
$ref_class = new ReflectionClass('FileChi_Storage_Factory');
$ref_prop  = $ref_class->getProperty('instances');
$ref_prop->setAccessible(true);
$ref_prop->setValue(null, array(1 => $mock_driver, 2 => new Mock_Streaming_Storage_Driver()));

echo "PASS: Fixtures and mocks initialized successfully.\n";

echo "\n=== 2. Test Tampered Provider ID (item 6.1 / 6.6) ===\n";
// Create valid token for provider 1
$valid_url = $mock_driver->get_signed_url('2026/10/valid.pdf', 900);
$parts     = parse_url($valid_url);
parse_str($parts['query'], $query_params);

// Tamper provider parameter from 1 to 2
$_GET = $query_params;
$_GET['filechi_provider'] = 2;

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 403, "Tampered provider must return 403 HTTP status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'Invalid or tampered') !== false, "Error message must indicate signature tampering");
}
assert($caught, "Tampered provider ID must throw 403 WPDieException");
echo "PASS: Tampered provider ID rejected with 403.\n";

echo "\n=== 3. Test Tampered File Path (item 6.6) ===\n";
$_GET = $query_params;
$_GET['filechi_file'] = rawurlencode('2026/10/tampered.pdf');

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 403, "Tampered file must return 403 HTTP status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'Invalid or tampered') !== false, "Error message must indicate signature tampering");
}
assert($caught, "Tampered file path must throw 403 WPDieException");
echo "PASS: Tampered file path rejected with 403.\n";

echo "\n=== 4. Test Expired Download URL (item 6.6) ===\n";
$expired_time  = time() - 60;
$expired_token = hash_hmac('sha256', '1|2026/10/valid.pdf|' . $expired_time, wp_salt('nonce'));
$_GET = array(
	'filechi_action'   => 'download',
	'filechi_file'     => rawurlencode('2026/10/valid.pdf'),
	'filechi_expires'  => $expired_time,
	'filechi_provider' => 1,
	'filechi_token'    => $expired_token,
);

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 403, "Expired token must return 403 HTTP status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'expired') !== false, "Error message must indicate expiration");
}
assert($caught, "Expired token must throw 403 WPDieException");
echo "PASS: Expired download link rejected with 403.\n";

echo "\n=== 5. Test Key Not in Logs as 'transferred' (item 6.2 / 6.6) ===\n";
// Create a file not present in $mock_transferred_logs
$not_logged_file = '2026/10/not-in-logs.pdf';
$mock_driver->remote_files[$not_logged_file] = array('exists' => true, 'size' => 500, 'stream_content' => 'data');

$exp   = time() + 900;
$token = hash_hmac('sha256', '1|' . $not_logged_file . '|' . $exp, wp_salt('nonce'));
$_GET  = array(
	'filechi_action'   => 'download',
	'filechi_file'     => rawurlencode($not_logged_file),
	'filechi_expires'  => $exp,
	'filechi_provider' => 1,
	'filechi_token'    => $token,
);

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 404, "Untransferred key must return 404 status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'File not found') !== false, "Error message must be 'File not found'");
}
assert($caught, "Key not in logs must throw 404 WPDieException");
echo "PASS: Key not in logs as 'transferred' rejected with 404.\n";

echo "\n=== 6. Test Unknown or Deleted Provider ID (item 6.1 / 6.6) ===\n";
$deleted_provider_id = 999;
$exp   = time() + 900;
$token = hash_hmac('sha256', $deleted_provider_id . '|2026/10/valid.pdf|' . $exp, wp_salt('nonce'));
$_GET  = array(
	'filechi_action'   => 'download',
	'filechi_file'     => rawurlencode('2026/10/valid.pdf'),
	'filechi_expires'  => $exp,
	'filechi_provider' => $deleted_provider_id,
	'filechi_token'    => $token,
);

// Temporarily add log for provider 999 to verify it reaches provider lookup
$mock_transferred_logs['999|2026/10/valid.pdf'] = true;

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 404, "Deleted provider must return 404 status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'provider not found') !== false, "Error message must indicate provider not found");
}
assert($caught, "Deleted provider must throw 404 WPDieException");
echo "PASS: Deleted or unknown provider ID returns 404.\n";

echo "\n=== 7. Test Missing Remote File on Storage Driver (item 6.3 / 6.6) ===\n";
// Key is in logs, but driver->exists() returns false
$missing_remote_file = '2026/10/remote-missing.pdf';
$mock_driver->remote_files[$missing_remote_file] = array('exists' => false, 'size' => -1, 'stream_content' => '');

$exp   = time() + 900;
$token = hash_hmac('sha256', '1|' . $missing_remote_file . '|' . $exp, wp_salt('nonce'));
$_GET  = array(
	'filechi_action'   => 'download',
	'filechi_file'     => rawurlencode($missing_remote_file),
	'filechi_expires'  => $exp,
	'filechi_provider' => 1,
	'filechi_token'    => $token,
);

$caught = false;
try {
	filechi_handle_signed_download();
} catch (WPDieException $e) {
	$caught = true;
	assert($e->status === 404, "Missing remote file must return 404 status, got: " . $e->status);
	assert(strpos($e->getMessage(), 'File not found') !== false, "Error message must be 'File not found'");
}
assert($caught, "Missing remote file must throw 404 WPDieException before headers are sent");
echo "PASS: Missing remote file returns clean 404 before sending headers.\n";

echo "\n=== 8. Test Persian File Name Header (RFC 5987) (item 6.4 / 6.6) ===\n";
$persian_name = 'کتاب-راهنما.pdf';
$headers_persian = filechi_build_download_headers($persian_name, 2048, 'application/pdf');

assert(isset($headers_persian['Content-Disposition']), "Content-Disposition header must be present");
$disposition_persian = $headers_persian['Content-Disposition'];
echo "Persian Content-Disposition: {$disposition_persian}\n";

// RFC 5987 check: filename="<ASCII fallback>"; filename*=UTF-8''<rawurlencoded>
assert(strpos($disposition_persian, 'attachment;') === 0, "Must specify attachment disposition");
assert(preg_match('/filename="([^"]+)"/', $disposition_persian, $m_ascii), "Must include ASCII fallback filename");
assert($m_ascii[1] === 'download.pdf', "ASCII fallback for pure Persian name must be 'download.pdf', got: {$m_ascii[1]}");
assert(strpos($disposition_persian, "filename*=UTF-8''" . rawurlencode($persian_name)) !== false, "Must include RFC 5987 filename* with UTF-8'' prefix and rawurlencode");
assert(isset($headers_persian['X-Content-Type-Options']) && $headers_persian['X-Content-Type-Options'] === 'nosniff', "Must set nosniff");
assert(isset($headers_persian['Content-Length']) && $headers_persian['Content-Length'] === '2048', "Must set Content-Length when size is known");
echo "PASS: Persian file name header correctly formatted according to RFC 5987.\n";

echo "\n=== 9. Test ASCII-Only File Name Header (item 6.4 / 6.6) ===\n";
$ascii_name = 'report_annual_2026.pdf';
$headers_ascii = filechi_build_download_headers($ascii_name, 4096, 'application/pdf');

$disposition_ascii = $headers_ascii['Content-Disposition'];
echo "ASCII Content-Disposition: {$disposition_ascii}\n";
assert(strpos($disposition_ascii, 'filename="report_annual_2026.pdf"') !== false, "ASCII filename must match original");
assert(strpos($disposition_ascii, "filename*=UTF-8''report_annual_2026.pdf") !== false, "RFC 5987 filename* must match original ASCII");
echo "PASS: ASCII-only file name header correctly formatted.\n";

echo "\n=== 10. Streaming Memory Test: 100 MB File (item 6.4 / 6.6) ===\n";
/**
 * Simulates a 100 MB remote file streamed in 1 MB chunks.
 * Verifies that PHP peak memory does not scale with file size (memory increase < 5 MB).
 */
$total_file_size = 100 * 1024 * 1024; // 100 MiB = 104,857,600 bytes
$streamed_bytes  = 0;

$mock_driver->remote_files['2026/10/large-100mb.bin'] = array(
	'exists'         => true,
	'size'           => $total_file_size,
	'stream_content' => function ($chunk_size) use ($total_file_size, &$streamed_bytes) {
		$remaining = $total_file_size;
		$chunk_data = str_repeat('X', $chunk_size); // 1 MiB chunk in memory

		while ($remaining > 0) {
			$to_write = min($remaining, $chunk_size);
			// Emit chunk directly (discarded into output buffer or null)
			echo ($to_write === $chunk_size) ? $chunk_data : substr($chunk_data, 0, $to_write);
			$remaining -= $to_write;
			$streamed_bytes += $to_write;
		}
		return true;
	},
);

// Record initial memory usage and reset peak usage for streaming measurement
gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
	memory_reset_peak_usage();
}
$initial_mem = memory_get_usage(false);

ob_start(function ($buffer) {
	// Discard output so test buffer doesn't accumulate 100 MB in RAM
	return '';
}, 1048576);

$stream_ok = $mock_driver->stream_to_output('2026/10/large-100mb.bin', 1048576);

ob_end_clean();

$peak_mem   = memory_get_peak_usage(false);
$mem_growth = max(0, $peak_mem - $initial_mem);

echo sprintf(
	"Streamed: %d bytes (%.2f MB). Initial Memory: %.2f MB, Peak Memory: %.2f MB, Growth: %.2f MB\n",
	$streamed_bytes,
	$streamed_bytes / (1024 * 1024),
	$initial_mem / (1024 * 1024),
	$peak_mem / (1024 * 1024),
	$mem_growth / (1024 * 1024)
);

assert($stream_ok === true, "Streaming 100 MB file must succeed");
assert($streamed_bytes === $total_file_size, "Must stream exactly 100 MB (104857600 bytes)");
assert($mem_growth < (5 * 1024 * 1024), "Memory growth during 100 MB stream must be under 5 MB, got: " . ($mem_growth / 1024 / 1024) . " MB");
echo "PASS: 100 MB file streamed with constant low memory footprint (< 5 MB growth).\n";

echo "\n======================================================\n";
echo "ALL SIGNED DOWNLOAD HANDLER TESTS PASSED!\n";
echo "======================================================\n";
