<?php
// MOCK
/**
 * Test: FTPS Credential Safety and Stripped AUTH TLS Protection (Item 4.4)
 *
 * Verifies:
 * 1. Stage 1 (connect) uses CURLUSESSL_TRY but NEVER sends user's real credentials (anonymous or none).
 * 2. Every stage and transfer sending credentials enforces CURLUSESSL_ALL.
 * 3. A server that strips AUTH TLS causes cURL to abort with CURLE_USE_SSL_FAILED (64)
 *    and NEVER transmits the password in clear text.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';

echo "=== 1. Static Verification of Control Channel TLS & Credential Options ===\n";

$ftps_source = file_get_contents(FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php');
assert(!empty($ftps_source), 'Failed to read FTPS driver source code');

// In test_stage_connect: CURLUSESSL_TRY must be used and CURLOPT_USERPWD must NOT be set
$stage_connect_matches = array();
if (preg_match('/function test_stage_connect\(.*?\)\s*\{(.*?)\n\t\}/s', $ftps_source, $stage_connect_matches)) {
	$connect_body = $stage_connect_matches[1];
	assert(strpos($connect_body, 'CURLUSESSL_TRY') !== false, 'test_stage_connect must use CURLUSESSL_TRY');
	assert(strpos($connect_body, 'CURLOPT_USERPWD') === false, 'test_stage_connect must never set CURLOPT_USERPWD');
	echo "PASS: test_stage_connect statically verified to use CURLUSESSL_TRY and never set CURLOPT_USERPWD.\n";
} else {
	throw new RuntimeException('Could not extract test_stage_connect body');
}

// In create_curl_handle: CURLOPT_USE_SSL must be CURLUSESSL_ALL
$create_handle_matches = array();
if (preg_match('/protected function create_curl_handle\(.*?\)\s*\{(.*?)\n\t\}/s', $ftps_source, $create_handle_matches)) {
	$handle_body = $create_handle_matches[1];
	assert(strpos($handle_body, 'CURLUSESSL_ALL') !== false, 'create_curl_handle must enforce CURLUSESSL_ALL');
	echo "PASS: create_curl_handle statically verified to enforce CURLUSESSL_ALL.\n";
} else {
	throw new RuntimeException('Could not extract create_curl_handle body');
}

echo "\n=== 2. Real Wire Test: Connect Stage Credential Non-Leak Proof ===\n";

$secret_username = 'filechi_admin_user_' . bin2hex(random_bytes(3));
$secret_password = 'P@ssw0rd!SuperSecret#' . bin2hex(random_bytes(4));

$temp_dir = sys_get_temp_dir();
$worker_script = $temp_dir . '/filechi_fake_ftp_server_' . uniqid() . '.php';
$log_file_1    = $temp_dir . '/filechi_wire_log_1_' . uniqid() . '.log';
$log_file_2    = $temp_dir . '/filechi_wire_log_2_' . uniqid() . '.log';

// Server script to capture wire traffic for test 1
$server_code_1 = <<<'PHP'
<?php
$log_file = $argv[1];
$server   = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) exit(1);
$name = stream_socket_get_name($server, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
echo "PORT:{$port}\n";
flush();

$client = stream_socket_accept($server, 5);
if ($client) {
    fwrite($client, "220 Welcome to Fake FTP Control Channel\r\n");
    $captured = '';
    while (!feof($client)) {
        $line = fgets($client, 1024);
        if ($line === false) break;
        $captured .= $line;
        if (stripos($line, 'AUTH') !== false) {
            fwrite($client, "500 Stripped TLS - Plaintext Only\r\n");
        }
        if (stripos($line, 'QUIT') !== false) {
            fwrite($client, "221 Bye\r\n");
            break;
        }
    }
    fclose($client);
    file_put_contents($log_file, $captured);
}
fclose($server);
PHP;

file_put_contents($worker_script, $server_code_1);

$php_bin = PHP_BINARY ?: 'C:\\wamp64\\bin\\php\\php8.3.28\\php.exe';

$descriptors = array(
	0 => array('pipe', 'r'),
	1 => array('pipe', 'w'),
	2 => array('pipe', 'w'),
);

// Launch fake server for connect stage test
$proc = proc_open("\"{$php_bin}\" \"{$worker_script}\" \"{$log_file_1}\"", $descriptors, $pipes);
assert(is_resource($proc), 'Failed to spawn fake FTP server');

$banner_line = fgets($pipes[1]);
preg_match('/PORT:(\d+)/', (string) $banner_line, $m);
assert(!empty($m[1]), 'Failed to read fake server port');
$test_port = (int) $m[1];

$driver = new FileChi_Storage_FTPS(array(
	'host'      => '127.0.0.1',
	'port'      => $test_port,
	'username'  => $secret_username,
	'password'  => $secret_password,
	'ftps_mode' => 'explicit',
));

// Execute Stage 1 (connect)
$stages = array();
$driver->test_stage_connect($stages, '127.0.0.1', $test_port, 'ftp', 'explicit');

fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$wire_traffic_1 = file_exists($log_file_1) ? file_get_contents($log_file_1) : '';

assert(strpos($wire_traffic_1, $secret_username) === false, "User's real username MUST NEVER be sent in connect stage");
assert(strpos($wire_traffic_1, $secret_password) === false, "User's real password MUST NEVER be sent in connect stage");
assert(strpos($wire_traffic_1, 'PASS ') === false, 'PASS command must never appear in connect stage');

echo "PASS: Wire test confirmed Stage 1 (connect) never transmits real username or password.\n";

echo "\n=== 3. Real Wire Test: Stripped AUTH TLS Protection (CURLUSESSL_ALL) ===\n";

// Server script for test 2
$proc_2 = proc_open("\"{$php_bin}\" \"{$worker_script}\" \"{$log_file_2}\"", $descriptors, $pipes_2);
assert(is_resource($proc_2), 'Failed to spawn fake FTP server for test 2');

$banner_line_2 = fgets($pipes_2[1]);
preg_match('/PORT:(\d+)/', (string) $banner_line_2, $m2);
assert(!empty($m2[1]), 'Failed to read fake server port 2');
$test_port_2 = (int) $m2[1];

// Driver with explicit FTPS connecting to the stripped-TLS rogue server
$driver_rogue = new FileChi_Storage_FTPS(array(
	'host'      => '127.0.0.1',
	'port'      => $test_port_2,
	'username'  => $secret_username,
	'password'  => $secret_password,
	'ftps_mode' => 'explicit',
));

// Attempt an authenticated operation (e.g. Stage 3 Login or an upload)
$stages_2 = array();
$login_res = $driver_rogue->test_stage_login($stages_2, 'ftp', '127.0.0.1', $test_port_2);

fclose($pipes_2[0]);
fclose($pipes_2[1]);
fclose($pipes_2[2]);
proc_close($proc_2);

$wire_traffic_2 = file_exists($log_file_2) ? file_get_contents($log_file_2) : '';

assert(strpos($wire_traffic_2, $secret_username) === false, "Rogue server MUST NOT receive username when AUTH TLS is stripped");
assert(strpos($wire_traffic_2, $secret_password) === false, "Rogue server MUST NOT receive password when AUTH TLS is stripped");
assert(strpos($wire_traffic_2, 'PASS ') === false, 'PASS command must never be sent in plaintext when AUTH TLS is stripped');

// Verify that the login failed with SSL failure (curl 64)
assert($login_res !== null && $login_res['success'] === false, 'Login must fail when server strips AUTH TLS');
assert($login_res['details']['curl_errno'] === 64, 'cURL errno must be 64 (CURLE_USE_SSL_FAILED)');

echo "PASS: Wire test confirmed stripped AUTH TLS causes immediate abort with curl 64; password never transmitted in clear text.\n";

// Cleanup temp files
@unlink($worker_script);
@unlink($log_file_1);
@unlink($log_file_2);

echo "\nAll FTPS credential safety checks passed successfully!\n";
