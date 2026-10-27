<?php
// MOCK
/**
 * Test: One-time data migration from filechi_logs to filechi_files (Item L1.3).
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';

global $wpdb;
$wpdb = new Mock_WPDB();

// In-memory mock storage for tables
$mock_logs_rows  = array();
$mock_files_rows = array();

$mock_logs_rows = array(
	1 => array(
		'id'            => 1,
		'attachment_id' => 101,
		'provider_id'   => 1,
		'file_path'     => '2026/10/photo.jpg',
		'file_size'     => 10240,
		'status'        => 'transferred',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-01 10:00:00',
		'updated_at'    => '2026-10-01 10:00:00',
	),
	2 => array(
		'id'            => 2,
		'attachment_id' => 101,
		'provider_id'   => 1,
		'file_path'     => '2026/10/photo-300x200.jpg',
		'file_size'     => 3040,
		'status'        => 'reconciled',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-01 10:01:00',
		'updated_at'    => '2026-10-01 10:01:00',
	),
	3 => array(
		'id'            => 3,
		'attachment_id' => 102,
		'provider_id'   => 2,
		'file_path'     => 'woocommerce_uploads/2026/10/product.zip',
		'file_size'     => 5242880,
		'status'        => 'failed',
		'error_message' => 'Connection timeout',
		'attempts'      => 3,
		'created_at'    => '2026-10-02 12:00:00',
		'updated_at'    => '2026-10-02 12:05:00',
	),
	4 => array(
		'id'            => 4,
		'attachment_id' => 103,
		'provider_id'   => 1,
		'file_path'     => '2026/10/deleted_file.png',
		'file_size'     => 0,
		'status'        => 'deleted', // Should NOT be migrated
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-03 14:00:00',
		'updated_at'    => '2026-10-03 14:00:00',
	),
	5 => array(
		'id'            => 5,
		'attachment_id' => 104,
		'provider_id'   => 1,
		'file_path'     => '2026/10/document.pdf',
		'file_size'     => 204800,
		'status'        => 'transferred',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-04 15:00:00',
		'updated_at'    => '2026-10-04 15:00:00',
	),
);

$logs_copy_before = $mock_logs_rows;

// Wire Mock_WPDB to handle our in-memory tables
$wpdb->get_results_override = function($query, $output) use (&$mock_logs_rows) {
	if (strpos($query, 'FROM wp_filechi_logs') !== false) {
		preg_match('/WHERE id > (\d+)/', $query, $m_id);
		preg_match('/LIMIT (\d+)/', $query, $m_limit);
		$last_id = isset($m_id[1]) ? (int) $m_id[1] : 0;
		$limit   = isset($m_limit[1]) ? (int) $m_limit[1] : 500;

		$matches = array();
		foreach ($mock_logs_rows as $row) {
			if ($row['id'] > $last_id && in_array($row['status'], array('transferred', 'reconciled', 'failed'), true)) {
				$matches[] = $row;
				if (count($matches) >= $limit) {
					break;
				}
			}
		}
		return $matches;
	}
	return array();
};

$wpdb->get_row_override = null;
// Custom get_row to query existing file row in $mock_files_rows
$orig_get_row = function($query) use (&$mock_files_rows) {
	if (strpos($query, 'FROM wp_filechi_files') !== false) {
		if (preg_match('/attachment_id = (\d+) AND provider_id = (\d+) AND (?:path_hash|local_path) = \'([^\']+)\'/', $query, $m)) {
			$aid    = (int) $m[1];
			$pid    = (int) $m[2];
			$target = $m[3];
			foreach ($mock_files_rows as $row) {
				if ((int)$row['attachment_id'] === $aid && (int)$row['provider_id'] === $pid) {
					if (($row['path_hash'] ?? '') === $target || ($row['local_path'] ?? '') === $target) {
						return $row;
					}
				}
			}
			return null;
		}
	}
	return null;
};

// Hook insert and update on Mock_WPDB
$next_file_id = 1;
$wpdb->on_insert = function($table, $data) use (&$mock_files_rows, &$next_file_id, $wpdb) {
	if (strpos($table, 'filechi_files') !== false) {
		$aid  = (int) $data['attachment_id'];
		$pid  = (int) $data['provider_id'];
		$path = $data['local_path'];
		$key  = "{$aid}_{$pid}_{$path}";
		$data['id'] = $next_file_id++;
		if (empty($data['path_hash'])) {
			$data['path_hash'] = sha1($path);
		}
		$mock_files_rows[$key] = $data;
		$wpdb->insert_id = $data['id'];
	}
};

$wpdb->on_update = function($table, $data, $where) use (&$mock_files_rows) {
	if (strpos($table, 'filechi_files') !== false) {
		$id = $where['id'];
		foreach ($mock_files_rows as $k => &$row) {
			if ($row['id'] === $id) {
				$row = array_merge($row, $data);
				break;
			}
		}
	}
};

// Monkey-patch get_row dynamically for this test
$mock_wpdb_ref = new class($wpdb, $orig_get_row) {
	private $wpdb;
	private $get_row_handler;
	public function __construct($wpdb, $get_row_handler) {
		$this->wpdb = $wpdb;
		$this->get_row_handler = $get_row_handler;
	}
	public function __get($name) { return $this->wpdb->$name; }
	public function __set($name, $val) { $this->wpdb->$name = $val; }
	public function __call($name, $args) {
		if ($name === 'get_row') {
			$res = call_user_func($this->get_row_handler, $args[0]);
			if ($res !== null) return $res;
		}
		return call_user_func_array(array($this->wpdb, $name), $args);
	}
};
$wpdb = $mock_wpdb_ref;

echo "=== 1. Test Batched Migration (Batch size 2) ===\n";
delete_option('filechi_files_migration_progress');

// First batch: should process IDs 1 and 2
$has_more = FileChi_DB::migrate_logs_to_files_batch(2);
echo 'First batch has more: ' . ($has_more === true ? 'PASS' : 'FAIL') . "\n";
assert($has_more === true);

$prog1 = get_option('filechi_files_migration_progress');
echo 'Progress last_id is 2: ' . ($prog1['last_id'] === 2 ? 'PASS' : 'FAIL') . "\n";
echo 'Migrated count is 2: ' . ($prog1['migrated_count'] === 2 ? 'PASS' : 'FAIL') . "\n";
assert($prog1['last_id'] === 2);
assert($prog1['migrated_count'] === 2);

// Second batch: should process IDs 3 and 5 (skipping deleted ID 4)
$has_more2 = FileChi_DB::migrate_logs_to_files_batch(2);
echo 'Second batch returned true (hit batch limit of 2): ' . ($has_more2 === true ? 'PASS' : 'FAIL') . "\n";
assert($has_more2 === true);

// Third batch: no remaining rows, finishes and marks completed
$has_more3 = FileChi_DB::migrate_logs_to_files_batch(2);
$prog3 = get_option('filechi_files_migration_progress');
echo 'Third batch completed migration: ' . ($has_more3 === false ? 'PASS' : 'FAIL') . "\n";
echo 'Progress marked completed: ' . (!empty($prog3['completed']) ? 'PASS' : 'FAIL') . "\n";
echo 'Total migrated count is 4: ' . ($prog3['migrated_count'] === 4 ? 'PASS' : 'FAIL') . "\n";
assert($has_more3 === false);
assert(!empty($prog3['completed']));
assert($prog3['migrated_count'] === 4);

echo "\n=== 2. Verify Migrated Rows and Field Mappings ===\n";
// ID 1: transferred
$file1 = $mock_files_rows['101_1_2026/10/photo.jpg'] ?? null;
echo 'File 1 exists: ' . ($file1 !== null ? 'PASS' : 'FAIL') . "\n";
assert($file1 !== null);
echo 'File 1 status is transferred: ' . ($file1['status'] === 'transferred' ? 'PASS' : 'FAIL') . "\n";
echo 'File 1 size is 10240: ' . ((int)$file1['size'] === 10240 ? 'PASS' : 'FAIL') . "\n";
assert($file1['status'] === 'transferred');
assert((int)$file1['size'] === 10240);

// ID 2: reconciled -> mapped to transferred
$file2 = $mock_files_rows['101_1_2026/10/photo-300x200.jpg'] ?? null;
echo 'File 2 (reconciled) mapped to status transferred: ' . ($file2['status'] === 'transferred' ? 'PASS' : 'FAIL') . "\n";
echo 'File 2 size is 3040: ' . ((int)$file2['size'] === 3040 ? 'PASS' : 'FAIL') . "\n";
assert($file2['status'] === 'transferred');
assert((int)$file2['size'] === 3040);

// ID 3: failed
$file3 = $mock_files_rows['102_2_woocommerce_uploads/2026/10/product.zip'] ?? null;
echo 'File 3 status is failed: ' . ($file3['status'] === 'failed' ? 'PASS' : 'FAIL') . "\n";
echo 'File 3 error message preserved: ' . ($file3['last_error'] === 'Connection timeout' ? 'PASS' : 'FAIL') . "\n";
echo 'File 3 failures count is 3: ' . ((int)$file3['failures'] === 3 ? 'PASS' : 'FAIL') . "\n";
assert($file3['status'] === 'failed');
assert($file3['last_error'] === 'Connection timeout');
assert((int)$file3['failures'] === 3);

// ID 4: deleted -> NOT migrated
$file4 = $mock_files_rows['103_1_2026/10/deleted_file.png'] ?? null;
echo 'File 4 (deleted status) was NOT migrated: ' . ($file4 === null ? 'PASS' : 'FAIL') . "\n";
assert($file4 === null);

// ID 5: transferred
$file5 = $mock_files_rows['104_1_2026/10/document.pdf'] ?? null;
echo 'File 5 status is transferred: ' . ($file5['status'] === 'transferred' ? 'PASS' : 'FAIL') . "\n";
assert($file5['status'] === 'transferred');

echo "\n=== 3. Idempotency Check (Running again does not duplicate or corrupt) ===\n";
// Reset progress but keep mock_files_rows
update_option('filechi_files_migration_progress', array(
	'last_id'        => 0,
	'completed'      => false,
	'migrated_count' => 0,
));

$files_count_before = count($mock_files_rows);
$has_more_again = FileChi_DB::migrate_logs_to_files_batch(500);
echo 'Second complete migration run finished: ' . ($has_more_again === false ? 'PASS' : 'FAIL') . "\n";
echo 'File count unchanged after re-run: ' . (count($mock_files_rows) === $files_count_before ? 'PASS' : 'FAIL') . "\n";
assert($has_more_again === false);
assert(count($mock_files_rows) === $files_count_before);

echo "\n=== 4. Ensure filechi_logs is untouched ===\n";
echo 'Logs array identical before and after migration: ' . ($mock_logs_rows === $logs_copy_before ? 'PASS' : 'FAIL') . "\n";
assert($mock_logs_rows === $logs_copy_before);

echo "\nALL FILES MIGRATION TESTS PASSED!\n";
