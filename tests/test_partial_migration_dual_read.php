<?php
// MOCK
/**
 * Test: Dual-read during partial migration (Item C1).
 *
 * Verifies that until filechi_files_migration_progress is marked completed:
 * 1. is_file_transferred() falls back to filechi_logs for files not yet migrated.
 * 2. get_attachment_files_by_path() falls back to filechi_logs for missing files.
 * 3. get_migration_stats() reads authoritative totals from logs table.
 * 4. Once migration completes, readers read authoritatively from filechi_files.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';

global $wpdb;
$wpdb = new Mock_WPDB();

$mock_logs = array(
	1 => array('id' => 1, 'attachment_id' => 10, 'provider_id' => 1, 'file_path' => '2026/10/f1.jpg', 'file_size' => 1000, 'status' => 'transferred', 'error_message' => null, 'attempts' => 1, 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00'),
	2 => array('id' => 2, 'attachment_id' => 10, 'provider_id' => 1, 'file_path' => '2026/10/f2.jpg', 'file_size' => 2000, 'status' => 'transferred', 'error_message' => null, 'attempts' => 1, 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00'),
	3 => array('id' => 3, 'attachment_id' => 11, 'provider_id' => 1, 'file_path' => '2026/10/f3.jpg', 'file_size' => 3000, 'status' => 'transferred', 'error_message' => null, 'attempts' => 1, 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00'),
	4 => array('id' => 4, 'attachment_id' => 11, 'provider_id' => 1, 'file_path' => '2026/10/f4.jpg', 'file_size' => 4000, 'status' => 'transferred', 'error_message' => null, 'attempts' => 1, 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00'),
	5 => array('id' => 5, 'attachment_id' => 12, 'provider_id' => 1, 'file_path' => '2026/10/f5.jpg', 'file_size' => 5000, 'status' => 'failed',      'error_message' => 'Upload failed', 'attempts' => 3, 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00'),
);

$mock_files = array();
$next_file_id = 1;

$wpdb->get_results_override = function($query, $output = 'ARRAY_A') use (&$mock_logs, &$mock_files) {
	// Query for migration batch from logs
	if (strpos($query, 'FROM wp_filechi_logs') !== false && strpos($query, 'WHERE id >') !== false) {
		preg_match('/WHERE id > (\d+).*LIMIT (\d+)/s', $query, $m);
		$last_id = isset($m[1]) ? (int) $m[1] : 0;
		$limit   = isset($m[2]) ? (int) $m[2] : 500;
		$res = array();
		foreach ($mock_logs as $id => $row) {
			if ($id > $last_id && in_array($row['status'], array('transferred', 'reconciled', 'failed'), true)) {
				$res[] = $row;
				if (count($res) >= $limit) break;
			}
		}
		return $res;
	}

	// Query for get_attachment_files_by_path
	if (strpos($query, 'FROM wp_filechi_files') !== false && strpos($query, 'attachment_id =') !== false) {
		preg_match('/attachment_id = (\d+) AND provider_id = (\d+)/', $query, $m);
		$aid = isset($m[1]) ? (int) $m[1] : 0;
		$pid = isset($m[2]) ? (int) $m[2] : 0;
		$res = array();
		foreach ($mock_files as $row) {
			if ((int) $row['attachment_id'] === $aid && (int) $row['provider_id'] === $pid) {
				$res[] = $row;
			}
		}
		return $res;
	}

	// Query for get_attachment_logs_by_file
	if (strpos($query, 'FROM wp_filechi_logs') !== false && strpos($query, 'attachment_id =') !== false) {
		preg_match('/attachment_id = (\d+) AND provider_id = (\d+)/', $query, $m);
		$aid = isset($m[1]) ? (int) $m[1] : 0;
		$pid = isset($m[2]) ? (int) $m[2] : 0;
		$res = array();
		foreach ($mock_logs as $row) {
			if ((int) $row['attachment_id'] === $aid && (int) $row['provider_id'] === $pid) {
				$res[] = $row;
			}
		}
		return $res;
	}

	return array();
};

$wpdb->get_row_override = function($query, $output = 'ARRAY_A') use (&$mock_files) {
	if (strpos($query, 'FROM wp_filechi_files') !== false) {
		preg_match('/attachment_id = (\d+) AND provider_id = (\d+) AND path_hash = \'([^\']+)\'/', $query, $m);
		if ($m) {
			$aid  = (int) $m[1];
			$pid  = (int) $m[2];
			$hash = $m[3];
			foreach ($mock_files as $row) {
				if ((int) $row['attachment_id'] === $aid && (int) $row['provider_id'] === $pid && ($row['path_hash'] ?? '') === $hash) {
					return $row;
				}
			}
		}
	}
	return null;
};

$wpdb->get_var_override = function($query) use (&$mock_files, &$mock_logs) {
	// is_file_transferred from wp_filechi_files
	if (strpos($query, 'SELECT status FROM wp_filechi_files') !== false) {
		preg_match('/provider_id = (\d+).*path_hash = \'([^\']+)\'/', $query, $m);
		if ($m) {
			$pid  = (int) $m[1];
			$hash = $m[2];
			foreach ($mock_files as $row) {
				if ((int) $row['provider_id'] === $pid && ($row['path_hash'] ?? '') === $hash && $row['status'] === 'transferred') {
					return 'transferred';
				}
			}
		}
		return null;
	}

	// is_file_transferred fallback from wp_filechi_logs
	if (strpos($query, 'SELECT status FROM wp_filechi_logs') !== false) {
		preg_match('/provider_id = (\d+) AND file_path = \'([^\']+)\'/', $query, $m);
		if ($m) {
			$pid  = (int) $m[1];
			$path = $m[2];
			foreach ($mock_logs as $row) {
				if ((int) $row['provider_id'] === $pid && $row['file_path'] === $path && $row['status'] === 'transferred') {
					return 'transferred';
				}
			}
		}
		return null;
	}

	// Stats: SUM(size) from wp_filechi_files
	if (strpos($query, 'SUM(size) FROM wp_filechi_files') !== false) {
		$sum = 0;
		foreach ($mock_files as $row) {
			if ($row['status'] === 'transferred') {
				$sum += (int) ($row['size'] ?? 0);
			}
		}
		return (string) $sum;
	}

	// Stats: COUNT(*) from wp_filechi_files WHERE status = 'failed'
	if (strpos($query, "COUNT(*) FROM wp_filechi_files WHERE status = 'failed'") !== false) {
		$count = 0;
		foreach ($mock_files as $row) {
			if ($row['status'] === 'failed') $count++;
		}
		return (string) $count;
	}

	// Stats: SUM(file_size) from wp_filechi_logs
	if (strpos($query, 'SUM(file_size) FROM wp_filechi_logs') !== false) {
		$sum = 0;
		foreach ($mock_logs as $row) {
			if (in_array($row['status'], array('transferred', 'reconciled'), true)) {
				$sum += (int) ($row['file_size'] ?? 0);
			}
		}
		return (string) $sum;
	}

	// Stats: COUNT(*) from wp_filechi_logs WHERE status = 'failed'
	if (strpos($query, "COUNT(*) FROM wp_filechi_logs WHERE status = 'failed'") !== false) {
		$count = 0;
		foreach ($mock_logs as $row) {
			if ($row['status'] === 'failed') $count++;
		}
		return (string) $count;
	}

	// Total attachments
	if (strpos($query, "post_type = 'attachment'") !== false) {
		return '3';
	}

	// Offloaded count
	if (strpos($query, "meta_key = '_filechi_offloaded'") !== false) {
		return '2';
	}

	// Given up count
	if (strpos($query, "meta_key = '_filechi_given_up'") !== false) {
		return '0';
	}

	return '0';
};

$wpdb->on_insert = function($table, $data) use (&$mock_files, &$next_file_id, $wpdb) {
	if (strpos($table, 'filechi_files') !== false) {
		$data['id'] = $next_file_id++;
		if (empty($data['path_hash'])) {
			$data['path_hash'] = sha1($data['local_path']);
		}
		$key = "{$data['attachment_id']}_{$data['provider_id']}_{$data['path_hash']}";
		$mock_files[$key] = $data;
		$wpdb->insert_id = $data['id'];
	}
};

$wpdb->on_update = function($table, $data, $where) use (&$mock_files) {
	if (strpos($table, 'filechi_files') !== false) {
		$id = $where['id'];
		foreach ($mock_files as $k => &$row) {
			if ($row['id'] === $id) {
				$row = array_merge($row, $data);
				break;
			}
		}
	}
};

echo "=== 1. Setup Initial State (Migration Not Started) ===\n";
delete_option('filechi_files_migration_progress');
assert(FileChi_DB::is_files_migration_completed() === false, 'Migration must initially be incomplete');
echo "Initial migration completed status is false: PASS\n";

echo "\n=== 2. Run Partial Migration (Batch 2 of 5) ===\n";
$has_more = FileChi_DB::migrate_logs_to_files_batch(2);
echo "Batch 1 (size 2) has_more is true: " . ($has_more ? 'PASS' : 'FAIL') . "\n";
assert($has_more === true);

$prog = get_option('filechi_files_migration_progress');
echo "Progress last_id is 2: " . ((int) $prog['last_id'] === 2 ? 'PASS' : 'FAIL') . "\n";
echo "Progress completed is false: " . (empty($prog['completed']) ? 'PASS' : 'FAIL') . "\n";
assert((int) $prog['last_id'] === 2);
assert(empty($prog['completed']));
assert(count($mock_files) === 2, 'Exactly 2 files should be in filechi_files table');
echo "filechi_files has exactly 2 rows: PASS\n";

echo "\n=== 3. Verify Dual-Read Authorizes Same Keys As Before ===\n";
// Key 1 (id=1) was migrated into filechi_files
assert(FileChi_DB::is_file_transferred(1, '2026/10/f1.jpg') === true, 'Migrated key f1 must be authorized');
// Key 2 (id=2) was migrated into filechi_files
assert(FileChi_DB::is_file_transferred(1, '2026/10/f2.jpg') === true, 'Migrated key f2 must be authorized');
// Key 3 (id=3) is NOT yet in filechi_files; must fall back to filechi_logs
assert(FileChi_DB::is_file_transferred(1, '2026/10/f3.jpg') === true, 'Unmigrated key f3 must be authorized via fallback');
// Key 4 (id=4) is NOT yet in filechi_files; must fall back to filechi_logs
assert(FileChi_DB::is_file_transferred(1, '2026/10/f4.jpg') === true, 'Unmigrated key f4 must be authorized via fallback');
// Key 5 (id=5) is failed in logs; must return false
assert(FileChi_DB::is_file_transferred(1, '2026/10/f5.jpg') === false, 'Failed file must not be authorized');
// Unknown key must return false
assert(FileChi_DB::is_file_transferred(1, '2026/10/unknown.jpg') === false, 'Unknown file must not be authorized');
echo "All transferred keys authorized seamlessly during partial migration: PASS\n";

echo "\n=== 4. Verify get_attachment_files_by_path Dual-Read Fallback ===\n";
// Attachment 11 has files f3 and f4 which are NOT yet in filechi_files
$att11_files = FileChi_DB::get_attachment_files_by_path(11, 1);
echo "Attachment 11 files retrieved via fallback: " . (count($att11_files) === 2 ? 'PASS' : 'FAIL') . "\n";
assert(count($att11_files) === 2);
assert(isset($att11_files['2026/10/f3.jpg']));
assert(isset($att11_files['2026/10/f4.jpg']));
echo "Attachment 11 files f3 and f4 populated: PASS\n";

echo "\n=== 5. Verify get_migration_stats Dual-Read Accuracy ===\n";
$stats = FileChi_DB::get_migration_stats();
echo "Stats total_bytes during partial migration is 10000: " . ((int)$stats['total_bytes'] === 10000 ? 'PASS' : 'FAIL') . "\n";
echo "Stats failed count during partial migration is 1: " . ((int)$stats['failed'] === 1 ? 'PASS' : 'FAIL') . "\n";
assert((int)$stats['total_bytes'] === 10000, 'Total bytes must reflect all 4 transferred files in logs');
assert((int)$stats['failed'] === 1, 'Failed count must reflect failed file in logs');

echo "\n=== 6. Complete Remaining Batches and Verify Completion ===\n";
$has_more_2 = FileChi_DB::migrate_logs_to_files_batch(2); // rows 3, 4
assert($has_more_2 === true);
$has_more_3 = FileChi_DB::migrate_logs_to_files_batch(2); // row 5, completes
assert($has_more_3 === false);

assert(FileChi_DB::is_files_migration_completed() === true, 'Migration must be marked completed');
echo "Migration marked completed: PASS\n";
assert(count($mock_files) === 5, 'All 5 rows must now be in filechi_files');

// Post-completion readers read from filechi_files directly
assert(FileChi_DB::is_file_transferred(1, '2026/10/f3.jpg') === true);
$post_stats = FileChi_DB::get_migration_stats();
echo "Post-completion total_bytes is 10000: " . ((int)$post_stats['total_bytes'] === 10000 ? 'PASS' : 'FAIL') . " (Observed: " . var_export($post_stats['total_bytes'], true) . ")\n";
echo "Post-completion failed count is 1: " . ((int)$post_stats['failed'] === 1 ? 'PASS' : 'FAIL') . "\n";
assert((int)$post_stats['total_bytes'] === 10000);
assert((int)$post_stats['failed'] === 1);

echo "\nALL DUAL-READ PARTIAL MIGRATION TESTS PASSED!\n";
