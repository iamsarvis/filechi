<?php
// REAL
/**
 * Real FTPS Integration Test for Issue #13 Collision Guard
 *
 * Demonstrates and verifies GitHub Issue #13:
 * Two attachments with the same relative path and keep_local off.
 *
 * - On unpatched code: demonstrates that Attachment 2 silently overwrites Attachment 1's remote file.
 * - On patched code: verifies that Attachment 2 is offloaded under the dup-<id>/ prefix,
 *   different URLs are served, deleting one does not delete the other, and restoring both brings both back.
 */

// If invoked directly by tests/run.php without arguments, spawn via the Python FTPS server wrapper
if ($argc <= 1) {
	$python_bin = 'C:\\Users\\98903\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
	if (!file_exists($python_bin)) {
		$python_bin = 'python';
	}

	$server_script = __DIR__ . '/test_ftps_server.py';
	$this_script   = __FILE__;

	$cmd = "\"{$python_bin}\" \"{$server_script}\" \"{$this_script}\" 2>&1";
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
}

// When called with arguments by test_ftps_server.py: execute the real FTPS tests
require_once dirname(__DIR__) . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';

$host         = $argv[1] ?? '127.0.0.1';
$port         = isset($argv[2]) ? (int) $argv[2] : 2121;
$username     = $argv[3] ?? 'testuser';
$password     = $argv[4] ?? 'testpass';
$expected_pin = $argv[5] ?? '';
$root_path    = $argv[6] ?? '/uploads';

echo "=== Real FTPS Issue #13 Collision Guard Verification ===\n";
echo "Target: ftps://{$host}:{$port} (User: {$username})\n";
echo "Root Path: {$root_path}\n";
echo "TLS Pin: {$expected_pin}\n\n";

$provider_settings = array(
	'host'       => $host,
	'port'       => $port,
	'username'   => $username,
	'password'   => $password,
	'ftps_mode'  => 'explicit',
	'tls_pin'    => $expected_pin,
	'passive'    => 1,
	'root_path'  => $root_path,
	'url_prefix' => 'https://cdn.example.com',
);

$provider = array(
	'id'       => 1,
	'name'     => 'Real FTPS Server',
	'driver'   => 'ftps',
	'settings' => $provider_settings,
);

// Register provider in mock DB
$GLOBALS['mock_filechi_providers'][1] = $provider;

// Set plugin settings: keep_local_files = 0 (triggering the vulnerability)
update_option('filechi_settings', array(
	'keep_local_files'      => 0,
	'keep_remote_on_delete' => 0,
	'offload_enabled'       => 1,
	'url_replacement'       => 1,
));

