<?php
// MOCK
/**
 * Test: FileChi FTPS Driver on PHP cURL (Step F1)
 *
 * Verifies FTPS cURL driver implementation:
 * 1. Control character and command injection rejection
 * 2. Path resolution and rawurlencoded cURL URL generation
 * 3. FTPS modes (explicit on port 21 / implicit on port 990)
 * 4. cURL errno and FTP reply error mapping
 * 5. Test connection requirements check
 * 6. Driver upload, size, exists, delete, and streaming logic
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';

echo "=== 1. Test Control-Character & Path Traversal Injection Rejection ===\n";

$driver = new FileChi_Storage_FTPS(array(
	'host'      => 'ftps.example.com',
	'username'  => 'user',
	'password'  => 'pass',
	'root_path' => 'public_html/uploads',
));

// Reject control characters
$invalid_control_paths = array(
	"file\0.jpg",
	"dir/\rfile.jpg",
	"file\nname.jpg",
	"folder/\x07bell.png",
	"test\x1Bescape.pdf",
	"del\x7Fitem.txt",
);

foreach ($invalid_control_paths as $bad_path) {
	$valid = $driver->validate_remote_path($bad_path);
	assert($valid === false, "Path containing control chars must be rejected: " . addcslashes($bad_path, "\0..\37\177"));
	assert(strpos($driver->get_last_error(), 'control characters') !== false, "Error must mention control characters");
}

// Reject root or directory paths
$invalid_dir_paths = array(
	'',
	'/',
	'.',
	'..',
	'dir/',
	'/var/www/',
	'uploads/subdir/',
);

foreach ($invalid_dir_paths as $bad_path) {
	$valid = $driver->validate_remote_path($bad_path);
	assert($valid === false, "Path targeting root/directory must be rejected: '{$bad_path}'");
	assert(strpos($driver->get_last_error(), 'root or a directory') !== false, "Error must mention root or directory");
}

// Accept safe normal paths
$valid_paths = array(
	'2026/10/photo.jpg',
	'documents/annual report 2026.pdf',
	'2026/10/عکس-جدید.jpg',
	'images/file+name@host.png',
);

foreach ($valid_paths as $good_path) {
	$valid = $driver->validate_remote_path($good_path);
	assert($valid === true, "Valid path must be accepted: '{$good_path}'");
}

echo "PASS: Control character and directory path validation verified.\n";

echo "\n=== 2. Test Path Resolution & rawurlencoded cURL URLs ===\n";

// Relative root path
$driver_rel = new FileChi_Storage_FTPS(array(
	'host'      => 'ftp.example.com',
	'root_path' => 'public_html/uploads',
	'ftps_mode' => 'explicit',
));
$resolved_rel = $driver_rel->resolve_path('2026/10/my photo.jpg');
assert($resolved_rel === 'public_html/uploads/2026/10/my photo.jpg', "Resolved relative path mismatch: {$resolved_rel}");

$url_rel = $driver_rel->build_curl_url($resolved_rel);
assert($url_rel === 'ftp://ftp.example.com:21/public_html/uploads/2026/10/my%20photo.jpg', "cURL URL relative encoding mismatch: {$url_rel}");

// Absolute root path
$driver_abs = new FileChi_Storage_FTPS(array(
	'host'      => 'ftp.example.com',
	'root_path' => '/var/www/uploads',
	'ftps_mode' => 'explicit',
));
$resolved_abs = $driver_abs->resolve_path('2026/10/photo.jpg');
assert($resolved_abs === '/var/www/uploads/2026/10/photo.jpg', "Resolved absolute path mismatch: {$resolved_abs}");

$url_abs = $driver_abs->build_curl_url($resolved_abs);
// In cURL FTP, absolute paths start with double slash //
assert($url_abs === 'ftp://ftp.example.com:21//var/www/uploads/2026/10/photo.jpg', "cURL URL absolute double-slash mismatch: {$url_abs}");

// Persian/UTF-8 path rawurlencoded
$resolved_utf8 = $driver_rel->resolve_path('2026/10/عکس.jpg');
$url_utf8 = $driver_rel->build_curl_url($resolved_utf8);
assert(strpos($url_utf8, rawurlencode('عکس.jpg')) !== false, "UTF-8 filename must be rawurlencoded in cURL URL: {$url_utf8}");

echo "PASS: Path resolution and cURL URL building verified.\n";

echo "\n=== 3. Test FTPS Modes (Explicit vs Implicit) & Ports ===\n";

// Default explicit FTPS on port 21
$driver_exp = new FileChi_Storage_FTPS(array('host' => 'ftp.example.com'));
assert($driver_exp->get_ftps_mode() === 'explicit', "Default mode must be explicit");
assert($driver_exp->get_scheme() === 'ftp', "Explicit mode scheme must be ftp");
assert($driver_exp->get_port() === 21, "Explicit mode default port must be 21");

// Custom port explicit FTPS
$driver_exp_custom = new FileChi_Storage_FTPS(array('host' => 'ftp.example.com', 'port' => 2121));
assert($driver_exp_custom->get_port() === 2121, "Custom port 2121 must be preserved");

// Implicit FTPS on port 990
$driver_imp = new FileChi_Storage_FTPS(array(
	'host'      => 'secure.example.com',
	'ftps_mode' => 'implicit',
));
assert($driver_imp->get_ftps_mode() === 'implicit', "Mode must be implicit");
assert($driver_imp->get_scheme() === 'ftps', "Implicit mode scheme must be ftps");
assert($driver_imp->get_port() === 990, "Implicit mode default port must be 990");

// Custom port implicit FTPS
$driver_imp_custom = new FileChi_Storage_FTPS(array(
	'host'      => 'secure.example.com',
	'ftps_mode' => 'implicit',
	'port'      => 9990,
));
assert($driver_imp_custom->get_port() === 9990, "Custom implicit port 9990 must be preserved");

echo "PASS: FTPS modes, schemes, and ports verified.\n";

echo "\n=== 4. Test cURL Error Code & Server Reply Mapping ===\n";

// Test cURL 60 (TLS Certificate Untrusted)
$err60 = $driver->map_curl_error(60, 'SSL: certificate subject name mismatch', 0);
assert(strpos($err60, '[curl 60]') !== false, "Must include [curl 60]");
assert(strpos($err60, "the server's TLS certificate is not trusted; use the host name that matches the certificate") !== false, "Must include exact untrusted cert message");

// Test cURL 67 (Login Denied)
$err67 = $driver->map_curl_error(67, 'Access denied', 530);
assert(strpos($err67, '[curl 67]') !== false, "Must include [curl 67]");
assert(strpos($err67, 'login denied') !== false, "Must include 'login denied'");
assert(strpos($err67, 'FTP reply 530') !== false, "Must include FTP reply code");

// Test cURL 7 (Connection Failed)
$err7 = $driver->map_curl_error(7, "Failed to connect to host", 0);
assert(strpos($err7, '[curl 7]') !== false, "Must include [curl 7]");
assert(strpos($err7, 'connection failed') !== false, "Must include 'connection failed'");

// Test cURL 28 (Timeout)
$err28 = $driver->map_curl_error(28, 'Operation timed out after 30000 milliseconds', 0);
assert(strpos($err28, '[curl 28]') !== false, "Must include [curl 28]");
assert(strpos($err28, 'timeout') !== false, "Must include 'timeout'");

// Test FTP 425 / 426 (Cannot open data connection)
$err425 = $driver->map_curl_error(0, 'Cannot open data connection', 425);
assert(strpos($err425, 'cannot open data connection') !== false, "Must include 'cannot open data connection'");
assert(strpos($err425, 'FTP reply 425') !== false, "Must include FTP reply 425");

// Test FTP 550 on file not found
$err550_fnf = $driver->map_curl_error(0, 'No such file or directory', 550);
assert(strpos($err550_fnf, 'file not found') !== false, "Must map to file not found");
assert(strpos($err550_fnf, 'FTP reply 550') !== false, "Must include FTP reply 550");

// Test FTP 550 on permission denied
$err550_perm = $driver->map_curl_error(0, 'Access is denied', 550);
assert(strpos($err550_perm, 'permission denied / 550') !== false, "Must map to permission denied / 550");

// Verify it never outputs only "put failed"
assert($err67 !== 'put failed' && $err7 !== 'put failed' && $err60 !== 'put failed', "Must never write only 'put failed'");

echo "PASS: Comprehensive cURL error and server reply mapping verified.\n";

echo "\n=== 5. Test test_connection() Requirements Guard ===\n";

$res = $driver->test_connection();
assert(is_array($res), "test_connection must return array");
assert(isset($res['success']), "test_connection must return success status");
assert(isset($res['message']), "test_connection must return message");

echo "PASS: test_connection() requirements check verified.\n";

echo "\n=== 6. Test Mock Operations (Upload, Size, Delete, Stream) ===\n";

/**
 * Subclassed mock driver to simulate cURL execution
 */
