<?php
// MOCK
/**
 * Test: Equivalence tests between filechi_logs and filechi_files (Item L1.6).
 *
 * Verifies that:
 * 1. Download handler authorizes exactly the same keys before and after migration.
 * 2. Dashboard migration stats are identical before and after on the same fixture.
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';

global $wpdb;
$wpdb = new Mock_WPDB();

// Test fixture in filechi_logs
$fixture_logs = array(
	1 => array(
		'id'            => 1,
		'attachment_id' => 301,
		'provider_id'   => 1,
		'file_path'     => '2026/10/nature.jpg',
		'file_size'     => 150000,
		'status'        => 'transferred',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-01 10:00:00',
		'updated_at'    => '2026-10-01 10:00:00',
	),
	2 => array(
		'id'            => 2,
		'attachment_id' => 301,
		'provider_id'   => 1,
		'file_path'     => '2026/10/nature-300x200.jpg',
		'file_size'     => 25000,
		'status'        => 'reconciled',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-01 10:01:00',
		'updated_at'    => '2026-10-01 10:01:00',
	),
	3 => array(
		'id'            => 3,
		'attachment_id' => 302,
		'provider_id'   => 2,
		'file_path'     => 'woocommerce_uploads/2026/10/manual.pdf',
		'file_size'     => 2500000,
		'status'        => 'transferred',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-02 11:00:00',
		'updated_at'    => '2026-10-02 11:00:00',
	),
	4 => array(
		'id'            => 4,
		'attachment_id' => 303,
		'provider_id'   => 1,
		'file_path'     => '2026/10/corrupt.png',
		'file_size'     => 0,
		'status'        => 'failed',
		'error_message' => 'Zero byte file',
		'attempts'      => 5,
		'created_at'    => '2026-10-03 12:00:00',
		'updated_at'    => '2026-10-03 12:05:00',
	),
	5 => array(
		'id'            => 5,
		'attachment_id' => 304,
		'provider_id'   => 1,
		'file_path'     => '2026/10/deleted_asset.jpg',
		'file_size'     => 0,
		'status'        => 'deleted',
		'error_message' => null,
		'attempts'      => 1,
		'created_at'    => '2026-10-04 13:00:00',
		'updated_at'    => '2026-10-04 13:00:00',
	),
);

$fixture_files = array();
$next_files_id = 1;

// Post meta fixture for stats
$GLOBALS['mock_post_meta'] = array(
	301 => array('_filechi_offloaded' => '1', '_filechi_provider_id' => '1'),
	302 => array('_filechi_offloaded' => '1', '_filechi_provider_id' => '2'),
	303 => array('_filechi_given_up'  => '1'),
	304 => array(),
	305 => array(), // Not offloaded
);

// Calculate expected baseline stats directly from legacy filechi_logs fixture
$expected_total_bytes = 0;
$expected_failed      = 0;
foreach ($fixture_logs as $log) {
	if (in_array($log['status'], array('transferred', 'reconciled'), true)) {
		$expected_total_bytes += (int) $log['file_size'];
	}
	if ($log['status'] === 'failed') {
		$expected_failed++;
	}
}

// Proxy Mock_WPDB to serve fixture_logs and fixture_files
$mock_proxy = new class($wpdb, $fixture_logs, $fixture_files, $next_files_id) {
	private $wpdb;
	public $logs_ref;
	public $files_ref;
	public $next_id_ref;

	public function __construct($wpdb, &$logs, &$files, &$next_id) {
		$this->wpdb        = $wpdb;
		$this->logs_ref    = &$logs;
		$this->files_ref   = &$files;
		$this->next_id_ref = &$next_id;
	}

	public function __get($name) { return $this->wpdb->$name; }
	public function __set($name, $val) { $this->wpdb->$name = $val; }

	public function prepare($query, ...$args) {
		return $this->wpdb->prepare($query, ...$args);
	}

	public function get_results($query, $output = 'ARRAY_A') {
		if (strpos($query, 'FROM wp_filechi_logs') !== false) {
			preg_match('/WHERE id > (\d+)/', $query, $m_id);
			preg_match('/LIMIT (\d+)/', $query, $m_limit);
			$last_id = isset($m_id[1]) ? (int) $m_id[1] : 0;
			$limit   = isset($m_limit[1]) ? (int) $m_limit[1] : 500;
			$results = array();
			foreach ($this->logs_ref as $r) {
				if ($r['id'] > $last_id && in_array($r['status'], array('transferred', 'reconciled', 'failed'), true)) {
					$results[] = $r;
					if (count($results) >= $limit) break;
				}
			}
			return $results;
		}
		if (strpos($query, 'FROM wp_filechi_files') !== false) {
			return array_values($this->files_ref);
		}
		return $this->wpdb->get_results($query, $output);
	}

	public function get_row($query, $output = 'ARRAY_A') {
		if (strpos($query, 'FROM wp_filechi_files') !== false) {
			if (preg_match("/attachment_id = (\d+) AND provider_id = (\d+) AND (?:path_hash|local_path) = '([^']+)'/", $query, $m)) {
				$aid    = (int) $m[1];
				$pid    = (int) $m[2];
				$target = $m[3];
				foreach ($this->files_ref as $row) {
					if ((int)$row['attachment_id'] === $aid && (int)$row['provider_id'] === $pid) {
						if (($row['path_hash'] ?? '') === $target || ($row['local_path'] ?? '') === $target) {
							return $row;
						}
					}
				}
				return null;
			}
		}
		return $this->wpdb->get_row($query, $output);
	}

	public function get_var($query) {
		// is_file_transferred query on wp_filechi_files
		if (strpos($query, 'FROM wp_filechi_files') !== false && strpos($query, "status = 'transferred'") !== false) {
			if (preg_match("/provider_id = (\d+)/", $query, $m)) {
				$pid = (int) $m[1];
				foreach ($this->files_ref as $row) {
					if ((int)$row['provider_id'] === $pid && $row['status'] === 'transferred') {
						$p_hash = $row['path_hash'] ?? sha1($row['local_path'] ?? '');
						$r_key  = $row['remote_key'] ?? '';
						$l_path = $row['local_path'] ?? '';
						if (($p_hash !== '' && strpos($query, "'" . addslashes($p_hash) . "'") !== false) ||
						    ($r_key !== '' && strpos($query, "'" . addslashes($r_key) . "'") !== false) ||
						    ($l_path !== '' && strpos($query, "'" . addslashes($l_path) . "'") !== false)) {
							return 'transferred';
						}
					}
				}
				return null;
			}
		}

		// Stats: SUM(size) FROM wp_filechi_files
		if (strpos($query, 'SUM(size) FROM wp_filechi_files') !== false) {
			$sum = 0;
			foreach ($this->files_ref as $row) {
				if ($row['status'] === 'transferred') {
					$sum += (int) ($row['size'] ?? 0);
				}
			}
			return $sum;
		}

		// Stats: COUNT(*) FROM wp_filechi_files WHERE status = 'failed'
		if (strpos($query, "COUNT(*) FROM wp_filechi_files WHERE status = 'failed'") !== false) {
			$cnt = 0;
			foreach ($this->files_ref as $row) {
				if ($row['status'] === 'failed') {
					$cnt++;
				}
			}
			return $cnt;
		}

		// Attachments count
		if (strpos($query, "post_type = 'attachment'") !== false) {
			return 5;
		}

		// Offloaded attachments count
		if (strpos($query, "meta_key = '_filechi_offloaded'") !== false) {
			return 2;
		}

		// Given up attachments count
		if (strpos($query, "meta_key = '_filechi_given_up'") !== false) {
			return 1;
		}

		return $this->wpdb->get_var($query);
	}

	public function insert($table, $data, $format = null) {
		if (strpos($table, 'filechi_files') !== false) {
			$aid  = (int) $data['attachment_id'];
			$pid  = (int) $data['provider_id'];
			$path = $data['local_path'];
			$key  = "{$aid}_{$pid}_{$path}";
			$data['id'] = $this->next_id_ref++;
			if (empty($data['path_hash'])) {
				$data['path_hash'] = sha1($path);
			}
			$this->files_ref[$key] = $data;
			$this->wpdb->insert_id = $data['id'];
			return 1;
		}
		return $this->wpdb->insert($table, $data, $format);
	}

	public function update($table, $data, $where, $data_format = null, $where_format = null) {
		if (strpos($table, 'filechi_files') !== false) {
			$id = $where['id'];
			foreach ($this->files_ref as &$row) {
				if ($row['id'] === $id) {
					$row = array_merge($row, $data);
					break;
				}
			}
			return 1;
		}
		return $this->wpdb->update($table, $data, $where, $data_format, $where_format);
	}
};
$wpdb = $mock_proxy;

echo "=== 1. Define Candidate Keys for Authorization Test ===\n";
$candidate_keys = array(
	// Transferred files (should authorize)
	array('pid' => 1, 'path' => '2026/10/nature.jpg', 'expected' => true),
	array('pid' => 1, 'path' => '2026/10/nature-300x200.jpg', 'expected' => true), // Reconciled on remote -> transferred
	array('pid' => 2, 'path' => 'woocommerce_uploads/2026/10/manual.pdf', 'expected' => true),

	// Wrong provider ID for existing file (should NOT authorize)
	array('pid' => 1, 'path' => 'woocommerce_uploads/2026/10/manual.pdf', 'expected' => false),
	array('pid' => 2, 'path' => '2026/10/nature.jpg', 'expected' => false),

	// Failed file (should NOT authorize)
	array('pid' => 1, 'path' => '2026/10/corrupt.png', 'expected' => false),

	// Deleted file (should NOT authorize)
	array('pid' => 1, 'path' => '2026/10/deleted_asset.jpg', 'expected' => false),

	// Non-existent ghost file (should NOT authorize)
	array('pid' => 1, 'path' => '2026/10/ghost.png', 'expected' => false),
);

// Determine authorization under legacy rules directly on filechi_logs fixture
$legacy_authorized = array();
foreach ($candidate_keys as $ck) {
	$pid  = $ck['pid'];
	$path = $ck['path'];
	$auth = false;
	foreach ($fixture_logs as $l) {
		if ((int)$l['provider_id'] === $pid && $l['file_path'] === $path && in_array($l['status'], array('transferred', 'reconciled'), true)) {
			$auth = true;
			break;
		}
	}
	$legacy_authorized["{$pid}|{$path}"] = $auth;
}

echo "=== 2. Run Migration from filechi_logs to filechi_files ===\n";
delete_option('filechi_files_migration_progress');
$migration_done = false;
while (!$migration_done) {
	$has_more = FileChi_DB::migrate_logs_to_files_batch(2);
	if (!$has_more) {
		$migration_done = true;
	}
}

echo 'Migration completed into filechi_files: ' . (count($fixture_files) === 4 ? 'PASS' : 'FAIL') . "\n";
assert(count($fixture_files) === 4);

echo "\n=== 3. Equivalence Check: Key Authorization ===\n";
foreach ($candidate_keys as $ck) {
	$pid      = $ck['pid'];
	$path     = $ck['path'];
	$legacy   = $legacy_authorized["{$pid}|{$path}"];
	$observed = FileChi_DB::is_file_transferred($pid, $path);

	$match = ($legacy === $observed && $observed === $ck['expected']);
	echo sprintf(
		"Provider %d, key '%s': Legacy=%s, New=%s (Expected=%s) -> %s\n",
		$pid,
		$path,
		$legacy ? 'YES' : 'NO',
		$observed ? 'YES' : 'NO',
		$ck['expected'] ? 'YES' : 'NO',
		$match ? 'PASS' : 'FAIL'
	);
	assert($match);
}

echo "\n=== 4. Equivalence Check: Dashboard Migration Stats ===\n";
$stats = FileChi_DB::get_migration_stats();

echo 'Total attachments: ' . ($stats['total'] === 5 ? 'PASS' : 'FAIL') . " (Expected: 5, Observed: {$stats['total']})\n";
assert($stats['total'] === 5);

echo 'Offloaded count: ' . ($stats['offloaded'] === 2 ? 'PASS' : 'FAIL') . " (Expected: 2, Observed: {$stats['offloaded']})\n";
assert($stats['offloaded'] === 2);

echo 'Given up count: ' . ($stats['given_up'] === 1 ? 'PASS' : 'FAIL') . " (Expected: 1, Observed: {$stats['given_up']})\n";
assert($stats['given_up'] === 1);

echo 'Remaining count: ' . ($stats['remaining'] === 2 ? 'PASS' : 'FAIL') . " (Expected: 2, Observed: {$stats['remaining']})\n";
assert($stats['remaining'] === 2);

echo 'Failed count matches legacy: ' . ($stats['failed'] === $expected_failed ? 'PASS' : 'FAIL') . " (Expected: {$expected_failed}, Observed: {$stats['failed']})\n";
assert($stats['failed'] === $expected_failed);

echo 'Total remote bytes matches legacy: ' . ((int)$stats['total_bytes'] === $expected_total_bytes ? 'PASS' : 'FAIL') . " (Expected: {$expected_total_bytes}, Observed: {$stats['total_bytes']})\n";
assert((int)$stats['total_bytes'] === $expected_total_bytes);

echo "\nALL EQUIVALENCE TESTS PASSED!\n";
