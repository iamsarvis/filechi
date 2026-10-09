<?php
/**
 * Test Runner for FileChi Test Suite
 *
 * Runs all tests/test_*.php files in separate processes,
 * prints PASS/FAIL per test, and exits with non-zero on any failure.
 */

$test_dir = __DIR__;
$test_files = glob($test_dir . '/test_*.php');
sort($test_files);

if (empty($test_files)) {
	echo "No test files found in {$test_dir}\n";
	exit(1);
}

$php_bin = PHP_BINARY ? PHP_BINARY : 'php';
$passed = 0;
$failed = 0;
$total  = count($test_files);

echo "========================================================\n";
echo "Running FileChi Test Suite ({$total} tests)\n";
echo "========================================================\n\n";

$start_time = microtime(true);

foreach ($test_files as $file) {
	$basename = basename($file);

	// Read label header (MOCK or REAL)
	$content = file_get_contents($file);
	$label = 'UNKNOWN';
	if (preg_match('/^\s*<\?php\s*\/\/\s*(MOCK|REAL)/m', $content, $m)) {
		$label = $m[1];
	}

	$cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($file);
	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	$proc = proc_open($cmd, $descriptors, $pipes, dirname($file));

	if (!is_resource($proc)) {
		echo "[-] [{$label}] {$basename} ... ERROR (Failed to spawn process)\n";
		$failed++;
		continue;
	}

	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);

	$exit_code = proc_close($proc);

	if ($exit_code === 0) {
		echo "[+] [{$label}] {$basename} ... PASS\n";
		$passed++;
	} else {
		echo "[-] [{$label}] {$basename} ... FAIL (Exit code: {$exit_code})\n";
		if (!empty($stderr)) {
			echo "    Error Output:\n    " . str_replace("\n", "\n    ", trim($stderr)) . "\n";
		}
		if (!empty($stdout)) {
			echo "    Standard Output:\n    " . str_replace("\n", "\n    ", trim($stdout)) . "\n";
		}
		$failed++;
	}
}

$duration = round(microtime(true) - $start_time, 2);

echo "\n========================================================\n";
echo "Test Summary: {$passed}/{$total} passed, {$failed} failed ({$duration}s)\n";
echo "========================================================\n";

if ($failed > 0) {
	exit(1);
}

exit(0);
