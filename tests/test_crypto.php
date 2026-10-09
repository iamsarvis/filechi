<?php
// REAL
/**
 * Test: FileChi_Crypto Authenticated Encryption & SigV4 Signatures
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-s3.php';

echo "=== 1. Testing Hardened FileChi_Crypto Authenticated Encryption ===\n";

$plaintext = 'MyVerySecretPassword123!@#_with_persian_حروف_فارسی';

// Test 1a: Round Trip (Sodium default)
FileChi_Crypto::$simulate_no_sodium  = false;
FileChi_Crypto::$simulate_no_ciphers = false;

$encrypted = FileChi_Crypto::encrypt($plaintext);
echo "[+] Encrypted string length: " . strlen($encrypted) . " chars\n";
assert(!empty($encrypted), "Encryption returned empty string");
assert($encrypted !== $plaintext, "Encryption did not alter plaintext");
$raw_decoded = base64_decode($encrypted);
assert(strpos($raw_decoded, 'fc_sod2:') === 0, "Payload does not start with versioned prefix fc_sod2:");
echo "[+] Versioned prefix fc_sod2: verified\n";

$decrypted = FileChi_Crypto::decrypt($encrypted);
assert($decrypted === $plaintext, "Decrypted text did not match original plaintext");
echo "[+] Round trip successful with Sodium: matched original plaintext\n";

// Test 1b: Tamper Rejection (fail-closed, must return false)
$tampered = base64_encode(substr(base64_decode($encrypted), 0, -4) . 'TAMP');
$tampered_res = FileChi_Crypto::decrypt($tampered);
assert($tampered_res === false, "Tampered payload was not rejected with false!");
echo "[+] Tampering rejection verified (returned false)\n";

// Test 1c: Wrong Key Rejection (must return false)
$wrong_key = random_bytes(32);
$wrong_key_res = FileChi_Crypto::decrypt($encrypted, $wrong_key);
assert($wrong_key_res === false, "Decryption with wrong key was not rejected with false!");
echo "[+] Wrong key rejection verified (returned false)\n";

// Test 1d: Missing Sodium (Simulate -> Fallback to AES-256-GCM)
FileChi_Crypto::$simulate_no_sodium = true;
$encrypted_gcm = FileChi_Crypto::encrypt($plaintext);
$raw_gcm = base64_decode($encrypted_gcm);
assert(strpos($raw_gcm, 'fc_gcm2:') === 0, "Fallback payload does not start with fc_gcm2:");
echo "[+] Missing sodium simulated: fallback to OpenSSL AES-256-GCM (fc_gcm2:) generated\n";

$decrypted_gcm = FileChi_Crypto::decrypt($encrypted_gcm);
assert($decrypted_gcm === $plaintext, "Decrypted AES-256-GCM text did not match original plaintext");
echo "[+] OpenSSL AES-256-GCM fallback round trip verified\n";

// Test 1e: No Cipher Available (Simulate -> Must Refuse to Store / Fail Closed)
FileChi_Crypto::$simulate_no_ciphers = true;
$threw_exception = false;
try {
	$fail_res = FileChi_Crypto::encrypt($plaintext);
	if ($fail_res instanceof WP_Error) {
		$threw_exception = true;
	}
} catch (\RuntimeException $e) {
	$threw_exception = true;
	echo "[+] Refused to store when no cipher available: Caught RuntimeException ('" . $e->getMessage() . "')\n";
}
assert($threw_exception, "Did not refuse to store when no cipher is available!");
echo "[+] Fail-closed behavior verified: refuses to store plaintext\n";

// Reset simulation flags
FileChi_Crypto::$simulate_no_sodium  = false;
FileChi_Crypto::$simulate_no_ciphers = false;

echo "\n=== 2. Testing FileChi_Storage_S3 SigV4 generation ===\n";
$s3 = new FileChi_Storage_S3(array(
	'provider_type' => 'arvancloud',
	'endpoint'      => 'https://s3.ir-thr-at1.arvanstorage.ir',
	'region'        => 'ir-thr-at1',
	'bucket'        => 'my-filechi-bucket',
	'access_key'    => 'test_arvan_access_key',
	'secret_key'    => 'test_arvan_secret_key_1234567890',
	'path_style'    => 1,
));

$public_url = $s3->get_url('2026/10/banner.jpg');
echo "Public URL: $public_url\n";
assert(strpos($public_url, 'https://s3.ir-thr-at1.arvanstorage.ir/my-filechi-bucket/2026/10/banner.jpg') === 0, "Public URL structure incorrect");

$signed_url = $s3->get_signed_url('2026/10/downloads/ebook.pdf', 1800);
echo "Pre-signed URL: $signed_url\n";
assert(strpos($signed_url, 'X-Amz-Algorithm=AWS4-HMAC-SHA256') !== false, "SigV4 algorithm missing in signed URL");
assert(strpos($signed_url, 'X-Amz-Signature=') !== false, "SigV4 signature missing in signed URL");
assert(strpos($signed_url, 'X-Amz-Expires=1800') !== false, "SigV4 expiry missing in signed URL");
echo "[+] SigV4 Pre-signed URL verified\n";

echo "\n=== 3. Testing SFTP Driver instantiation with scoped phpseclib 3 ===\n";
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';
$sftp = new FileChi_Storage_SFTP(array(
	'host'     => 'sftp.yourserver.com',
	'username' => 'testuser',
	'password' => 'secret',
));
$sftp_url = $sftp->get_url('2026/10/image.png');
echo "SFTP URL: $sftp_url\n";
$sftp_signed = $sftp->get_signed_url('2026/10/file.zip', 900);
echo "SFTP Signed download URL: $sftp_signed\n";
assert(strpos($sftp_signed, 'filechi_token=') !== false, "Token missing in SFTP signed URL");

echo "\n=== 4. Testing FTPS Driver instantiation ===\n";
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
$ftps = new FileChi_Storage_FTPS(array(
	'host'     => 'ftp.yourserver.com',
	'username' => 'testuser',
	'password' => 'secret',
));
$ftps_signed = $ftps->get_signed_url('2026/10/file.zip', 900);
echo "FTPS Signed download URL: $ftps_signed\n";

echo "\nALL CRYPTO TESTS PASSED!\n";
