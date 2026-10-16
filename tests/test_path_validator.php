<?php
// REAL
/**
 * Test: FileChi Strict Path Validator (Item 4.3)
 *
 * Verifies FileChi_Path::validate() and FileChi_Path::is_valid() logic
 * under standard and malicious input variants.
 * Label: REAL (pure logic, fakes no WordPress functions, DB, or network).
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';

echo "=== FileChi_Path Strict Validator Unit Tests ===\n\n";

$test_cases = array(
	// 1. Multiple dots and empty segments (consecutive slashes)
	array(
		'input'    => '....//....//x',
		'expected' => 'rejected',
		'label'    => 'Consecutive slashes and multiple dots (....//....//x)',
	),
	// 2. Traversal dots with current directory dot
	array(
		'input'    => '..././x',
		'expected' => 'rejected',
		'label'    => 'Dot and multi-dot segments (..././x)',
	),
	// 3. Single-encoded slash traversal
	array(
		'input'    => '..%2f..%2fx',
		'expected' => 'rejected',
		'label'    => 'URL-encoded slash traversal (..%2f..%2fx)',
	),
	// 4. Single-encoded dot traversal
	array(
		'input'    => '%2e%2e/x',
		'expected' => 'rejected',
		'label'    => 'URL-encoded dot traversal (%2e%2e/x)',
	),
	// 5. Windows backslash directory traversal
	array(
		'input'    => '..\..\x',
		'expected' => 'rejected',
		'label'    => 'Backslash directory traversal (..\..\x)',
	),
	// 6. Double-encoded variants
	array(
		'input'    => '%252e%252e%252f%252e%252e%252fx',
		'expected' => 'rejected',
		'label'    => 'Double URL-encoded traversal (%252e%252e%252f%252e%252e%252fx)',
	),
	array(
		'input'    => 'foo/%252e%252e/bar.jpg',
		'expected' => 'rejected',
		'label'    => 'Double URL-encoded traversal in subpath (foo/%252e%252e/bar.jpg)',
	),
	array(
		'input'    => '%252e%252e/x',
		'expected' => 'rejected',
		'label'    => 'Double URL-encoded dots (%252e%252e/x)',
	),
	// 7. NUL byte injection (raw and URL-encoded)
	array(
		'input'    => "foo\0bar.jpg",
		'expected' => 'rejected',
		'label'    => 'Raw NUL byte (foo\0bar.jpg)',
	),
	array(
		'input'    => 'foo%00bar.jpg',
		'expected' => 'rejected',
		'label'    => 'URL-encoded NUL byte (foo%00bar.jpg)',
	),
	// 8. Classic mid-path traversal
	array(
		'input'    => 'a/../b',
		'expected' => 'rejected',
		'label'    => 'Parent directory traversal in path (a/../b)',
	),
	// 9. Absolute Unix paths
	array(
		'input'    => '/etc/passwd',
		'expected' => 'rejected',
		'label'    => 'Absolute Unix root path (/etc/passwd)',
	),
	// 10. Windows drive letters
	array(
		'input'    => 'C:\x',
		'expected' => 'rejected',
		'label'    => 'Windows drive letter with backslash (C:\x)',
	),
	array(
		'input'    => 'C:/x',
		'expected' => 'rejected',
		'label'    => 'Windows drive letter with forward slash (C:/x)',
	),
	// 11. Legitimate Persian file names
	array(
		'input'    => '2026/10/تصویر-تست.jpg',
		'expected' => 'accepted',
		'label'    => 'Persian file name (2026/10/تصویر-تست.jpg)',
	),
	array(
		'input'    => 'فروشگاه/فایل-محصول.zip',
		'expected' => 'accepted',
		'label'    => 'Persian folder and file name (فروشگاه/فایل-محصول.zip)',
	),
	// 12. Legitimate names with spaces, @, +, parentheses, brackets
	array(
		'input'    => 'my photos @ 2026/test image (1) + final.png',
		'expected' => 'accepted',
		'label'    => 'Name with spaces, @, +, parentheses (my photos @ 2026/test image (1) + final.png)',
	),
	array(
		'input'    => 'user@domain/file [copy] (v2)+archive.pdf',
		'expected' => 'accepted',
		'label'    => 'Name with @, brackets, parentheses, + (user@domain/file [copy] (v2)+archive.pdf)',
	),
	// 13. Overly long path (> 1024 characters)
	array(
		'input'    => 'uploads/' . str_repeat('a', 1100) . '.jpg',
		'expected' => 'rejected',
		'label'    => 'Overly long path (1,100+ characters)',
	),
	// 14. Empty and boundary cases
	array(
		'input'    => '',
		'expected' => 'rejected',
		'label'    => 'Empty string path',
	),
	array(
		'input'    => '/',
		'expected' => 'rejected',
		'label'    => 'Single root slash',
	),
	array(
		'input'    => '2026/10/',
		'expected' => 'rejected',
		'label'    => 'Trailing slash directory path (2026/10/)',
	),
);

$all_passed = true;

foreach ($test_cases as $tc) {
	$input    = $tc['input'];
	$expected = $tc['expected'];
	$label    = $tc['label'];

	$actual_state = 'accepted';
	$exception_msg = '';

	try {
		FileChi_Path::validate($input);
	} catch (InvalidArgumentException $e) {
		$actual_state  = 'rejected';
		$exception_msg = $e->getMessage();
	}

	$is_valid_bool = FileChi_Path::is_valid($input);
	$expected_bool = ($expected === 'accepted');

	$matches_expected = ($actual_state === $expected) && ($is_valid_bool === $expected_bool);

	if (!$matches_expected) {
		$all_passed = false;
		echo "[-] FAIL: {$label}\n";
		echo "    Input:    " . addcslashes($input, "\0..\37\177") . "\n";
		echo "    Expected: {$expected}\n";
		echo "    Actual:   {$actual_state}" . ($exception_msg ? " ({$exception_msg})" : '') . "\n";
	} else {
		echo "[+] PASS: {$label} => State: {$actual_state}";
		if ($actual_state === 'rejected') {
			echo " (Reason: {$exception_msg})";
		}
		echo "\n";
	}

	assert($actual_state === $expected, "Path '{$label}' must be {$expected}");
	assert($is_valid_bool === $expected_bool, "is_valid() for '{$label}' must match {$expected}");
}

echo "\n" . ($all_passed ? "All FileChi_Path tests passed successfully!" : "Some tests failed!") . "\n";