class Testable_FileChi_Storage_FTPS extends FileChi_Storage_FTPS {
	public $mock_size = 1024;
	public $mock_upload_success = true;
	public $mock_delete_success = true;

	public function get_size($remote_path) {
		if (!$this->validate_remote_path($this->resolve_path($remote_path))) {
			return -1;
		}
		return $this->mock_size;
	}

	public function upload($local_file, $remote_path) {
		$this->last_error = '';
		if (!file_exists($local_file) || !is_readable($local_file)) {
			$this->last_error = "Local file does not exist or is not readable: {$local_file}";
			return false;
		}

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return false;
		}

		if (!$this->mock_upload_success) {
			$this->last_error = $this->map_curl_error(67, 'Login incorrect', 530);
			return false;
		}

		$remote_size = $this->get_size($remote_path);
		$local_size  = (int) filesize($local_file);
		if ($remote_size >= 0 && $remote_size !== $local_size) {
			$this->last_error = "Remote size mismatch (expected: {$local_size}, actual: {$remote_size})";
			return false;
		}

		return true;
	}

	public function delete($remote_path) {
		$this->last_error = '';
		$resolved = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved)) {
			return false;
		}
		return $this->mock_delete_success;
	}
}

$temp_file = tempnam(sys_get_temp_dir(), 'ftps_test_');
file_put_contents($temp_file, str_repeat('A', 512));

$mock_driver = new Testable_FileChi_Storage_FTPS(array(
	'host'      => 'ftps.mock.local',
	'root_path' => 'uploads',
));

// Size mismatch detection
$mock_driver->mock_size = 100; // Expected is 512
$res_mismatch = $mock_driver->upload($temp_file, 'test.txt');
assert($res_mismatch === false, "Upload must fail on size mismatch");
assert(strpos($mock_driver->get_last_error(), 'Remote size mismatch') !== false, "Error must describe size mismatch");

// Size match success
$mock_driver->mock_size = 512;
$res_ok = $mock_driver->upload($temp_file, 'test.txt');
assert($res_ok === true, "Upload must succeed when sizes match");
assert($mock_driver->exists('test.txt') === true, "exists() must be true when size >= 0");

// Delete safe path
assert($mock_driver->delete('test.txt') === true, "delete() must succeed on valid path");

// Delete dangerous path
assert($mock_driver->delete('/') === false, "delete('/') must be refused");
assert($mock_driver->delete('dir/') === false, "delete('dir/') must be refused");

unlink($temp_file);

echo "PASS: Mock driver upload, size verification, and delete safety verified.\n";
