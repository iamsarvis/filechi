<?php
// REAL
/**
 * Real FTPS Test Runner (Item 4.5)
 *
 * Spawns the Python-based real FTPS server (test_ftps_server.py) with pyftpdlib,
 * runs the full 7-stage FTPS verification suite, and returns the result.
 */

$python_bin = 'C:\\Users\\98903\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
if (!file_exists($python_bin)) {
	$python_bin = 'python';
}

$script = __DIR__ . '/test_ftps_server.py';
echo "Starting Real FTPS Server Test via {$python_bin}...\n";

$cmd = "\"{$python_bin}\" \"{$script}\" 2>&1";
$descriptors = array(
	0 => array('pipe', 'r'),
	1 => array('pipe', 'w'),
);

$proc = proc_open($cmd, $descriptors, $pipes, dirname(dirname(__DIR__)));
if (!is_resource($proc)) {
	echo "Failed to start Python FTPS test runner\n";
	exit(1);
}

fclose($pipes[0]);
while (!feof($pipes[1])) {
	$line = fgets($pipes[1]);
	if ($line !== false) {
		echo $line;
	}
}
fclose($pipes[1]);

$code = proc_close($proc);
exit($code);
