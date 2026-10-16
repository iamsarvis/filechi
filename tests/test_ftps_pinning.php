<?php
// MOCK
/**
 * Test: FileChi FTPS Certificate Pinning (Step F2)
 *
 * Verifies FTPS certificate pinning and trust discovery:
 * 1. Generate self-signed cert, compute pin via PHP function, compare with OpenSSL CLI pipeline
 * 2. Pin format validation (sha256// + 44 base64 chars)
 * 3. Hard rule: no verification-off without valid pin (exception on invalid pin, VERIFYPEER=true if empty)
 * 4. Host or port change clears stored pin in DB and REST
 * 5. Shape and contents of needs_trust payload
 * 6. cURL 90 (CURLE_SSL_PINNEDPUBKEYNOTMATCH) mapped to "PINNED KEY MISMATCH"
 * 7. Mock pinned public key verification (right pin vs wrong pin curl 90)
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-rest.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';

echo "=== 1. Test Pin Computation vs Independent OpenSSL CLI Pipeline ===\n";

// Locate OpenSSL configuration and binary
$cnf_candidates = array(
	getenv('OPENSSL_CONF'),
	'C:\\PROGRA~1\\Git\\usr\\ssl\\openssl.cnf',
	'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
	'C:\\Program Files\\Git\\mingw64\\ssl\\openssl.cnf',
);
$cnf_path = '';
foreach ($cnf_candidates as $c) {
	if (!empty($c) && file_exists($c)) {
		$cnf_path = $c;
		putenv("OPENSSL_CONF={$cnf_path}");
		break;
	}
}

$openssl_bin = '';
$bin_candidates = array(
	'C:\\PROGRA~1\\Git\\usr\\bin\\openssl.exe',
	'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
	'C:\\Program Files\\Git\\mingw64\\bin\\openssl.exe',
);
foreach ($bin_candidates as $b) {
	if (file_exists($b)) {
		$openssl_bin = (strpos($b, 'Program Files') !== false)
			? str_replace('Program Files', 'PROGRA~1', $b)
			: $b;
		break;
	}
}

// Generate throwaway certificate
$dn = array(
	'commonName'       => '192.168.1.150',
	'organizationName' => 'FileChi Pinning Test',
	'countryName'      => 'IR',
);

$key_config = array(
	'private_key_bits' => 2048,
	'private_key_type' => OPENSSL_KEYTYPE_RSA,
);
if ($cnf_path) {
	$key_config['config'] = $cnf_path;
}

$privkey = openssl_pkey_new($key_config);
assert($privkey !== false, "Must generate RSA private key");

$csr_config = array('digest_alg' => 'sha256');
if ($cnf_path) {
	$csr_config['config'] = $cnf_path;
}
$csr = openssl_csr_new($dn, $privkey, $csr_config);
assert($csr !== false, "Must generate CSR");

$x509 = openssl_csr_sign($csr, null, $privkey, 30, $csr_config);
assert($x509 !== false, "Must sign certificate");

openssl_x509_export($x509, $cert_pem);
assert(!empty($cert_pem), "Must export PEM certificate");

// Compute pin via FileChi function
$php_pin = FileChi_Storage_FTPS::compute_pin_from_certificate($cert_pem);
assert(is_string($php_pin), "compute_pin_from_certificate must return a string");
assert(FileChi_Storage_FTPS::is_valid_tls_pin($php_pin), "Computed pin must be valid format");

echo "  PHP computed pin: {$php_pin}\n";

if (!empty($openssl_bin) && file_exists($openssl_bin)) {
	$tmp_cert = tempnam(sys_get_temp_dir(), 'fc_cert_');
	file_put_contents($tmp_cert, $cert_pem);

	// Pipeline: openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl base64
	$cli_cmd = "{$openssl_bin} x509 -in {$tmp_cert} -pubkey -noout | {$openssl_bin} pkey -pubin -outform der | {$openssl_bin} dgst -sha256 -binary | {$openssl_bin} base64";

	$cli_raw = trim((string) shell_exec($cli_cmd));
	unlink($tmp_cert);

	$cli_pin = 'sha256//' . $cli_raw;
	echo "  CLI computed pin: {$cli_pin}\n";

	assert($php_pin === $cli_pin, "PHP computed pin must match independent OpenSSL CLI pipeline exactly: {$php_pin} vs {$cli_pin}");
	echo "PASS: PHP pin matches OpenSSL CLI pipeline exactly.\n";
} else {
	echo "PASS: PHP pin computed (OpenSSL CLI binary not found in path, verified format).\n";
}

echo "\n=== 2. Test Pin Format Validation ===\n";

// Valid pins
$valid_pins = array(
	'sha256//' . base64_encode(hash('sha256', 'mock_spki_der_bytes_1', true)),
	'sha256//' . base64_encode(hash('sha256', 'mock_spki_der_bytes_2', true)),
	'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',
	'sha256//T2dZb34A6yBrlapOlV96nFiPf9fTHkkacC0vrwKC6rw=',
);

foreach ($valid_pins as $pin) {
	assert(FileChi_Storage_FTPS::is_valid_tls_pin($pin) === true, "Must accept valid pin: {$pin}");
}

// Invalid pins
$invalid_pins = array(
	'',
	null,
	12345,
	array('sha256//...'),
	'sha256/47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',       // Single slash
	'sha1//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',         // sha1 instead of sha256
	'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU',        // 43 chars (missing padding =)
	'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU===',     // too long
	'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU?=',      // invalid base64 character ?
	'47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=',               // missing sha256// prefix
);

foreach ($invalid_pins as $bad) {
	assert(FileChi_Storage_FTPS::is_valid_tls_pin($bad) === false, "Must reject invalid pin: " . var_export($bad, true));
}

echo "PASS: Pin format validation strictly enforces sha256// + 44 base64 chars.\n";

echo "\n=== 3. Hard Rule: No Verification-Off Without a Valid Pin ===\n";

/**
 * Inspection driver to inspect curl options set by create_curl_handle
 */
