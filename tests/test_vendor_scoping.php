<?php
// REAL
/**
 * Test: Automated Vendor Scoping Proof
 *
 * Verifies that all phpseclib3 and ParagonIE\ConstantTime classes and namespaces
 * in includes/vendor/ are strictly scoped under FileChi\Vendor\ and that no unscoped
 * classes leak into the global namespace.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/vendor/autoload.php';

echo "=== 1. Scan includes/vendor for Unscoped Namespaces & Usages ===\n";

$vendor_dir = FILECHI_DIR . 'includes/vendor';
assert(is_dir($vendor_dir), "includes/vendor directory must exist");

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($vendor_dir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$scanned_files = 0;
$violations    = array();

foreach ($iterator as $item) {
	if (!$item->isFile() || $item->getExtension() !== 'php') {
		continue;
	}

	$pathname = str_replace('\\', '/', $item->getPathname());

	// Skip Action Scheduler as it is intentionally unscoped per design
	if (strpos($pathname, '/woocommerce/action-scheduler/') !== false) {
		continue;
	}

	// Skip autoloader file itself
	if (basename($pathname) === 'autoload.php') {
		continue;
	}

	$scanned_files++;
	$content = file_get_contents($item->getPathname());

	// 1. Unscoped namespace declarations
	if (preg_match('/namespace\s+(?!FileChi\\\\Vendor\\\\)(phpseclib3|ParagonIE\\\\ConstantTime)/i', $content, $m)) {
		$violations[] = "Unscoped namespace declaration in {$pathname}: {$m[0]}";
	}

	// 2. Unscoped namespace usages (e.g. use phpseclib3\... or \phpseclib3\...)
	if (preg_match_all('/(?<!FileChi\\\\Vendor\\\\)(phpseclib3\\\\[a-zA-Z0-9_\\\\]+|ParagonIE\\\\ConstantTime\\\\[a-zA-Z0-9_\\\\]+)/', $content, $matches)) {
		foreach ($matches[0] as $match) {
			$violations[] = "Unscoped class/namespace reference in {$pathname}: {$match}";
		}
	}
}

echo "Scanned {$scanned_files} PHP files in includes/vendor.\n";
if (!empty($violations)) {
	echo "[-] FAILED: Found " . count($violations) . " scoping violation(s):\n";
	foreach (array_slice($violations, 0, 10) as $violation) {
		echo "    - {$violation}\n";
	}
	exit(1);
}

echo "[+] Zero unscoped vendor references found across all scanned files: PASS\n";

echo "\n=== 2. Verify Scoped Classes Load Correctly ===\n";

// Test loading scoped phpseclib class
assert(class_exists('FileChi\Vendor\phpseclib3\Crypt\RSA'), "Class FileChi\\Vendor\\phpseclib3\\Crypt\\RSA must exist");
assert(class_exists('FileChi\Vendor\phpseclib3\Crypt\PublicKeyLoader'), "Class FileChi\\Vendor\\phpseclib3\\Crypt\\PublicKeyLoader must exist");
echo "[+] Scoped phpseclib3 classes exist and load: PASS\n";

// Test loading scoped ParagonIE class
assert(class_exists('FileChi\Vendor\ParagonIE\ConstantTime\Base64UrlSafe'), "Class FileChi\\Vendor\\ParagonIE\\ConstantTime\\Base64UrlSafe must exist");
echo "[+] Scoped ParagonIE\\ConstantTime classes exist and load: PASS\n";

echo "\n=== 3. Verify Unscoped Classes Do NOT Leak into Global Namespace ===\n";

assert(!class_exists('phpseclib3\Crypt\RSA', false), "Unscoped phpseclib3\\Crypt\\RSA must NOT exist");
assert(!class_exists('phpseclib3\Crypt\PublicKeyLoader', false), "Unscoped phpseclib3\\Crypt\\PublicKeyLoader must NOT exist");
assert(!class_exists('ParagonIE\ConstantTime\Base64UrlSafe', false), "Unscoped ParagonIE\\ConstantTime\\Base64UrlSafe must NOT exist");
echo "[+] No unscoped classes in global namespace: PASS\n";

echo "\nALL VENDOR SCOPING PROOF TESTS PASSED!\n";
