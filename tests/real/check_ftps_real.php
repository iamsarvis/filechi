<?php
// REAL
/**
 * Real FTPS Driver Verification Checks (Item 4.5)
 *
 * Runs real network checks against a local FTPS server:
 * 1. Certificate discovery (needs_trust payload and pin matching)
 * 2. Wrong pin rejection (curl 90 CURLE_SSL_PINNEDPUBKEYNOTMATCH)
 * 3. Right pin acceptance (all 6 stages verified: connect, tls, login, directory, data_connection, write_probe)
 * 4. Real upload over encrypted data channel
 * 5. Real size query
 * 6. Real streaming
 * 7. Real deletion and exists check
 */

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

echo "=== FileChi FTPS Real Server Verification Suite ===\n";
echo "Target: ftps://{$host}:{$port} (User: {$username})\n";
echo "Expected Server Pin: {$expected_pin}\n\n";

// --- CHECK 1: Certificate Discovery ---
echo "[1/7] Testing Certificate Discovery (untrusted CA / no pin configured)...\n";
$driver_discovery = new FileChi_Storage_FTPS(array(
	'host'      => $host,
	'port'      => $port,
	'username'  => $username,
	'password'  => $password,
	'ftps_mode' => 'explicit',
	'tls_pin'   => '',
));

$res_discovery = $driver_discovery->test_connection();
assert(isset($res_discovery['needs_trust']) && $res_discovery['needs_trust'] === true, 'Discovery test must return needs_trust=true');
assert(!empty($res_discovery['fingerprint']), 'Discovery test must return certificate fingerprint');
assert(FileChi_Storage_FTPS::is_valid_tls_pin($res_discovery['fingerprint']), 'Fingerprint must be a valid sha256// pin');
if (!empty($expected_pin)) {
	assert($res_discovery['fingerprint'] === $expected_pin, "Discovered pin '{$res_discovery['fingerprint']}' must match expected pin '{$expected_pin}'");
}
$server_pin = $res_discovery['fingerprint'];
echo "  [+] PASS: Discovered server pin: {$server_pin}\n";

// --- CHECK 2: Wrong Pin Rejection (curl 90) ---
echo "[2/7] Testing Wrong Pin Rejection (curl 90)...\n";
$wrong_pin = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
$driver_wrong = new FileChi_Storage_FTPS(array(
	'host'      => $host,
	'port'      => $port,
	'username'  => $username,
	'password'  => $password,
	'ftps_mode' => 'explicit',
	'tls_pin'   => $wrong_pin,
));

$res_wrong = $driver_wrong->test_connection();
assert($res_wrong['success'] === false, 'Wrong pin connection test must fail');
assert(isset($res_wrong['details']['curl_errno']) && $res_wrong['details']['curl_errno'] === 90, 'Curl errno must be 90 (CURLE_SSL_PINNEDPUBKEYNOTMATCH)');
assert(strpos($res_wrong['message'], 'PINNED KEY MISMATCH') !== false, 'Failure message must mention PINNED KEY MISMATCH');
echo "  [+] PASS: Wrong pin was rejected with curl 90 (PINNED KEY MISMATCH)\n";

// --- CHECK 3: Right Pin Acceptance (All 6 Stages) ---
echo "[3/7] Testing Right Pin Connection (All 6 Stages)...\n";
$driver_right = new FileChi_Storage_FTPS(array(
	'host'      => $host,
	'port'      => $port,
	'username'  => $username,
	'password'  => $password,
	'ftps_mode' => 'explicit',
	'tls_pin'   => $server_pin,
	'passive'   => 1,
));

$res_right = $driver_right->test_connection();
assert($res_right['success'] === true, "Connection test with right pin must succeed: {$res_right['message']}");
assert(isset($res_right['stages']) && is_array($res_right['stages']), 'Connection result must include stages array');
assert(count($res_right['stages']) === 6, 'Must execute exactly 6 diagnostic stages');

$expected_stages = array('connect', 'tls', 'login', 'directory', 'data_connection', 'write_probe');
foreach ($expected_stages as $idx => $stage_name) {
	assert(isset($res_right['stages'][$idx]['name']), "Stage index {$idx} must have name");
	assert($res_right['stages'][$idx]['name'] === $stage_name, "Stage {$idx} must be {$stage_name}");
	assert($res_right['stages'][$idx]['ok'] === true, "Stage {$stage_name} must succeed: " . ($res_right['stages'][$idx]['detail'] ?? ''));
}
echo "  [+] PASS: All 6 stages succeeded with verified public key pin!\n";

// --- CHECK 4: Real Upload over Encrypted TLS Data Channel ---
echo "[4/7] Testing Upload over FTPS TLS Data Channel...\n";
$upload_content = "FileChi Real FTPS Integration Test - " . bin2hex(random_bytes(16)) . "\n" . str_repeat("TLS-Data-Channel-Payload\n", 40);
$temp_file = tempnam(sys_get_temp_dir(), 'filechi_real_ftps_');
file_put_contents($temp_file, $upload_content);
$local_size = (int) filesize($temp_file);

$remote_file = 'test_real_ftps_' . bin2hex(random_bytes(4)) . '.txt';
$upload_ok   = $driver_right->upload($temp_file, $remote_file);
assert($upload_ok === true, "Upload failed: " . $driver_right->get_last_error());
echo "  [+] PASS: Successfully uploaded {$local_size} bytes to '{$remote_file}'\n";

// --- CHECK 5: Real Size Query ---
echo "[5/7] Testing Remote File Size Query...\n";
$remote_size = $driver_right->get_size($remote_file);
assert($remote_size === $local_size, "Remote size mismatch: expected {$local_size}, got {$remote_size}");
assert($driver_right->exists($remote_file) === true, "exists() must return true for uploaded file");
echo "  [+] PASS: Remote file size correctly verified ({$remote_size} bytes)\n";

// --- CHECK 6: Real Stream Output ---
echo "[6/7] Testing Stream to Output...\n";
usleep(100000); // 100ms for clean socket recycling
$streamed_data = '';
ob_start(function ($chunk) use (&$streamed_data) {
	$streamed_data .= $chunk;
	return '';
});
$stream_ok = $driver_right->stream_to_output($remote_file);
if (ob_get_level() > 0) {
	ob_end_clean();
}

assert($stream_ok === true, "stream_to_output failed: " . $driver_right->get_last_error());
assert($streamed_data === $upload_content, "Streamed content did not match uploaded content");
echo "  [+] PASS: File content successfully streamed and verified (" . strlen($streamed_data) . " bytes)\n";

// --- CHECK 7: Real Deletion ---
echo "[7/7] Testing Remote File Deletion...\n";
usleep(100000);
$delete_ok = $driver_right->delete($remote_file);
assert($delete_ok === true, "delete() failed: " . $driver_right->get_last_error());
assert($driver_right->exists($remote_file) === false, "exists() must return false after deletion");
echo "  [+] PASS: File deleted and confirmed non-existent\n";

@unlink($temp_file);

echo "\n=======================================================\n";
echo "ALL 7 FTPS REAL CHECKS PASSED SUCCESSFULLY!\n";
echo "=======================================================\n";
exit(0);