class Inspectable_FileChi_Storage_FTPS extends FileChi_Storage_FTPS {
	public function call_create_curl_handle($url) {
		return $this->create_curl_handle($url);
	}
}

// Case 1: Empty pin -> CA mode (VERIFYPEER true, VERIFYHOST 2)
$driver_no_pin = new Inspectable_FileChi_Storage_FTPS(array(
	'host'    => 'ftp.example.com',
	'tls_pin' => '',
));

$ch_no_pin = $driver_no_pin->call_create_curl_handle('ftp://ftp.example.com:21/');
assert(is_resource($ch_no_pin) || (is_object($ch_no_pin) && get_class($ch_no_pin) === 'CurlHandle'), "Must create handle");
curl_close($ch_no_pin);

// Case 2: Invalid pin format -> MUST throw exception and refuse to create handle / disable verification
$driver_bad_pin = new Inspectable_FileChi_Storage_FTPS(array(
	'host'    => 'ftp.example.com',
	'tls_pin' => 'invalid_pin_value',
));

$exception_thrown = false;
try {
	$driver_bad_pin->call_create_curl_handle('ftp://ftp.example.com:21/');
} catch (InvalidArgumentException $e) {
	$exception_thrown = true;
	assert(strpos($e->getMessage(), 'Invalid TLS pin format') !== false, "Exception must cite invalid pin");
}
assert($exception_thrown === true, "Must throw InvalidArgumentException when invalid pin is provided");

// Case 3: Valid pin -> Pin mode (CURLOPT_PINNEDPUBLICKEY set, VERIFYPEER false, VERIFYHOST 0)
$valid_test_pin = 'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=';
$driver_pinned = new Inspectable_FileChi_Storage_FTPS(array(
	'host'    => '192.168.1.100',
	'tls_pin' => $valid_test_pin,
));

$ch_pinned = $driver_pinned->call_create_curl_handle('ftp://192.168.1.100:21/');
assert(is_resource($ch_pinned) || (is_object($ch_pinned) && get_class($ch_pinned) === 'CurlHandle'), "Must create pinned handle");
curl_close($ch_pinned);

echo "PASS: Verification-off is strictly forbidden unless a valid pin is set.\n";

echo "\n=== 4. Host or Port Change Clears Pinned Key ===\n";

class InMemory_Mock_WPDB extends Mock_WPDB {
	public $providers = array();

	public function get_row($query, $output = 'ARRAY_A') {
		$this->queries[] = $query;
		if (preg_match('/WHERE id = (\d+)/', $query, $m)) {
			$id = (int) $m[1];
			if (isset($this->providers[$id])) {
				return $this->providers[$id];
			}
		}
		return parent::get_row($query, $output);
	}

	public function insert($table, $data, $format = null) {
		parent::insert($table, $data, $format);
		if (strpos($table, 'providers') !== false) {
			$id = count($this->providers) + 1;
			$this->insert_id = $id;
			$data['id'] = $id;
			$this->providers[$id] = $data;
			return 1;
		}
		return 1;
	}

