<?php
// REAL
/**
 * Test: PublicKeyLoader::load() with RSA and Ed25519 keys using scoped phpseclib
 *
 * Generates throwaway RSA and Ed25519 keys dynamically at test run time into a
 * temporary directory, ensuring zero private keys are stored in version control.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/vendor/autoload.php';

use FileChi\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use FileChi\Vendor\phpseclib3\Crypt\RSA;
use FileChi\Vendor\phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use FileChi\Vendor\phpseclib3\Crypt\EC;
use FileChi\Vendor\phpseclib3\Crypt\EC\PrivateKey as ECPrivateKey;

// Create temporary directory for runtime key generation
$temp_dir = sys_get_temp_dir() . '/filechi_test_keys_' . uniqid('', true);
if (!mkdir($temp_dir, 0700, true) && !is_dir($temp_dir)) {
	throw new RuntimeException("Failed to create temporary directory for test keys at $temp_dir");
}

$rsa_path    = $temp_dir . '/test_rsa';
$rsa_pub_path = $rsa_path . '.pub';
$ed_path     = $temp_dir . '/test_ed25519';
$ed_pub_path  = $ed_path . '.pub';

try {
	echo "=== 0. Generate Runtime Throwaway Keys into Temporary Folder ===\n";
	// Generate 2048-bit RSA key pair
	$rsa_generated = RSA::createKey(2048);
	file_put_contents($rsa_path, $rsa_generated->toString('PKCS8'));
	file_put_contents($rsa_pub_path, $rsa_generated->getPublicKey()->toString('OpenSSH'));

	// Generate Ed25519 key pair
	$ed_generated = EC::createKey('Ed25519');
	file_put_contents($ed_path, $ed_generated->toString('PKCS8'));
	file_put_contents($ed_pub_path, $ed_generated->getPublicKey()->toString('OpenSSH'));

	echo "[+] Generated RSA and Ed25519 keys in $temp_dir: PASS\n";

	echo "\n=== 1. Load Scoped RSA Private Key ===\n";
	assert(file_exists($rsa_path), "RSA test key missing at $rsa_path");
	$rsa_content = file_get_contents($rsa_path);
	$rsa_key     = PublicKeyLoader::load($rsa_content);

	echo "Loaded key class: " . get_class($rsa_key) . "\n";
	assert($rsa_key instanceof RSAPrivateKey);
	echo "RSA Key Length: " . $rsa_key->getLength() . " bits\n";
	assert($rsa_key->getLength() === 2048);
	echo "[+] RSA Key Load: PASS\n";

	echo "\n=== 2. Load Scoped RSA Public Key (.pub) ===\n";
	$rsa_pub_content = file_get_contents($rsa_pub_path);
	$rsa_pub_key     = PublicKeyLoader::load($rsa_pub_content);
	echo "Loaded public key class: " . get_class($rsa_pub_key) . "\n";
	echo "[+] RSA Public Key Load: PASS\n";

	echo "\n=== 3. Load Scoped Ed25519 Private Key ===\n";
	assert(file_exists($ed_path), "Ed25519 test key missing at $ed_path");
	$ed_content = file_get_contents($ed_path);
	$ed_key     = PublicKeyLoader::load($ed_content);

	echo "Loaded key class: " . get_class($ed_key) . "\n";
	assert($ed_key instanceof ECPrivateKey);
	echo "[+] Ed25519 Key Load: PASS\n";

	echo "\n=== 4. Load Scoped Ed25519 Public Key (.pub) ===\n";
	$ed_pub_content = file_get_contents($ed_pub_path);
	$ed_pub_key     = PublicKeyLoader::load($ed_pub_content);
	echo "Loaded public key class: " . get_class($ed_pub_key) . "\n";
	echo "[+] Ed25519 Public Key Load: PASS\n";

	echo "\n=== 5. Verify SFTP Driver Class with Scoped phpseclib ===\n";
	require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
	require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-sftp.php';

	$sftp = new FileChi_Storage_SFTP(array(
		'host'        => 'localhost',
		'username'    => 'testuser',
		'auth_type'   => 'key',
		'private_key' => $rsa_content,
	));
	echo "[+] SFTP Storage Driver initialized with RSA key: PASS\n";

	echo "\nALL SCOPED KEY LOADING TESTS PASSED!\n";
} finally {
	// Clean up temporary key files and directory
	if (file_exists($rsa_path)) {
		unlink($rsa_path);
	}
	if (file_exists($rsa_pub_path)) {
		unlink($rsa_pub_path);
	}
	if (file_exists($ed_path)) {
		unlink($ed_path);
	}
	if (file_exists($ed_pub_path)) {
		unlink($ed_pub_path);
	}
	if (is_dir($temp_dir)) {
		rmdir($temp_dir);
	}
}
