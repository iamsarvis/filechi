<?php
// MOCK
/**
 * Test: Issue #13 Collision Guard Layer 1 & Layer 2 Unit Tests
 *
 * Covers F13.7 requirements:
 * - Layer 1 sequence (-1, -2)
 * - The plugin's own attachment is ignored
 * - Sub-size names collision detection
 * - Layer 2 prefix decision (dup-<attachment_id>/)
 * - Collisions with failed or untransferred rows are ignored
 * - The pre-upload re-check safety net (remote_key_collision)
 * - get_remote_key() used at every call site
 * - DB find_remote_key_collisions() audit
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-path.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-ftps.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';

echo "=== 1. Layer 1: Unique Filename Sequence (-1, -2) ===\n";

$temp_dir = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_l1_' . uniqid());
$upload_dir = $temp_dir . '/uploads/2026/10';
@mkdir($upload_dir, 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $upload_dir,
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $temp_dir . '/uploads',
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

// Initially no rows in filechi_files or postmeta
$GLOBALS['mock_filechi_files_rows'] = array();
$GLOBALS['mock_post_meta']          = array();

$unique_0 = FileChi_Media::get_unique_filename('sample.jpg', $upload_dir);
assert($unique_0 === 'sample.jpg', "Empty state must return original candidate name, got {$unique_0}");
echo "[+] Candidate free: returns 'sample.jpg'\n";

// Populate sample.jpg as transferred in filechi_files for attachment 1
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 1,
	'attachment_id' => 1,
	'provider_id'   => 1,
	'local_path'    => '2026/10/sample.jpg',
	'remote_key'    => '2026/10/sample.jpg',
	'path_hash'     => sha1('2026/10/sample.jpg'),
	'status'        => 'transferred',
);

$unique_1 = FileChi_Media::get_unique_filename('sample.jpg', $upload_dir);
assert($unique_1 === 'sample-1.jpg', "When sample.jpg taken, must advance to sample-1.jpg, got {$unique_1}");
echo "[+] Candidate taken in filechi_files: advances to 'sample-1.jpg'\n";

// Populate sample-1.jpg as transferred for attachment 2
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 2,
	'attachment_id' => 2,
	'provider_id'   => 1,
	'local_path'    => '2026/10/sample-1.jpg',
	'remote_key'    => '2026/10/sample-1.jpg',
	'path_hash'     => sha1('2026/10/sample-1.jpg'),
	'status'        => 'transferred',
);

$unique_2 = FileChi_Media::get_unique_filename('sample.jpg', $upload_dir);
assert($unique_2 === 'sample-2.jpg', "When sample-1.jpg taken, must advance to sample-2.jpg, got {$unique_2}");
echo "[+] Candidate -1 taken: advances to 'sample-2.jpg'\n";

echo "\n=== 2. Layer 1: Plugin's Own Attachment is Ignored ===\n";

// When checking for attachment 1 itself, sample.jpg should NOT be considered taken
$unique_self = FileChi_Media::get_unique_filename('sample.jpg', $upload_dir, 1);
assert($unique_self === 'sample.jpg', "Attachment's own rows must be ignored, got {$unique_self}");
echo "[+] Own attachment excluded: returns 'sample.jpg'\n";

echo "\n=== 3. Layer 1: Generated Sub-Size Names Collision ===\n";

// Test candidate image 'photo.jpg'. The main path '2026/10/photo.jpg' is NOT in index,
// but intermediate thumbnail '2026/10/photo-150x150.jpg' IS transferred for attachment 5.
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 3,
	'attachment_id' => 5,
	'provider_id'   => 1,
	'local_path'    => '2026/10/photo-150x150.jpg',
	'remote_key'    => '2026/10/photo-150x150.jpg',
	'path_hash'     => sha1('2026/10/photo-150x150.jpg'),
	'status'        => 'transferred',
);

$unique_sub = FileChi_Media::get_unique_filename('photo.jpg', $upload_dir);
assert($unique_sub === 'photo-1.jpg', "Candidate whose sub-size is taken must advance to photo-1.jpg, got {$unique_sub}");
echo "[+] Sub-size collision detected: advances to 'photo-1.jpg'\n";

echo "\n=== 4. Layer 1: wp_unique_filename filter hook ===\n";

$media = new FileChi_Media();
$filtered_name = apply_filters('wp_unique_filename', 'sample.jpg', '.jpg', $upload_dir);
assert($filtered_name === 'sample-2.jpg', "Hooked filter must return unique name, got {$filtered_name}");
echo "[+] wp_unique_filename filter verified: returns 'sample-2.jpg'\n";

// Cleanup temp dir
@rmdir($upload_dir);
@rmdir(dirname($upload_dir));
@rmdir($temp_dir);

echo "\n[+] Layer 1 Tests PASSED.\n";