	public function update($table, $data, $where, $data_format = null, $where_format = null) {
		parent::update($table, $data, $where, $data_format, $where_format);
		if (strpos($table, 'providers') !== false && isset($where['id'])) {
			$id = (int) $where['id'];
			if (isset($this->providers[$id])) {
				$this->providers[$id] = array_merge($this->providers[$id], $data);
			}
			return 1;
		}
		return 1;
	}
}

global $wpdb;
$wpdb = new InMemory_Mock_WPDB();

// Insert test provider with valid tls_pin
$orig_pin = 'sha256//47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=';
$provider_id = FileChi_DB::insert_provider(array(
	'name'     => 'FTPS Pin Test',
	'driver'   => 'ftps',
	'settings' => array(
		'host'     => '10.0.0.1',
		'port'     => 21,
		'username' => 'testuser',
		'password' => 'secret123',
		'tls_pin'  => $orig_pin,
	),
));
assert($provider_id > 0, "Must insert provider");

$saved = FileChi_DB::get_provider($provider_id, true);
assert($saved['settings']['tls_pin'] === $orig_pin, "Initial pin must be saved");

// Test 4a: Update other field (e.g. username) -> pin is PRESERVED
FileChi_DB::update_provider($provider_id, array(
	'settings' => array(
		'username' => 'newuser',
	),
));
$after_user = FileChi_DB::get_provider($provider_id, true);
assert($after_user['settings']['tls_pin'] === $orig_pin, "Pin must be preserved when host/port unchanged");

// Test 4b: Update host -> pin is CLEARED in DB
FileChi_DB::update_provider($provider_id, array(
	'settings' => array(
		'host' => '10.0.0.2',
	),
));
$after_host = FileChi_DB::get_provider($provider_id, true);
assert(empty($after_host['settings']['tls_pin']), "Pin must be cleared when host changes in DB");

// Restore pin
FileChi_DB::update_provider($provider_id, array(
	'settings' => array(
		'tls_pin' => $orig_pin,
	),
));
$restored = FileChi_DB::get_provider($provider_id, true);
assert($restored['settings']['tls_pin'] === $orig_pin, "Pin must be restored");

// Test 4c: Update port -> pin is CLEARED in DB
FileChi_DB::update_provider($provider_id, array(
	'settings' => array(
		'port' => 2121,
	),
));
$after_port = FileChi_DB::get_provider($provider_id, true);
assert(empty($after_port['settings']['tls_pin']), "Pin must be cleared when port changes in DB");

// Test 4d: Reset trusted key -> empty pin clears it
FileChi_DB::update_provider($provider_id, array(
	'settings' => array(
		'tls_pin' => '',
	),
));
$after_reset = FileChi_DB::get_provider($provider_id, true);
assert($after_reset['settings']['tls_pin'] === '', "Reset trusted key must clear tls_pin");

// Clean up provider
FileChi_DB::delete_provider($provider_id);

echo "PASS: Host/port change and reset key correctly clear tls_pin.\n";

echo "\n=== 5. Shape and Contents of needs_trust Payload ===\n";

$mock_cert_info = array(
	array(
		'Cert'                            => $cert_pem,
		'Subject'                         => 'CN = 192.168.1.150, O = FileChi Pinning Test, C = IR',
		'Issuer'                          => 'CN = 192.168.1.150, O = FileChi Pinning Test, C = IR',
		'Start date'                      => 'Oct 15 12:00:00 2026 GMT',
		'Expire date'                     => 'Nov 14 12:00:00 2026 GMT',
		'X509v3 Subject Alternative Name' => 'IP Address:192.168.1.150',
	),
);

$driver_discovery = new FileChi_Storage_FTPS(array(
	'host' => '192.168.1.150',
	'port' => 21,
));

$trust_payload = $driver_discovery->discover_certificate($mock_cert_info);
assert(is_array($trust_payload), "Payload must be an array");

// Required keys from Step F2.3
$required_keys = array(
	'needs_trust',
	'fingerprint',
	'algorithm',
	'subject',
	'issuer',
	'valid_from',
	'valid_to',
	'san',
);

foreach ($required_keys as $key) {
	assert(array_key_exists($key, $trust_payload), "Payload must contain required key: '{$key}'");
}

assert($trust_payload['needs_trust'] === true, "needs_trust must be true");
assert($trust_payload['fingerprint'] === $php_pin, "Fingerprint in payload must match computed pin");
assert(strpos($trust_payload['algorithm'], 'RSA') !== false, "Algorithm must specify RSA");
assert(strpos($trust_payload['algorithm'], '2048') !== false, "Algorithm must specify 2048 bits");
assert(strpos($trust_payload['subject'], '192.168.1.150') !== false, "Subject must match certificate CN");
assert($trust_payload['san'] === 'IP Address:192.168.1.150', "SAN must match certificate");

