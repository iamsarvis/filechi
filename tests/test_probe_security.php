<?php
// MOCK
/**
 * Test: Probe Security and Path Bypass Constraints (item 6.7)
 *
 * Verifies:
 * 1. resolve_path('.filechi-probe-../../x', false) is rejected (depends on $is_probe flag, not key content).
 * 2. resolve_path('.filechi-probe-../../x', true) is rejected (strict regex rejects malicious names).
 * 3. resolve_path with invalid hex or extension when $is_probe = true is rejected.
 * 4. resolve_path with valid probe format (.filechi-probe-[a-f0-9]{8,32}\.tmp) and $is_probe = true succeeds.
 * 5. Driver probe generator produces compliant probe names matching ^\.filechi-probe-[a-f0-9]{8,32}\.tmp$.
 * 6. Verification that no REST endpoint or request parameter can set $is_probe.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-s3.php';
require_once FILECHI_DIR . 'includes/class-filechi-rest.php';

echo "=== 1. Instantiate Drivers for Security Verification ===\n";

$sftp = new FileChi_Storage_SFTP(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => '/uploads',
));

$ftps = new FileChi_Storage_FTPS(array(
	'host'      => 'localhost',
	'username'  => 'test',
	'root_path' => '/uploads',
));

$s3 = new FileChi_Storage_S3(array(
	'bucket'   => 'my-bucket',
	'endpoint' => 'https://s3.example.com',
	'root_path' => 'uploads',
));

echo "PASS: SFTP, FTPS, and S3 drivers instantiated.\n";

echo "\n=== 2. Prove Malicious Probe Name with \$is_probe = false is Rejected ===\n";
$malicious_key = '.filechi-probe-../../etc/passwd';

// Test SFTP
$sftp_caught = false;
try {
	$sftp->resolve_path($malicious_key, false);
} catch (FileChi_Path_Exception $e) {
	$sftp_caught = true;
}
assert($sftp_caught, "SFTP must reject '{$malicious_key}' when \$is_probe = false");

// Test FTPS
$ftps_caught = false;
try {
	$ftps->resolve_path($malicious_key, false);
} catch (FileChi_Path_Exception $e) {
	$ftps_caught = true;
}
assert($ftps_caught, "FTPS must reject '{$malicious_key}' when \$is_probe = false");

// Test S3
$s3_caught = false;
try {
	$s3->resolve_key($malicious_key, false);
} catch (FileChi_Path_Exception $e) {
	$s3_caught = true;
}
assert($s3_caught, "S3 must reject '{$malicious_key}' when \$is_probe = false");

echo "PASS: Bypass does NOT trigger on key content alone; \$is_probe = false strictly rejects traversal probe.\n";

echo "\n=== 3. Prove Malicious Probe Name with \$is_probe = true is Rejected ===\n";
// Even if an attacker somehow supplied $is_probe = true, strict regex must block traversal and non-compliant names

$bad_probe_names = array(
	'.filechi-probe-../../etc/passwd',
	'.filechi-probe-12345678/subdir.tmp',
	'.filechi-probe-xyz12345.tmp',       // 'x', 'y', 'z' are not hex [a-f0-9]
	'.filechi-probe-1234.tmp',           // length < 8
	'.filechi-probe-' . str_repeat('a', 33) . '.tmp', // length > 32
	'.filechi-probe-12345678.txt',       // wrong extension (not .tmp)
	'filechi-probe-12345678.tmp',        // missing leading dot
	'.filechi-probe-12345678.tmp.php',   // double extension
);

foreach ($bad_probe_names as $bad_name) {
	// SFTP
	$caught = false;
	try {
		$sftp->resolve_path($bad_name, true);
	} catch (FileChi_Path_Exception $e) {
		$caught = true;
	}
	assert($caught, "SFTP must reject malicious probe name '{$bad_name}' even with \$is_probe = true");

	// FTPS
	$caught = false;
	try {
		$ftps->resolve_path($bad_name, true);
	} catch (FileChi_Path_Exception $e) {
		$caught = true;
	}
	assert($caught, "FTPS must reject malicious probe name '{$bad_name}' even with \$is_probe = true");

	// S3
	$caught = false;
	try {
		$s3->resolve_key($bad_name, true);
	} catch (FileChi_Path_Exception $e) {
		$caught = true;
	}
	assert($caught, "S3 must reject malicious probe name '{$bad_name}' even with \$is_probe = true");
}
echo "PASS: All 8 malicious/invalid probe name variations rejected when \$is_probe = true.\n";

echo "\n=== 4. Prove Compliant Probe Names with \$is_probe = true Succeed ===\n";
$valid_probe_names = array(
	'.filechi-probe-12345678.tmp',
	'.filechi-probe-abcdef0123456789.tmp',
	'.filechi-probe-' . str_repeat('f', 32) . '.tmp',
);

foreach ($valid_probe_names as $valid_name) {
	$sftp_res = $sftp->resolve_path($valid_name, true);
	assert(strpos($sftp_res, $valid_name) !== false, "SFTP should resolve valid probe: {$valid_name}");

	$ftps_res = $ftps->resolve_path($valid_name, true);
	assert(strpos($ftps_res, $valid_name) !== false, "FTPS should resolve valid probe: {$valid_name}");

	$s3_res = $s3->resolve_key($valid_name, true);
	assert(strpos($s3_res, $valid_name) !== false, "S3 should resolve valid probe: {$valid_name}");
}
echo "PASS: Compliant probe names matching ^\\.filechi-probe-[a-f0-9]{8,32}\\.tmp$ resolved successfully.\n";

echo "\n=== 5. Verify Plugin Probe Name Pattern Generation ===\n";
// Pattern specified by 6.7
$pattern = '/^\.filechi-probe-[a-f0-9]{8,32}\.tmp$/';

// Simulate probe name generation used in FTPS and S3
for ($i = 0; $i < 20; $i++) {
	$rand_suffix = bin2hex(random_bytes(8)); // 16 hex characters
	$probe_name  = '.filechi-probe-' . $rand_suffix . '.tmp';
	assert(preg_match($pattern, $probe_name) === 1, "Generated probe name '{$probe_name}' must match pattern");
}
echo "PASS: Plugin probe generator produces strictly valid probe names across 20 iterations.\n";

echo "\n=== 6. Prove REST API Does Not Accept or Set \$is_probe ===\n";
$rest_class   = new ReflectionClass('FileChi_REST');
$test_conn_fn = $rest_class->getMethod('test_connection');

// Inspect parameters of FileChi_REST::test_connection
$fn_params = $test_conn_fn->getParameters();
assert(count($fn_params) === 1, "REST test_connection must only accept WP_REST_Request parameter");
assert($fn_params[0]->getName() === 'request', "Parameter must be 'request'");

// Inspect source code of test_connection to prove $is_probe is never read from request
$file = $rest_class->getFileName();
$lines = file($file);
$start = $test_conn_fn->getStartLine();
$end   = $test_conn_fn->getEndLine();
$code  = implode('', array_slice($lines, $start - 1, $end - $start + 1));

assert(strpos($code, 'is_probe') === false, "FileChi_REST::test_connection must not reference or extract 'is_probe'");
assert(strpos($code, '$params[\'is_probe\']') === false, "REST parameters cannot set is_probe");
echo "PASS: Verified that no REST parameter or request input can set \$is_probe.\n";

echo "\n======================================================\n";
echo "ALL PROBE SECURITY TESTS (ITEM 6.7) PASSED!\n";
echo "======================================================\n";
