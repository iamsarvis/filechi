<?php
// REAL
/**
 * Test: PublicKeyLoader::load() with RSA and Ed25519 keys using scoped phpseclib
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/vendor/autoload.php';

use FileChi\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use FileChi\Vendor\phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use FileChi\Vendor\phpseclib3\Crypt\EC\PrivateKey as ECPrivateKey;

echo "=== 1. Load Scoped RSA Private Key ===\n";
$rsa_path = __DIR__ . '/fixtures/test_rsa_unenc';
assert(file_exists($rsa_path), "RSA test key fixture missing at $rsa_path");
$rsa_content = file_get_contents($rsa_path);
$rsa_key = PublicKeyLoader::load($rsa_content);

echo "Loaded key class: " . get_class($rsa_key) . "\n";
assert($rsa_key instanceof RSAPrivateKey);
echo "RSA Key Length: " . $rsa_key->getLength() . " bits\n";
assert($rsa_key->getLength() === 2048);
echo "[+] RSA Key Load: PASS\n";

echo "\n=== 2. Load Scoped RSA Public Key (.pub) ===\n";
$rsa_pub_content = file_get_contents($rsa_path . '.pub');
$rsa_pub_key = PublicKeyLoader::load($rsa_pub_content);
echo "Loaded public key class: " . get_class($rsa_pub_key) . "\n";
echo "[+] RSA Public Key Load: PASS\n";

echo "\n=== 3. Load Scoped Ed25519 Private Key ===\n";
$ed_path = __DIR__ . '/fixtures/test_ed25519_unenc';
assert(file_exists($ed_path), "Ed25519 test key fixture missing at $ed_path");
$ed_content = file_get_contents($ed_path);
$ed_key = PublicKeyLoader::load($ed_content);

echo "Loaded key class: " . get_class($ed_key) . "\n";
assert($ed_key instanceof ECPrivateKey);
echo "[+] Ed25519 Key Load: PASS\n";

echo "\n=== 4. Load Scoped Ed25519 Public Key (.pub) ===\n";
$ed_pub_content = file_get_contents($ed_path . '.pub');
$ed_pub_key = PublicKeyLoader::load($ed_pub_content);
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