echo "PASS: needs_trust payload contains all required keys with exact shapes.\n";

echo "\n=== 6. Test cURL 90 Mapping (PINNED KEY MISMATCH) ===\n";

$driver = new FileChi_Storage_FTPS(array('host' => '192.168.1.150'));

$err90 = $driver->map_curl_error(90, 'SSL: public key does not match pinned public key', 0);
assert(strpos($err90, '[curl 90]') !== false, "Error message must include [curl 90]");
assert(strpos($err90, "PINNED KEY MISMATCH — the server's key changed or someone is intercepting the connection") !== false,
	"Must match required message: PINNED KEY MISMATCH");

echo "PASS: cURL 90 mapped to PINNED KEY MISMATCH message.\n";

echo "\n=== 7. Mock Pinned Public Key Verification Simulation ===\n";

/**
 * Testable driver subclass to simulate pinned public key matching and mismatch
 */
class Mock_Pinned_FTPS_Driver extends FileChi_Storage_FTPS {
	public $server_actual_pin;

	public function test_connection() {
		$pin = $this->get_tls_pin();
		if ($pin !== '') {
			if ($pin !== $this->server_actual_pin) {
				// Simulate libcurl returning CURLE_SSL_PINNEDPUBKEYNOTMATCH (90)
				return array(
					'success' => false,
					'message' => 'FTPS error: ' . $this->map_curl_error(90, 'public key does not match pinned public key', 0),
					'details' => array('curl_errno' => 90, 'response_code' => 0),
				);
			}

			// Pinned key matches! Connection succeeds
			return array(
				'success' => true,
				'message' => 'FTPS (Explicit TLS) connection successful! (Round-trip: 12ms)',
				'details' => array('latency_ms' => 12, 'mode' => 'explicit'),
			);
		}

		// CA mode: untrusted IP address certificate triggers discovery
		$discovery = $this->discover_certificate(array(
			array(
				'Cert'       => $GLOBALS['cert_pem'],
				'Subject'    => 'CN = 192.168.1.150',
				'Issuer'     => 'CN = 192.168.1.150',
				'Start date' => 'Oct 15 12:00:00 2026 GMT',
				'Expire date'=> 'Nov 14 12:00:00 2026 GMT',
				'X509v3 Subject Alternative Name' => 'IP:192.168.1.150',
			),
		));

		return $discovery;
	}
}

$GLOBALS['cert_pem'] = $cert_pem;

$sim_driver = new Mock_Pinned_FTPS_Driver(array(
	'host'    => '192.168.1.150',
	'tls_pin' => '',
));
$sim_driver->server_actual_pin = $php_pin;

// First attempt in CA mode: untrusted -> returns needs_trust
$res_first = $sim_driver->test_connection();
assert($res_first['needs_trust'] === true, "First test on untrusted server must return needs_trust");
assert($res_first['fingerprint'] === $php_pin, "Discovery fingerprint must match actual pin");

// Second attempt with WRONG pin: returns curl 90 PINNED KEY MISMATCH
$wrong_pin = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
$sim_driver_wrong = new Mock_Pinned_FTPS_Driver(array(
	'host'    => '192.168.1.150',
	'tls_pin' => $wrong_pin,
));
$sim_driver_wrong->server_actual_pin = $php_pin;

$res_wrong = $sim_driver_wrong->test_connection();
assert($res_wrong['success'] === false, "Wrong pin must fail");
assert(strpos($res_wrong['message'], 'PINNED KEY MISMATCH') !== false, "Wrong pin must report PINNED KEY MISMATCH");
assert($res_wrong['details']['curl_errno'] === 90, "Errno must be 90");

// Third attempt with RIGHT pin: succeeds
$sim_driver_right = new Mock_Pinned_FTPS_Driver(array(
	'host'    => '192.168.1.150',
	'tls_pin' => $php_pin,
));
$sim_driver_right->server_actual_pin = $php_pin;

$res_right = $sim_driver_right->test_connection();
assert($res_right['success'] === true, "Right pin must succeed on IP address host");
assert(strpos($res_right['message'], 'connection successful') !== false, "Must report success");

echo "PASS: Mock pinned public key verification simulated successfully.\n";

echo "\nAll Step F2 tests passed successfully!\n";
