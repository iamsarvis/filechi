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

echo "\n=== 5. Layer 2: Prefix Decision (dup-<attachment_id>/) ===\n";

$temp_dir2 = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_l2_' . uniqid());
$upload_dir2 = $temp_dir2 . '/uploads/2026/10';
@mkdir($upload_dir2, 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $upload_dir2,
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $temp_dir2 . '/uploads',
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

class Mock_Collision_Storage implements FileChi_Storage_Interface {
	public $uploaded    = array();
	public $deleted     = array();
	public $signed      = array();
	public $urls        = array();
	public $provider_id = 1;

	public function upload_content($content, $remote_path, $mime_type = '') {
		return true;
	}
	public function set_provider_id($id) {
		$this->provider_id = (int) $id;
	}
	public function get_provider_id() {
		return $this->provider_id;
	}

	public function upload($local_file, $remote_path) {
		$this->uploaded[] = array('local' => $local_file, 'remote' => $remote_path);
		return true;
	}
	public function delete($remote_path) {
		$this->deleted[] = $remote_path;
		return true;
	}
	public function exists($remote_path) {
		foreach ($this->uploaded as $u) {
			if ($u['remote'] === $remote_path) {
				return true;
			}
		}
		return false;
	}
	public function get_size($remote_path) {
		return 1000;
	}
	public function get_url($remote_path) {
		$this->urls[] = $remote_path;
		return 'https://cdn.example.com/' . $remote_path;
	}
	public function get_signed_url($remote_path, $expires = 900) {
		$this->signed[] = $remote_path;
		return 'https://cdn.example.com/signed/' . $remote_path . '?exp=' . $expires;
	}
	public function download($remote_path, $local_file, $expected_size = -1) {
		file_put_contents($local_file, 'restored content');
		return true;
	}
	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		return true;
	}
	public function test_connection() {
		return array('success' => true, 'message' => 'ok');
	}
	public function get_last_error() {
		return '';
	}
}

$mock_driver = new Mock_Collision_Storage();
$GLOBALS['filechi_mock_driver_override'] = $mock_driver;
FileChi_Storage_Factory::set_instance(1, $mock_driver);

$provider = array(
	'id'         => 1,
	'name'       => 'Mock Provider',
	'driver'     => 'mock',
	'settings'   => array(),
	'is_default' => 1,
);
$GLOBALS['mock_filechi_providers'][1] = $provider;

// Set attachment 10 as transferred for banner.jpg
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 10,
	'attachment_id' => 10,
	'provider_id'   => 1,
	'local_path'    => '2026/10/banner.jpg',
	'remote_key'    => '2026/10/banner.jpg',
	'path_hash'     => sha1('2026/10/banner.jpg'),
	'status'        => 'transferred',
);

// Attachment 20 comes in with banner.jpg
$att_20_file = $upload_dir2 . '/banner.jpg';
file_put_contents($att_20_file, 'banner 20 content');
update_post_meta(20, '_wp_attached_file', '2026/10/banner.jpg');
$meta_20 = array('file' => '2026/10/banner.jpg', 'filesize' => strlen('banner 20 content'));

$res_20 = FileChi_Media::offload_attachment(20, $provider, $meta_20);
assert($res_20 === true, 'Attachment 20 offload must succeed');

$prefix_20 = get_post_meta(20, '_filechi_remote_prefix', true);
assert($prefix_20 === 'dup-20/', "Attachment 20 must have prefix 'dup-20/', got '{$prefix_20}'");

$last_upload = end($mock_driver->uploaded);
assert($last_upload['remote'] === 'dup-20/2026/10/banner.jpg', "Remote upload path must use prefix: {$last_upload['remote']}");
echo "[+] Layer 2: Prefix 'dup-20/' assigned and used for remote upload.\n";

echo "\n=== 6. Layer 2: Collisions with Failed Rows Ignored ===\n";

// Attachment 30 has a failed row for 'failed-doc.pdf'
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 30,
	'attachment_id' => 30,
	'provider_id'   => 1,
	'local_path'    => '2026/10/failed-doc.pdf',
	'remote_key'    => '2026/10/failed-doc.pdf',
	'path_hash'     => sha1('2026/10/failed-doc.pdf'),
	'status'        => 'failed',
);

// Attachment 40 comes in with 'failed-doc.pdf'
$att_40_file = $upload_dir2 . '/failed-doc.pdf';
file_put_contents($att_40_file, 'failed doc 40 content');
update_post_meta(40, '_wp_attached_file', '2026/10/failed-doc.pdf');
$meta_40 = array('file' => '2026/10/failed-doc.pdf', 'filesize' => strlen('failed doc 40 content'));

$res_40 = FileChi_Media::offload_attachment(40, $provider, $meta_40);
assert($res_40 === true, 'Attachment 40 offload must succeed');

