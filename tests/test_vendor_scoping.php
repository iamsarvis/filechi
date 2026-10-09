<?php
// REAL
/**
 * Test: Automated Vendor Scoping Proof & Negative Control
 *
 * 1. Executes negative control: proves that the scoping detector catches unscoped
 *    references in both plain code and double-backslash string forms, while passing
 *    scoped references.
 * 2. Scans includes/vendor/ to verify zero unscoped phpseclib3 or ParagonIE classes leak.
 * 3. Verifies that scoped classes load properly and global/unprefixed classes do not exist.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/vendor/autoload.php';

/**
 * Core detector function that scans a directory for unscoped vendor references.
 *
 * @param string $dir Target directory to scan.
 * @return array List of violations.
 */
function filechi_detect_scoping_violations($dir) {
	$violations = array();
	$iterator   = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
	);

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

		$content = file_get_contents($item->getPathname());

		// 1. Unscoped namespace declarations
		if (preg_match('/namespace\s+(?!FileChi\\\\Vendor\\\\)(phpseclib3|ParagonIE\\\\ConstantTime)/i', $content, $m)) {
			$violations[] = array(
				'file'  => $pathname,
				'match' => $m[0],
				'type'  => 'namespace',
			);
		}

		// 2. Unscoped namespace usages (matches plain e.g. phpseclib3\ and double-backslash 'phpseclib3\\')
		$pattern = '/(?<!FileChi\\\\Vendor\\\\)(?<!FileChi\\\\\\\\Vendor\\\\\\\\)(phpseclib3(?:\\\\{1,2})[a-zA-Z0-9_\\\\]+|ParagonIE(?:\\\\{1,2})ConstantTime(?:\\\\{1,2})[a-zA-Z0-9_\\\\]+)/';
		if (preg_match_all($pattern, $content, $matches)) {
			foreach ($matches[0] as $match) {
				$violations[] = array(
					'file'  => $pathname,
					'match' => $match,
					'type'  => 'reference',
				);
			}
		}
	}

	return $violations;
}

echo "=== 1. Negative Control Test ===\n";

// Create isolated temporary fixture directory
$temp_dir = sys_get_temp_dir() . '/filechi_scoping_control_' . uniqid();
mkdir($temp_dir, 0777, true);

// (a) Scoped fixture (must produce 0 violations)
$scoped_content = <<<'PHP'
<?php
namespace FileChi\Vendor\phpseclib3\Crypt;
use FileChi\Vendor\phpseclib3\Crypt\RSA;
use FileChi\Vendor\ParagonIE\ConstantTime\Base64;
$class_rsa = 'FileChi\\Vendor\\phpseclib3\\Crypt\\RSA';
$class_b64 = 'FileChi\\Vendor\\ParagonIE\\ConstantTime\\Base64';
PHP;
file_put_contents($temp_dir . '/scoped.php', $scoped_content);

// (b) Unscoped fixture (must be caught by detector)
$unscoped_content = <<<'PHP'
<?php
namespace phpseclib3\Crypt;
use phpseclib3\Crypt\RSA;
use ParagonIE\ConstantTime\Base64;
$plain_instantiation = new \phpseclib3\Crypt\RSA();
$double_backslash_rsa = 'phpseclib3\\Crypt\\RSA';
$double_backslash_b64 = 'ParagonIE\\ConstantTime\\Base64';
PHP;
file_put_contents($temp_dir . '/unscoped.php', $unscoped_content);

$control_violations = filechi_detect_scoping_violations($temp_dir);

// Cleanup temporary directory
unlink($temp_dir . '/scoped.php');
unlink($temp_dir . '/unscoped.php');
rmdir($temp_dir);

$scoped_violations   = array_filter($control_violations, function ($v) {
	return basename($v['file']) === 'scoped.php';
});
$unscoped_violations = array_filter($control_violations, function ($v) {
	return basename($v['file']) === 'unscoped.php';
});

assert(count($scoped_violations) === 0, "Negative control failure: Scoped code produced false positive violation");
assert(count($unscoped_violations) >= 4, "Negative control failure: Unscoped code was not reported by detector");

// Verify that double-backslash string forms were specifically detected
$matches_text = implode(' | ', array_column($unscoped_violations, 'match'));
assert(strpos($matches_text, 'phpseclib3\\\\Crypt\\\\RSA') !== false, "Double-backslash phpseclib3 was not detected");
assert(strpos($matches_text, 'ParagonIE\\\\ConstantTime\\\\Base64') !== false, "Double-backslash ParagonIE was not detected");

echo "[+] Negative control verified: detector detects plain and double-backslash unscoped references while ignoring scoped references: PASS\n";

echo "\n=== 2. Scan includes/vendor for Unscoped Namespaces & Usages ===\n";

$vendor_dir = FILECHI_DIR . 'includes/vendor';
assert(is_dir($vendor_dir), "includes/vendor directory must exist");

$real_violations = filechi_detect_scoping_violations($vendor_dir);

if (!empty($real_violations)) {
	echo "[-] FAILED: Found " . count($real_violations) . " scoping violation(s) in includes/vendor:\n";
	foreach (array_slice($real_violations, 0, 10) as $violation) {
		echo "    - {$violation['file']}: {$violation['match']} ({$violation['type']})\n";
	}
	exit(1);
}

echo "[+] Zero unscoped vendor references found across includes/vendor: PASS\n";

echo "\n=== 3. Verify Scoped Classes Load Correctly ===\n";

assert(class_exists('FileChi\Vendor\phpseclib3\Crypt\RSA'), "Class FileChi\\Vendor\\phpseclib3\\Crypt\\RSA must exist");
assert(class_exists('FileChi\Vendor\phpseclib3\Crypt\PublicKeyLoader'), "Class FileChi\\Vendor\\phpseclib3\\Crypt\\PublicKeyLoader must exist");
echo "[+] Scoped phpseclib3 classes exist and load: PASS\n";

assert(class_exists('FileChi\Vendor\ParagonIE\ConstantTime\Base64UrlSafe'), "Class FileChi\\Vendor\\ParagonIE\\ConstantTime\\Base64UrlSafe must exist");
echo "[+] Scoped ParagonIE\\ConstantTime classes exist and load: PASS\n";

echo "\n=== 4. Verify Unscoped Classes Do NOT Leak into Global Namespace ===\n";

assert(!class_exists('phpseclib3\Crypt\RSA', false), "Unscoped phpseclib3\\Crypt\\RSA must NOT exist");
assert(!class_exists('phpseclib3\Crypt\PublicKeyLoader', false), "Unscoped phpseclib3\\Crypt\\PublicKeyLoader must NOT exist");
assert(!class_exists('ParagonIE\ConstantTime\Base64UrlSafe', false), "Unscoped ParagonIE\\ConstantTime\\Base64UrlSafe must NOT exist");
echo "[+] No unscoped classes in global namespace: PASS\n";

echo "\nALL VENDOR SCOPING PROOF & NEGATIVE CONTROL TESTS PASSED!\n";