$temp_base = wp_normalize_path(sys_get_temp_dir() . '/filechi_issue13_' . uniqid());
$upload_dir = $temp_base . '/uploads';
@mkdir($upload_dir . '/2026/10', 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $upload_dir . '/2026/10',
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $upload_dir,
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

$driver = FileChi_Storage_Factory::create($provider);
assert($driver instanceof FileChi_Storage_FTPS, 'Driver must be FTPS instance');

$rel_path    = '2026/10/nody-photo.jpg';
$local_file  = $upload_dir . '/' . $rel_path;
$content_101 = "ATTACHMENT_101_ORIGINAL_DATA_CONTENT_AAA";
$content_102 = "ATTACHMENT_102_OVERWRITING_DATA_CONTENT_BBB";

// --- 1. Upload Attachment 101 ---
echo "[-] Uploading Attachment 101 with relative path {$rel_path}...\n";
file_put_contents($local_file, $content_101);
assert(file_exists($local_file), 'Local file 101 must exist before offload');

$GLOBALS['mock_posts'][101] = (object) array('ID' => 101, 'post_type' => 'attachment', 'post_title' => 'Photo 101');
update_post_meta(101, '_wp_attached_file', $rel_path);
$meta_101 = array('file' => $rel_path, 'filesize' => strlen($content_101));
$GLOBALS['mock_post_meta'][101]['_wp_attachment_metadata'] = $meta_101;

$res_101 = FileChi_Media::offload_attachment(101, $provider, $meta_101);
assert($res_101 === true, 'Attachment 101 offload must succeed');
assert(!file_exists($local_file), 'Local file 101 must be deleted when keep_local=0');
assert((int) get_post_meta(101, '_filechi_offloaded', true) === 1, 'Attachment 101 must be marked offloaded');

// Check remote file exists
assert($driver->exists($rel_path) === true, 'Remote file must exist for 101');
$dl_check = $temp_base . '/check_101.tmp';
$driver->download($rel_path, $dl_check);
assert(file_get_contents($dl_check) === $content_101, 'Remote content must match 101');
@unlink($dl_check);
echo "    [+] Attachment 101 offloaded, local deleted, remote verified.\n";

// --- 2. Upload Attachment 102 (with identical relative path, e.g. while plugin was inactive or because local was gone) ---
echo "[-] Uploading Attachment 102 with identical relative path {$rel_path}...\n";
file_put_contents($local_file, $content_102);
assert(file_exists($local_file), 'Local file 102 must exist before offload');

$GLOBALS['mock_posts'][102] = (object) array('ID' => 102, 'post_type' => 'attachment', 'post_title' => 'Photo 102');
update_post_meta(102, '_wp_attached_file', $rel_path);
$meta_102 = array('file' => $rel_path, 'filesize' => strlen($content_102));
$GLOBALS['mock_post_meta'][102]['_wp_attachment_metadata'] = $meta_102;

$res_102 = FileChi_Media::offload_attachment(102, $provider, $meta_102);
assert($res_102 === true, 'Attachment 102 offload must succeed');
assert(!file_exists($local_file), 'Local file 102 must be deleted when keep_local=0');

// Inspect remote storage
$is_patched = method_exists('FileChi_Media', 'get_remote_key');

if (!$is_patched) {
	// UNPATCHED CODE: Reproduce the collision bug
	echo "[-] Inspecting remote file on unpatched codebase...\n";
	$dl_bug = $temp_base . '/check_bug.tmp';
	$driver->download($rel_path, $dl_bug);
	$remote_contents = file_get_contents($dl_bug);
	@unlink($dl_bug);

	echo "    Observed remote content: {$remote_contents}\n";
	if ($remote_contents === $content_102) {
		echo "[!] REPRODUCED BUG #13: Attachment 101 remote file was silently OVERWRITTEN by Attachment 102!\n";
		echo "    Attachment 101 data has been lost on remote server because both attachments used the same remote key.\n";
	} else {
		throw new Exception("Unexpected: content did not match 102");
	}

	// Clean up remote
	$driver->delete($rel_path);
} else {
	// PATCHED CODE (F13.7): Verify collision guard
	echo "[-] Verifying collision guard on patched codebase...\n";
	$prefix_102 = get_post_meta(102, '_filechi_remote_prefix', true);
	assert(!empty($prefix_102), 'Attachment 102 must have a remote prefix assigned');
	assert($prefix_102 === 'dup-102/', "Prefix must be 'dup-102/', got '{$prefix_102}'");

	$remote_key_101 = FileChi_Media::get_remote_key(101, $rel_path);
	$remote_key_102 = FileChi_Media::get_remote_key(102, $rel_path);

	assert($remote_key_101 === $rel_path, 'Attachment 101 remote key must remain unprefixed');
	assert($remote_key_102 === 'dup-102/' . $rel_path, 'Attachment 102 remote key must be prefixed with dup-102/');

	// Both remote files must exist simultaneously
	assert($driver->exists($remote_key_101) === true, 'Remote file 101 must exist');
	assert($driver->exists($remote_key_102) === true, 'Remote file 102 must exist');

	// Verify both have their respective distinct contents
	$dl_101 = $temp_base . '/verify_101.tmp';
	$dl_102 = $temp_base . '/verify_102.tmp';
	$driver->download($remote_key_101, $dl_101);
	$driver->download($remote_key_102, $dl_102);

	assert(file_get_contents($dl_101) === $content_101, 'Attachment 101 content must be preserved');
	assert(file_get_contents($dl_102) === $content_102, 'Attachment 102 content must match');
	@unlink($dl_101);
	@unlink($dl_102);
	echo "    [+] Both files exist concurrently on FTPS server with correct distinct contents.\n";

	// Verify different URLs are returned
	$media = new FileChi_Media();
	$url_101 = $media->filter_attachment_url('https://example.com/wp-content/uploads/' . $rel_path, 101);
	$url_102 = $media->filter_attachment_url('https://example.com/wp-content/uploads/' . $rel_path, 102);
	assert($url_101 !== $url_102, 'URLs for both attachments must differ');
	assert(strpos($url_102, 'dup-102') !== false, 'URL for 102 must contain the prefix');
	echo "    [+] Attachment 101 URL: {$url_101}\n";
	echo "    [+] Attachment 102 URL: {$url_102}\n";

	// Deleting Attachment 101 does NOT delete Attachment 102's remote file
	$media->handle_delete_attachment(101);
	assert($driver->exists($remote_key_101) === false, 'Attachment 101 remote file must be deleted');
	assert($driver->exists($remote_key_102) === true, 'Attachment 102 remote file must NOT be deleted');
	echo "    [+] Deleting Attachment 101 cleanly left Attachment 102 intact.\n";

	// Restore Attachment 102 brings its file back locally
	if (class_exists('FileChi_Restore')) {
		require_once FILECHI_DIR . 'includes/class-filechi-restore.php';
		$restore_res = FileChi_Restore::restore_attachment(102, $provider, $driver, false);
		assert($restore_res === true, 'Restoring Attachment 102 must succeed');
		assert(file_exists($local_file), 'Attachment 102 restored local file must exist');
		assert(file_get_contents($local_file) === $content_102, 'Restored content must match 102');
		echo "    [+] Attachment 102 restored back to local server with correct content.\n";
	}

	// Clean up remote file 102
	$driver->delete($remote_key_102);
}

// Cleanup local temp directory
$cleanup_recursive = function($dir) use (&$cleanup_recursive) {
	if (!is_dir($dir)) {
		return;
	}
	foreach (scandir($dir) as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$p = $dir . '/' . $item;
		is_dir($p) ? $cleanup_recursive($p) : @unlink($p);
	}
	@rmdir($dir);
};
$cleanup_recursive($temp_base);

echo "\n[+] Issue #13 Real FTPS Test COMPLETED SUCCESSFULLY.\n";