$prefix_40 = get_post_meta(40, '_filechi_remote_prefix', true);
assert(empty($prefix_40), "Failed rows must not trigger collision prefix, got '{$prefix_40}'");

$last_upload_40 = end($mock_driver->uploaded);
assert($last_upload_40['remote'] === '2026/10/failed-doc.pdf', "Uncollided key must remain unprefixed: {$last_upload_40['remote']}");
echo "[+] Failed rows ignored: Attachment 40 remained unprefixed.\n";

echo "\n=== 7. Layer 2: Safety Net Pre-Upload Re-Check (remote_key_collision) ===\n";

// Attachment 50 tries to upload. We simulate a race condition where the exact remote_key
// is already transferred for another attachment (Attachment 99).
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 99,
	'attachment_id' => 99,
	'provider_id'   => 1,
	'local_path'    => '2026/10/race.jpg',
	'remote_key'    => 'dup-50/2026/10/race.jpg',
	'path_hash'     => sha1('2026/10/race.jpg'),
	'status'        => 'transferred',
);

update_post_meta(50, '_filechi_remote_prefix', 'dup-50/');
$att_50_file = $upload_dir2 . '/race.jpg';
file_put_contents($att_50_file, 'race file content');
update_post_meta(50, '_wp_attached_file', '2026/10/race.jpg');
$meta_50 = array('file' => '2026/10/race.jpg', 'filesize' => strlen('race file content'));

$res_50 = FileChi_Media::offload_attachment(50, $provider, $meta_50);
assert($res_50 === false, 'Safety net must block upload on collision');
assert(file_exists($att_50_file), 'Local file must be kept intact when collision blocked');
assert(get_post_meta(50, '_filechi_last_error', true) === 'remote_key_collision', 'Failure reason must be remote_key_collision');
echo "[+] Safety net verified: upload aborted with 'remote_key_collision' and local file preserved.\n";

echo "\n=== 8. Layer 2: get_remote_key() Used at All Call Sites ===\n";

$media_inst = new FileChi_Media();

// 8a. filter_attachment_url
update_post_meta(20, '_filechi_offloaded', 1);
update_post_meta(20, '_filechi_provider_id', 1);
$url_20 = $media_inst->filter_attachment_url('https://example.com/wp-content/uploads/2026/10/banner.jpg', 20);
assert(strpos($url_20, 'dup-20/2026/10/banner.jpg') !== false, "filter_attachment_url must use remote_key: {$url_20}");
echo "[+] Call site 1 (filter_attachment_url): verified.\n";

// 8b. filter_image_srcset
$sources_in = array(
	300 => array('url' => 'https://example.com/wp-content/uploads/2026/10/banner-300x200.jpg', 'descriptor' => 'w', 'value' => 300),
);
$sources_out = $media_inst->filter_image_srcset($sources_in, array(300, 200), 'https://example.com/wp-content/uploads/2026/10/banner.jpg', array(), 20);
assert(strpos($sources_out[300]['url'], 'dup-20/2026/10/banner-300x200.jpg') !== false, 'filter_image_srcset must use remote_key');
echo "[+] Call site 2 (filter_image_srcset): verified.\n";

// 8c. filter_attachment_image_src
$img_in = array('https://example.com/wp-content/uploads/2026/10/banner-300x200.jpg', 300, 200, true);
$img_out = $media_inst->filter_attachment_image_src($img_in, 20, 'medium', false);
assert(strpos($img_out[0], 'dup-20/2026/10/banner-300x200.jpg') !== false, 'filter_attachment_image_src must use remote_key');
echo "[+] Call site 3 (filter_attachment_image_src): verified.\n";

// 8d. handle_delete_attachment
$mock_driver->deleted = array();
$media_inst->handle_delete_attachment(20);
assert(in_array('dup-20/2026/10/banner.jpg', $mock_driver->deleted, true), 'handle_delete_attachment must delete prefixed remote key');
echo "[+] Call site 4 (handle_delete_attachment): verified.\n";

// 8e. WooCommerce signed URL
require_once FILECHI_DIR . 'includes/class-filechi-woocommerce.php';
$wc = new FileChi_WooCommerce();
update_post_meta(20, '_filechi_offloaded', 1);

$wc_url = $wc->filter_download_path('https://example.com/wp-content/uploads/2026/10/banner.jpg', 123, 1);
assert(strpos($wc_url, 'dup-20/2026/10/banner.jpg') !== false, "WooCommerce filter_download_path must use remote_key: {$wc_url}");
echo "[+] Call site 5 (WooCommerce filter_download_path): verified.\n";

// Cleanup temp dir 2
@unlink($att_20_file);
@unlink($att_40_file);
@unlink($att_50_file);
@rmdir($upload_dir2);
@rmdir(dirname($upload_dir2));
@rmdir($temp_dir2);

