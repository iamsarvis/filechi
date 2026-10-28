<?php
// REAL
/**
 * Real FTPS Integration Test for Restore Engine (Item R1.8)
 *
 * Communicates with a real loopback explicit TLS FTPS server (pyftpdlib):
 * - Uploads multiple files (standard, Persian UTF-8 'تصویر-تست.jpg', nested subdirectories)
 * - Verifies remote existence and size
 * - Deletes local copies
 * - Downloads / restores each file over explicit TLS data channel
 * - Verifies restored file SHA-256 matches the original exactly
 * - Cleans up remote and local test files
 */

// If invoked directly by tests/run.php without arguments, spawn via the Python FTPS server wrapper
if ($argc <= 1) {
	$python_bin = 'C:\\Users\\98903\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
	if (!file_exists($python_bin)) {
		$python_bin = 'python';
	}

	$server_script = __DIR__ . '/test_ftps_server.py';
	$this_script   = __FILE__;

	$cmd = "\"{$python_bin}\" \"{$server_script}\" \"{$this_script}\" 2>&1";
	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
	);

	$proc = proc_open($cmd, $descriptors, $pipes, dirname(dirname(__DIR__)));
	if (!is_resource($proc)) {
		echo "Failed to start Python FTPS test runner\n";
		exit(1);
	}

	fclose($pipes[0]);
	while (!feof($pipes[1])) {
		$line = fgets($pipes[1]);
		if ($line !== false) {
			echo $line;
		}
	}
	fclose($pipes[1]);

	$code = proc_close($proc);
	exit($code);
}

// When called with arguments by test_ftps_server.py: execute the real FTPS tests
require_once dirname(__DIR__) . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';

$host         = $argv[1] ?? '127.0.0.1';
$port         = isset($argv[2]) ? (int) $argv[2] : 2121;
$username     = $argv[3] ?? 'testuser';
$password     = $argv[4] ?? 'testpass';
$expected_pin = $argv[5] ?? '';
$root_path    = $argv[6] ?? '/uploads';

echo "=== Real FTPS Restore Verification (Item R1.8) ===\n";
echo "Target: ftps://{$host}:{$port} (User: {$username})\n";
echo "Root Path: {$root_path}\n";
echo "TLS Pin: {$expected_pin}\n\n";

$driver = new FileChi_Storage_FTPS(array(
	'host'      => $host,
	'port'      => $port,
	'username'  => $username,
	'password'  => $password,
	'ftps_mode' => 'explicit',
	'tls_pin'   => $expected_pin,
	'passive'   => 1,
	'root_path' => $root_path,
));

// Verify connection
$conn_test = $driver->test_connection();
assert($conn_test['success'] === true, 'FTPS connection test must succeed: ' . ($conn_test['message'] ?? ''));
echo "[+] Real FTPS server connection established with explicit TLS pin validation.\n\n";

$temp_base = wp_normalize_path(sys_get_temp_dir() . '/filechi_real_restore_' . uniqid());
$orig_dir  = $temp_base . '/original';
$dest_dir  = $temp_base . '/restored';
@mkdir($orig_dir, 0777, true);
@mkdir($dest_dir, 0777, true);

// Test fixtures
$test_files = array(
	'2026/10/sample-banner.jpg' => "JFIF\x00\x01\x01\x01\x00\x60\x00\x60\x00\x00" . str_repeat('StandardImageBinaryData', 100),
	'2026/10/تصویر-تست.jpg'     => "UTF8-PERSIAN-IMAGE\x00\xFF\xD8\xFF" . str_repeat('فایل تستی فارسی با نام تصویر-تست', 50),
	'2026/10/sub/archive.pdf'   => "%PDF-1.4\n1 0 obj\n<< /Title (Test Document) >>\nendobj\n" . str_repeat("PDFSTREAMCONTENT\n", 40),
);

$passed_files = 0;

foreach ($test_files as $rel_path => $content) {
	echo "[-] Testing round-trip restore for: {$rel_path}...\n";

	$orig_file = $orig_dir . '/' . $rel_path;
	$dest_file = $dest_dir . '/' . $rel_path;
	@wp_mkdir_p(dirname($orig_file));
	@wp_mkdir_p(dirname($dest_file));

	file_put_contents($orig_file, $content);
	$orig_size = filesize($orig_file);
	$orig_hash = hash_file('sha256', $orig_file);

	// 1. Upload to remote FTPS server
	$upload_res = $driver->upload($orig_file, $rel_path);
	assert($upload_res === true, "Upload must succeed for {$rel_path}: " . $driver->get_last_error());

	// 2. Verify remote exists and size matches
	assert($driver->exists($rel_path) === true, "Remote file must exist: {$rel_path}");
	$remote_size = $driver->get_size($rel_path);
	assert($remote_size === $orig_size, "Remote size ({$remote_size}) must match original size ({$orig_size}) for {$rel_path}");

	// 3. Delete local original file to simulate offload
	@unlink($orig_file);
	assert(!file_exists($orig_file), "Original local file must be deleted before restore");

	// 4. Download / restore from remote FTPS server
	$download_res = $driver->download($rel_path, $dest_file, $orig_size);
	assert($download_res === true, "Download must succeed for {$rel_path}: " . $driver->get_last_error());
	assert(file_exists($dest_file), "Restored local file must exist: {$dest_file}");

	// 5. Compare SHA-256 hash
	$restored_hash = hash_file('sha256', $dest_file);
	assert($restored_hash === $orig_hash, "Restored SHA-256 ({$restored_hash}) must match original SHA-256 ({$orig_hash}) for {$rel_path}");

	// 6. Delete remote file
	$del_res = $driver->delete($rel_path);
	assert($del_res === true, "Remote deletion must succeed for {$rel_path}");
	assert($driver->exists($rel_path) === false, "Remote file must not exist after deletion for {$rel_path}");

	// 7. Cleanup local restored file
	@unlink($dest_file);

	$passed_files++;
	echo "    [+] PASS: Upload -> delete local -> restore -> SHA-256 matched -> delete remote verified.\n";
}

// Cleanup local temp directories
$cleanup_recursive = function($dir) use (&$cleanup_recursive) {
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
			$cleanup_recursive($p);
		} else {
			@unlink($p);
		}
	}
	@rmdir($dir);
};
$cleanup_recursive($temp_base);

echo "\n========================================================\n";
echo "ALL {$passed_files} REAL FTPS RESTORE CHECKS PASSED SUCCESSFULLY!\n";
echo "========================================================\n";
exit(0);
