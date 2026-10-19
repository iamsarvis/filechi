<?php
// MOCK
/**
 * Test: FileChi_DB::record_file_state upsert and attachment deletion cleanup (Item L1.4).
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';

global $wpdb;
$wpdb = new Mock_WPDB();

$files_store = array();
$next_id     = 1;

// Custom Mock_WPDB handlers for files table
$wpdb->get_row_override = null;
$orig_get_row = function($query) use (&$files_store) {
	if (strpos($query, 'FROM wp_filechi_files') !== false) {
		if (preg_match('/attachment_id = (\d+) AND provider_id = (\d+) AND local_path = \'([^\']+)\'/', $query, $m)) {
			$aid  = (int) $m[1];
			$pid  = (int) $m[2];
			$path = $m[3];
			$key  = "{$aid}_{$pid}_{$path}";
			return $files_store[$key] ?? null;
		}
	}
	return null;
};

$wpdb->on_insert = function($table, $data) use (&$files_store, &$next_id) {
	if (strpos($table, 'filechi_files') !== false) {
		$aid  = (int) $data['attachment_id'];
		$pid  = (int) $data['provider_id'];
		$path = $data['local_path'];
		$key  = "{$aid}_{$pid}_{$path}";
		$data['id'] = $next_id++;
		$files_store[$key] = $data;
	}
};

$wpdb->on_update = function($table, $data, $where) use (&$files_store) {
	if (strpos($table, 'filechi_files') !== false) {
		$id = $where['id'];
		foreach ($files_store as $k => &$row) {
			if ($row['id'] === $id) {
				$row = array_merge($row, $data);
				break;
			}
		}
	}
};

$mock_wpdb_proxy = new class($wpdb, $orig_get_row, $files_store) {
	private $wpdb;
	private $get_row_handler;
	public $files_store_ref;
	public function __construct($wpdb, $get_row_handler, &$files_store) {
		$this->wpdb = $wpdb;
		$this->get_row_handler = $get_row_handler;
		$this->files_store_ref = &$files_store;
	}
	public function __get($name) { return $this->wpdb->$name; }
	public function __set($name, $val) { $this->wpdb->$name = $val; }
	public function __call($name, $args) {
		if ($name === 'get_row') {
			$res = call_user_func($this->get_row_handler, $args[0]);
			if ($res !== null) return $res;
		}
		if ($name === 'query' && strpos($args[0], 'DELETE FROM wp_filechi_files') !== false) {
			if (preg_match('/attachment_id = (\d+)/', $args[0], $m)) {
				$del_aid = (int) $m[1];
				$count = 0;
				foreach ($this->files_store_ref as $k => $row) {
					if ((int)$row['attachment_id'] === $del_aid) {
						unset($this->files_store_ref[$k]);
						$count++;
					}
				}
				return $count;
			}
		}
		return call_user_func_array(array($this->wpdb, $name), $args);
	}
};
$wpdb = $mock_wpdb_proxy;

echo "=== 1. Test record_file_state Insert and Upsert ===\n";
// Initial failure
$id1 = FileChi_DB::record_file_state(201, 1, '2026/10/banner.jpg', 'failed', array(
	'size'       => 15000,
	'last_error' => 'Timeout',
));
echo 'Initial failed record inserted: ' . ($id1 > 0 ? 'PASS' : 'FAIL') . "\n";
assert($id1 > 0);

$row1 = $files_store['201_1_2026/10/banner.jpg'] ?? null;
echo 'Status is failed: ' . ($row1['status'] === 'failed' ? 'PASS' : 'FAIL') . "\n";
echo 'Failures count is 1: ' . ((int)$row1['failures'] === 1 ? 'PASS' : 'FAIL') . "\n";
assert($row1['status'] === 'failed');
assert((int)$row1['failures'] === 1);

// Second consecutive failure increments failures
$id2 = FileChi_DB::record_file_state(201, 1, '2026/10/banner.jpg', 'failed', array(
	'size'       => 15000,
	'last_error' => 'Connection refused',
));
echo 'Same row ID returned on update: ' . ($id2 === $id1 ? 'PASS' : 'FAIL') . "\n";
assert($id2 === $id1);

$row2 = $files_store['201_1_2026/10/banner.jpg'] ?? null;
echo 'Failures incremented to 2: ' . ((int)$row2['failures'] === 2 ? 'PASS' : 'FAIL') . "\n";
echo 'Last error updated: ' . ($row2['last_error'] === 'Connection refused' ? 'PASS' : 'FAIL') . "\n";
assert((int)$row2['failures'] === 2);
assert($row2['last_error'] === 'Connection refused');

// Subsequent success resets failures
$id3 = FileChi_DB::record_file_state(201, 1, '2026/10/banner.jpg', 'transferred', array(
	'size' => 15000,
));
$row3 = $files_store['201_1_2026/10/banner.jpg'] ?? null;
echo 'Status updated to transferred: ' . ($row3['status'] === 'transferred' ? 'PASS' : 'FAIL') . "\n";
echo 'Failures reset to 0 on success: ' . ((int)$row3['failures'] === 0 ? 'PASS' : 'FAIL') . "\n";
assert($row3['status'] === 'transferred');
assert((int)$row3['failures'] === 0);

echo "\n=== 2. Add multiple files for attachment 201 and attachment 202 ===\n";
FileChi_DB::record_file_state(201, 1, '2026/10/banner-300x200.jpg', 'transferred', array('size' => 4500));
FileChi_DB::record_file_state(202, 1, '2026/10/other.jpg', 'transferred', array('size' => 25000));

echo 'Total file rows in store is 3: ' . (count($files_store) === 3 ? 'PASS' : 'FAIL') . "\n";
assert(count($files_store) === 3);

echo "\n=== 3. Deleting attachment removes its file rows ===\n";
$media = new FileChi_Media();
$media->handle_delete_attachment(201);

echo 'Total file rows after deleting attachment 201 is 1: ' . (count($files_store) === 1 ? 'PASS' : 'FAIL') . "\n";
echo 'Attachment 201 banner removed: ' . (!isset($files_store['201_1_2026/10/banner.jpg']) ? 'PASS' : 'FAIL') . "\n";
echo 'Attachment 201 thumbnail removed: ' . (!isset($files_store['201_1_2026/10/banner-300x200.jpg']) ? 'PASS' : 'FAIL') . "\n";
echo 'Attachment 202 other file preserved: ' . (isset($files_store['202_1_2026/10/other.jpg']) ? 'PASS' : 'FAIL') . "\n";
assert(count($files_store) === 1);
assert(!isset($files_store['201_1_2026/10/banner.jpg']));
assert(!isset($files_store['201_1_2026/10/banner-300x200.jpg']));
assert(isset($files_store['202_1_2026/10/other.jpg']));

echo "\nALL FILE STATE RECORDING TESTS PASSED!\n";