echo "\n[+] Layer 2 Tests PASSED.\n";

echo "\n=== 9. Layer 2: Audit Existing Damage (Item F13.5) ===\n";

$temp_dir3   = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_l3_' . uniqid());
$upload_dir3 = $temp_dir3 . '/uploads/2026/10';
@mkdir($upload_dir3, 0777, true);

$GLOBALS['mock_upload_dir'] = array(
	'path'    => $upload_dir3,
	'url'     => 'https://example.com/wp-content/uploads/2026/10',
	'subdir'  => '/2026/10',
	'basedir' => $temp_dir3 . '/uploads',
	'baseurl' => 'https://example.com/wp-content/uploads',
	'error'   => false,
);

// Attachment 100: Owner (lowest ID)
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 100,
	'attachment_id' => 100,
	'provider_id'   => 1,
	'local_path'    => '2026/10/shared-doc.pdf',
	'remote_key'    => '2026/10/shared-doc.pdf',
	'path_hash'     => sha1('2026/10/shared-doc.pdf'),
	'size'          => 500,
	'status'        => 'transferred',
);
update_post_meta(100, '_filechi_offloaded', 1);

// Attachment 200: Colliding, local file exists
$att_200_file = $upload_dir3 . '/shared-doc.pdf';
file_put_contents($att_200_file, 'local content 200');
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 200,
	'attachment_id' => 200,
	'provider_id'   => 1,
	'local_path'    => '2026/10/shared-doc.pdf',
	'remote_key'    => '2026/10/shared-doc.pdf',
	'path_hash'     => sha1('2026/10/shared-doc.pdf'),
	'size'          => 500,
	'status'        => 'transferred',
);
update_post_meta(200, '_filechi_offloaded', 1);

// Attachment 300: Colliding, local file MISSING
$GLOBALS['mock_filechi_files_rows'][] = array(
	'id'            => 300,
	'attachment_id' => 300,
	'provider_id'   => 1,
	'local_path'    => '2026/10/missing-local.pdf',
	'remote_key'    => '2026/10/shared-doc.pdf',
	'path_hash'     => sha1('2026/10/missing-local.pdf'),
	'size'          => 500,
	'status'        => 'transferred',
);
update_post_meta(300, '_filechi_offloaded', 1);

// Test count_remote_key_collisions()
$collision_count = FileChi_DB::count_remote_key_collisions();
assert($collision_count === 1, "Collision count must be 1, got {$collision_count}");
echo "[+] count_remote_key_collisions() returned 1.\n";

// Test get_migration_stats() includes collisions
$stats = FileChi_DB::get_migration_stats();
assert(isset($stats['collisions']), "get_migration_stats() must include 'collisions'");
assert($stats['collisions'] === 1, "Stats collisions must be 1, got {$stats['collisions']}");
echo "[+] get_migration_stats()['collisions'] verified.\n";

// Run audit
$audit_results = FileChi_DB::find_remote_key_collisions();
assert(count($audit_results) === 2, 'Audit must produce 2 records for 2 colliding attachments');

// Attachment 200 verification
$res_200 = null;
foreach ($audit_results as $r) {
	if ($r['colliding_id'] === 200) {
		$res_200 = $r;
		break;
	}
}
assert($res_200 !== null, 'Attachment 200 record must exist in audit');
assert($res_200['owner_id'] === 100, 'Lowest ID 100 must be owner');
assert($res_200['action'] === 'reoffload_queued', 'Attachment 200 with local file must have action reoffload_queued');
assert(get_post_meta(200, '_filechi_remote_prefix', true) === 'dup-200/', "Attachment 200 must have prefix 'dup-200/'");
assert(get_post_meta(200, '_filechi_offloaded', true) === '', 'Attachment 200 offloaded flag must be cleared');
echo "[+] Audit: Attachment 200 re-offload queued under prefix 'dup-200/'.\n";

// Attachment 300 verification
$res_300 = null;
foreach ($audit_results as $r) {
	if ($r['colliding_id'] === 300) {
		$res_300 = $r;
		break;
	}
}
assert($res_300 !== null, 'Attachment 300 record must exist in audit');
assert($res_300['owner_id'] === 100, 'Lowest ID 100 must be owner');
assert($res_300['action'] === 'unrecoverable', 'Attachment 300 without local file must have action unrecoverable');
echo "[+] Audit: Attachment 300 logged as unrecoverable.\n";

// Cleanup temp dir 3
@unlink($att_200_file);
@rmdir($upload_dir3);
@rmdir(dirname($upload_dir3));
@rmdir($temp_dir3);

echo "\n[+] All Collision Guard Tests (F13.2 - F13.5) PASSED.\n";
