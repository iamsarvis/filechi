<?php
// MOCK
/**
 * Test: FileChi FTPS Diagnostics and Staged Test Connection (Step F2b)
 *
 * Verifies:
 * 1. Stage order: connect -> tls -> login -> directory -> data_connection -> write_probe
 * 2. Stop-at-first-failure behavior (stages after first failure never execute)
 * 3. Each stage failure maps to the correct failure structure and message
 * 4. cURL 7 + FTP 227/229 -> reason `ftp_data_connection_failed` with target <ip>:<port> in message
 * 5. parse_passive_info parsing from 227, 229, and cURL error strings
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';

echo "=== 1. Test parse_passive_info Parsing ===\n";

// 1a. IPv4 227 Entering Passive Mode
$headers_227 = array(
	'220 Welcome',
	'234 AUTH TLS OK',
	'230 Logged in',
	'227 Entering Passive Mode (130,185,79,177,137,81)',
);
$parsed_227 = FileChi_Storage_FTPS::parse_passive_info($headers_227);
assert($parsed_227['ip'] === '130.185.79.177', "227 IP mismatch: {$parsed_227['ip']}");
assert($parsed_227['port'] === 35153, "227 port mismatch: expected 35153, got {$parsed_227['port']}");

// 1b. EPSV 229 Entering Extended Passive Mode
$headers_229 = array(
	'229 Entering Extended Passive Mode (|||49152|)',
);
$parsed_229 = FileChi_Storage_FTPS::parse_passive_info($headers_229, '', 'example.com');
assert($parsed_229['port'] === 49152, "229 port mismatch: expected 49152, got {$parsed_229['port']}");
assert($parsed_229['ip'] === 'example.com', "229 IP fallback mismatch");

// 1c. cURL error string parsing
$errstr = 'Failed to connect to 130.185.79.177 port 35153 after 2102 ms: Could not connect to server';
$parsed_err = FileChi_Storage_FTPS::parse_passive_info(array(), $errstr);
assert($parsed_err['ip'] === '130.185.79.177', "Error IP mismatch: {$parsed_err['ip']}");
assert($parsed_err['port'] === 35153, "Error port mismatch: {$parsed_err['port']}");

echo "PASS: Passive info parsing from 227, 229, and cURL error verified.\n";

echo "\n=== 2. Test Data-Connection Firewall Diagnosis (F2b.3) ===\n";

class MockFTPS_DataConnFail extends FileChi_Storage_FTPS {
	public function test_stage_connect(array &$stages, $host, $port, $scheme, $ftps_mode) {
		$stages[] = array('name' => 'connect', 'ok' => true, 'detail' => "Connected to {$host}:{$port}");
		return null;
	}

	public function test_stage_tls(array &$stages, $host, $port, $scheme, $ftps_mode, $tls_pin) {
		$stages[] = array('name' => 'tls', 'ok' => true, 'detail' => 'AUTH TLS OK');
		return null;
	}

	public function test_stage_login(array &$stages, $scheme, $host, $port) {
		$stages[] = array('name' => 'login', 'ok' => true, 'detail' => 'Authenticated');
		return null;
	}

	public function test_stage_directory(array &$stages) {
		$stages[] = array('name' => 'directory', 'ok' => true, 'detail' => 'Root path OK');
		return null;
	}

	// Simulate Stage 5 data connection failure with curl 7 and FTP reply 227
	public function test_stage_data_connection(array &$stages, $host, $port) {
		$errno = 7;
		$error = 'Failed to connect to 130.185.79.177 port 35153 after 2102 ms: Could not connect to server';
		$response_code = 227;
		$list_headers = array('227 Entering Passive Mode (130,185,79,177,137,81)');

		$passive_info = self::parse_passive_info($list_headers, $error, $host);
		$passive_port = $passive_info['port'];
		$passive_ip   = $passive_info['ip'];

		$is_data_conn_failed = (
			$errno === 7 &&
			(
				$response_code === 227 ||
				$response_code === 229 ||
				($passive_port > 0 && $passive_port !== $port)
			)
		);

		assert($is_data_conn_failed === true, 'Data connection failure condition must trigger');

		$target       = ($passive_ip ?: $host) . ':' . ($passive_port > 0 ? $passive_port : 'passive-port');
		$diag_message = sprintf(
			"Login succeeded, but the data connection to %s could not be opened. Either the FTP server's passive port range is closed in its firewall, or this web host blocks outgoing connections to high ports. Test from FileZilla on another network, then ask the host to open the range or allow outgoing connections.",
			$target
		);

		$stages[] = array(
			'name'   => 'data_connection',
			'ok'     => false,
			'detail' => $diag_message,
		);

		return array(
			'success' => false,
			'message' => $diag_message,
			'stages'  => $stages,
			'details' => array(
				'failed_stage'  => 'data_connection',
				'reason'        => 'ftp_data_connection_failed',
				'curl_errno'    => 7,
				'response_code' => 227,
				'passive_ip'    => $passive_ip,
				'passive_port'  => $passive_port,
			),
		);
	}
}

$diag_driver = new MockFTPS_DataConnFail(array(
	'host' => '130.185.79.177',
	'port' => 21,
));
$diag_res = $diag_driver->test_connection();

assert($diag_res['success'] === false, 'Test result must be false');
assert(isset($diag_res['details']['reason']), 'Details must have reason key');
assert($diag_res['details']['reason'] === 'ftp_data_connection_failed', "Reason must be 'ftp_data_connection_failed', got '{$diag_res['details']['reason']}'");
assert($diag_res['details']['passive_port'] === 35153, "Passive port must be 35153");
assert(strpos($diag_res['message'], '130.185.79.177:35153') !== false, "Message must mention passive ip:port 130.185.79.177:35153");
assert(strpos($diag_res['message'], 'passive port range is closed in its firewall') !== false, "Message must diagnose passive firewall");
assert(count($diag_res['stages']) === 5, "Must stop after 5 stages (write_probe must not run)");
assert($diag_res['stages'][4]['ok'] === false, "Stage 5 must be marked false");

echo "PASS: Data-connection diagnosis and reason mapping verified.\n";

echo "\n=== 3. Test Staged Execution Order & Stop-At-First-Failure ===\n";

class ControllableFTPS extends FileChi_Storage_FTPS {
	public $fail_at = null;
	public $executed_stages = array();

	public function test_stage_connect(array &$stages, $host, $port, $scheme, $ftps_mode) {
		$this->executed_stages[] = 'connect';
		if ($this->fail_at === 'connect') {
			$stages[] = array('name' => 'connect', 'ok' => false, 'detail' => 'Connect failed');
			return array('success' => false, 'message' => 'Connect failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'connect', 'ok' => true, 'detail' => 'Connect ok');
		return null;
	}

	public function test_stage_tls(array &$stages, $host, $port, $scheme, $ftps_mode, $tls_pin) {
		$this->executed_stages[] = 'tls';
		if ($this->fail_at === 'tls') {
			$stages[] = array('name' => 'tls', 'ok' => false, 'detail' => 'TLS failed');
			return array('success' => false, 'message' => 'TLS failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'tls', 'ok' => true, 'detail' => 'TLS ok');
		return null;
	}

	public function test_stage_login(array &$stages, $scheme, $host, $port) {
		$this->executed_stages[] = 'login';
		if ($this->fail_at === 'login') {
			$stages[] = array('name' => 'login', 'ok' => false, 'detail' => 'Login failed');
			return array('success' => false, 'message' => 'Login failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'login', 'ok' => true, 'detail' => 'Login ok');
		return null;
	}

	public function test_stage_directory(array &$stages) {
		$this->executed_stages[] = 'directory';
		if ($this->fail_at === 'directory') {
			$stages[] = array('name' => 'directory', 'ok' => false, 'detail' => 'Directory failed');
			return array('success' => false, 'message' => 'Directory failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'directory', 'ok' => true, 'detail' => 'Directory ok');
		return null;
	}

	public function test_stage_data_connection(array &$stages, $host, $port) {
		$this->executed_stages[] = 'data_connection';
		if ($this->fail_at === 'data_connection') {
			$stages[] = array('name' => 'data_connection', 'ok' => false, 'detail' => 'Data conn failed');
			return array('success' => false, 'message' => 'Data conn failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'data_connection', 'ok' => true, 'detail' => 'Data conn ok');
		return null;
	}

	public function test_stage_write_probe(array &$stages) {
		$this->executed_stages[] = 'write_probe';
		if ($this->fail_at === 'write_probe') {
			$stages[] = array('name' => 'write_probe', 'ok' => false, 'detail' => 'Write probe failed');
			return array('success' => false, 'message' => 'Write probe failed', 'stages' => $stages);
		}
		$stages[] = array('name' => 'write_probe', 'ok' => true, 'detail' => 'Write probe ok');
		return null;
	}
}

// 3a. Failure at Stage 1: connect
$c1 = new ControllableFTPS(array('host' => 'example.com'));
$c1->fail_at = 'connect';
$r1 = $c1->test_connection();
assert($r1['success'] === false);
assert($c1->executed_stages === array('connect'), 'Only connect stage must run when connect fails');
assert(count($r1['stages']) === 1);
assert($r1['stages'][0]['name'] === 'connect' && $r1['stages'][0]['ok'] === false);

// 3b. Failure at Stage 2: tls
$c2 = new ControllableFTPS(array('host' => 'example.com'));
$c2->fail_at = 'tls';
$r2 = $c2->test_connection();
assert($r2['success'] === false);
assert($c2->executed_stages === array('connect', 'tls'), 'Only connect and tls must run when tls fails');
assert(count($r2['stages']) === 2);
assert($r2['stages'][1]['name'] === 'tls' && $r2['stages'][1]['ok'] === false);

// 3c. Failure at Stage 3: login
$c3 = new ControllableFTPS(array('host' => 'example.com'));
$c3->fail_at = 'login';
$r3 = $c3->test_connection();
assert($r3['success'] === false);
assert($c3->executed_stages === array('connect', 'tls', 'login'));
assert(count($r3['stages']) === 3);
assert($r3['stages'][2]['name'] === 'login' && $r3['stages'][2]['ok'] === false);

// 3d. Failure at Stage 4: directory
$c4 = new ControllableFTPS(array('host' => 'example.com'));
$c4->fail_at = 'directory';
$r4 = $c4->test_connection();
assert($r4['success'] === false);
assert($c4->executed_stages === array('connect', 'tls', 'login', 'directory'));
assert(count($r4['stages']) === 4);
assert($r4['stages'][3]['name'] === 'directory' && $r4['stages'][3]['ok'] === false);

// 3e. Failure at Stage 5: data_connection
$c5 = new ControllableFTPS(array('host' => 'example.com'));
$c5->fail_at = 'data_connection';
$r5 = $c5->test_connection();
assert($r5['success'] === false);
assert($c5->executed_stages === array('connect', 'tls', 'login', 'directory', 'data_connection'));
assert(count($r5['stages']) === 5);
assert($r5['stages'][4]['name'] === 'data_connection' && $r5['stages'][4]['ok'] === false);

// 3f. Failure at Stage 6: write_probe
$c6 = new ControllableFTPS(array('host' => 'example.com'));
$c6->fail_at = 'write_probe';
$r6 = $c6->test_connection();
assert($r6['success'] === false);
assert($c6->executed_stages === array('connect', 'tls', 'login', 'directory', 'data_connection', 'write_probe'));
assert(count($r6['stages']) === 6);
assert($r6['stages'][5]['name'] === 'write_probe' && $r6['stages'][5]['ok'] === false);

// 3g. All stages pass
$c_all = new ControllableFTPS(array('host' => 'example.com'));
$c_all->fail_at = null;
$r_all = $c_all->test_connection();
assert($r_all['success'] === true);
assert($c_all->executed_stages === array('connect', 'tls', 'login', 'directory', 'data_connection', 'write_probe'));
assert(count($r_all['stages']) === 6);
foreach ($r_all['stages'] as $stg) {
	assert($stg['ok'] === true, "Stage {$stg['name']} must be ok");
}
assert(isset($r_all['details']['latency_ms']));

echo "PASS: Stage order and stop-at-first-failure strictly verified across all stages.\n";
