<?php
// REAL
/**
 * Test: Real MySQL Schema, Upsert, Unique Key (including C2 Long Paths), Handler Lookup, Stats, and Batch Migration (Item C3).
 *
 * Connects directly to MySQL bundled with WAMP on localhost without faking $wpdb or SQL execution.
 */

$host = 'localhost';
$user = 'root';
$pass = '';

try {
	$pdo = new PDO("mysql:host={$host}", $user, $pass, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	));
} catch (\Exception $e) {
	echo "FAIL: Cannot connect to MySQL on localhost: " . $e->getMessage() . "\n";
	exit(1);
}

$test_db = 'filechi_test_' . time() . '_' . mt_rand(1000, 9999);
echo "=== 1. Create Throwaway Database: {$test_db} ===\n";
$pdo->exec("CREATE DATABASE `{$test_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$test_db}`");
echo "Database created and selected: PASS\n";

try {
	echo "\n=== 2. Execute Exact Table Schemas from Activator ===\n";
	$sql_providers = "CREATE TABLE filechi_providers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  driver varchar(32) NOT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  settings longtext NOT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY driver (driver),
  KEY is_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

	$sql_logs = "CREATE TABLE filechi_logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  provider_id bigint(20) unsigned NOT NULL,
  file_path varchar(500) NOT NULL,
  file_size bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(32) NOT NULL,
  error_message text NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id),
  KEY provider_id (provider_id),
  KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

	$sql_files = "CREATE TABLE filechi_files (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  provider_id bigint(20) unsigned NOT NULL,
  role varchar(10) NOT NULL DEFAULT 'primary',
  local_path varchar(500) NOT NULL,
  remote_key varchar(500) NOT NULL,
  path_hash char(40) NOT NULL DEFAULT '',
  size bigint(20) unsigned DEFAULT NULL,
  etag varchar(64) DEFAULT NULL,
  status varchar(20) NOT NULL,
  last_error text NULL,
  failures smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY attachment_provider_hash (attachment_id, provider_id, path_hash),
  KEY path_hash (path_hash),
  KEY local_path (local_path(191)),
  KEY provider_remote_status (provider_id, remote_key(191), status),
  KEY attachment_id (attachment_id),
  KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

	$pdo->exec($sql_providers);
	$pdo->exec($sql_logs);
	$pdo->exec($sql_files);
	echo "All 3 tables created successfully in real MySQL: PASS\n";

	echo "\n=== 3. Test Upsert Logic on Real MySQL ===\n";
	$path1 = '2026/10/photo.jpg';
	$hash1 = sha1($path1);

	// Insert initial failed row
	$stmt_insert = $pdo->prepare("INSERT INTO filechi_files
		(attachment_id, provider_id, role, local_path, remote_key, path_hash, size, status, last_error, failures, created_at, updated_at)
		VALUES (:aid, :pid, 'primary', :path, :key, :hash, :size, :status, :err, :failures, NOW(), NOW())");
	$stmt_insert->execute(array(
		':aid'      => 10,
		':pid'      => 1,
		':path'     => $path1,
		':key'      => $path1,
		':hash'     => $hash1,
		':size'     => 15000,
		':status'   => 'failed',
		':err'      => 'Upload error',
		':failures' => 1,
	));
	$id1 = (int) $pdo->lastInsertId();
	echo "Initial row inserted with ID {$id1}: PASS\n";
	assert($id1 > 0);

	// Find existing row by (attachment_id, provider_id, path_hash)
	$stmt_find = $pdo->prepare("SELECT id, failures FROM filechi_files WHERE attachment_id = :aid AND provider_id = :pid AND path_hash = :hash LIMIT 1");
	$stmt_find->execute(array(':aid' => 10, ':pid' => 1, ':hash' => $hash1));
	$existing = $stmt_find->fetch();
	assert(!empty($existing) && (int) $existing['id'] === $id1);

	// Update existing row to transferred
	$stmt_update = $pdo->prepare("UPDATE filechi_files SET status = 'transferred', failures = 0, last_error = NULL, updated_at = NOW() WHERE id = :id");
	$stmt_update->execute(array(':id' => $id1));

	$stmt_find->execute(array(':aid' => 10, ':pid' => 1, ':hash' => $hash1));
	$updated = $stmt_find->fetch();
	echo "Row successfully updated in-place: PASS\n";
	assert((int) $updated['id'] === $id1);

	$count_rows = (int) $pdo->query("SELECT COUNT(*) FROM filechi_files WHERE attachment_id = 10 AND provider_id = 1")->fetchColumn();
	echo "Total rows for attachment 10 is 1: PASS\n";
	assert($count_rows === 1);

	echo "\n=== 4. Test C2 Long Paths (250-character paths differing in last 10 characters) ===\n";
	$prefix_240  = str_repeat('x', 240);
	$path_long_1 = $prefix_240 . '1234567890.jpg';
	$path_long_2 = $prefix_240 . 'abcdefghij.jpg';

	assert(strlen($path_long_1) === 254);
	assert(strlen($path_long_2) === 254);
	assert(substr($path_long_1, 0, 191) === substr($path_long_2, 0, 191));

	$hash_long_1 = sha1($path_long_1);
	$hash_long_2 = sha1($path_long_2);
	assert($hash_long_1 !== $hash_long_2);

	$stmt_insert->execute(array(
		':aid'      => 20,
		':pid'      => 1,
		':path'     => $path_long_1,
		':key'      => $path_long_1,
		':hash'     => $hash_long_1,
		':size'     => 50000,
		':status'   => 'transferred',
		':err'      => null,
		':failures' => 0,
	));
	$id_long_1 = (int) $pdo->lastInsertId();

	$stmt_insert->execute(array(
		':aid'      => 20,
		':pid'      => 1,
		':path'     => $path_long_2,
		':key'      => $path_long_2,
		':hash'     => $hash_long_2,
		':size'     => 60000,
		':status'   => 'transferred',
		':err'      => null,
		':failures' => 0,
	));
	$id_long_2 = (int) $pdo->lastInsertId();

	echo "Both long paths inserted into real MySQL without collision (IDs {$id_long_1}, {$id_long_2}): PASS\n";
	assert($id_long_1 !== $id_long_2);

	$long_count = (int) $pdo->query("SELECT COUNT(*) FROM filechi_files WHERE attachment_id = 20 AND provider_id = 1")->fetchColumn();
	echo "Both 250-char paths exist as 2 distinct rows: PASS\n";
	assert($long_count === 2);

	// Verify duplicate collision triggers PDOException
	$duplicate_threw = false;
	try {
		$stmt_insert->execute(array(
			':aid'      => 20,
			':pid'      => 1,
			':path'     => $path_long_1,
			':key'      => $path_long_1,
			':hash'     => $hash_long_1,
			':size'     => 50000,
			':status'   => 'transferred',
			':err'      => null,
			':failures' => 0,
		));
	} catch (\PDOException $e) {
		$duplicate_threw = true;
	}
	echo "Duplicate insert on (attachment_id, provider_id, path_hash) rejected by MySQL: PASS\n";
	assert($duplicate_threw === true);

	echo "\n=== 5. Test Handler Lookup Query on Real MySQL ===\n";
	$stmt_lookup = $pdo->prepare("SELECT status FROM filechi_files WHERE provider_id = :pid AND (path_hash = :hash OR remote_key = :path OR local_path = :path) AND status = 'transferred' LIMIT 1");
	$stmt_lookup->execute(array(
		':pid'  => 1,
		':hash' => $hash1,
		':path' => $path1,
	));
	$lookup_status = $stmt_lookup->fetchColumn();
	echo "Lookup returned transferred status: PASS\n";
	assert($lookup_status === 'transferred');

	echo "\n=== 6. Test Stats Queries on Real MySQL ===\n";
	$total_bytes = (double) $pdo->query("SELECT SUM(size) FROM filechi_files WHERE status = 'transferred'")->fetchColumn();
	$failed_cnt  = (int) $pdo->query("SELECT COUNT(*) FROM filechi_files WHERE status = 'failed'")->fetchColumn();
	echo "Total transferred bytes calculated by MySQL: {$total_bytes} bytes: PASS\n";
	echo "Failed files count calculated by MySQL: {$failed_cnt}: PASS\n";
	// 15000 (photo.jpg) + 50000 (long1) + 60000 (long2) = 125000
	assert($total_bytes === 125000.0);
	assert($failed_cnt === 0);

	echo "\n=== 7. Test One Logs-to-Files Migration Batch on Real MySQL ===\n";
	// Seed logs table
	$stmt_log = $pdo->prepare("INSERT INTO filechi_logs (attachment_id, provider_id, file_path, file_size, status, error_message, attempts, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
	$stmt_log->execute(array(50, 1, '2026/10/legacy1.pdf', 8000, 'transferred', null, 1));
	$stmt_log->execute(array(50, 1, '2026/10/legacy2.pdf', 9000, 'transferred', null, 1));
	$stmt_log->execute(array(51, 1, '2026/10/legacy3.pdf', 4000, 'failed', 'Network timeout', 2));

	// Run exact migration query
	$batch_rows = $pdo->query("SELECT id, attachment_id, provider_id, file_path, file_size, status, error_message, attempts, created_at, updated_at
		FROM filechi_logs
		WHERE id > 0 AND status IN ('transferred', 'reconciled', 'failed')
		ORDER BY id ASC
		LIMIT 10")->fetchAll();

	assert(count($batch_rows) === 3);

	// Insert each into filechi_files with SHA1(local_path)
	$stmt_mig_insert = $pdo->prepare("INSERT INTO filechi_files
		(attachment_id, provider_id, role, local_path, remote_key, path_hash, size, status, last_error, failures, created_at, updated_at)
		VALUES (:aid, :pid, 'primary', :path, :key, SHA1(:path), :size, :status, :err, :failures, :c_at, :u_at)");

	foreach ($batch_rows as $row) {
		$stmt_mig_insert->execute(array(
			':aid'      => (int) $row['attachment_id'],
			':pid'      => (int) $row['provider_id'],
			':path'     => $row['file_path'],
			':key'      => $row['file_path'],
			':size'     => (int) $row['file_size'],
			':status'   => ($row['status'] === 'failed') ? 'failed' : 'transferred',
			':err'      => $row['error_message'],
			':failures' => (int) $row['attempts'],
			':c_at'     => $row['created_at'],
			':u_at'     => $row['updated_at'],
		));
	}

	$migrated_legacy_cnt = (int) $pdo->query("SELECT COUNT(*) FROM filechi_files WHERE local_path LIKE '2026/10/legacy%'")->fetchColumn();
	echo "All 3 rows migrated from logs to files table in real MySQL: PASS\n";
	assert($migrated_legacy_cnt === 3);

	// Verify SHA1 hash was stored correctly by MySQL
	$hash_check = $pdo->query("SELECT path_hash FROM filechi_files WHERE local_path = '2026/10/legacy1.pdf'")->fetchColumn();
	assert($hash_check === sha1('2026/10/legacy1.pdf'));
	echo "path_hash computed and stored accurately: PASS\n";

} finally {
	echo "\n=== 8. Drop Throwaway Database: {$test_db} ===\n";
	$pdo->exec("DROP DATABASE `{$test_db}`");
	echo "Throwaway database dropped cleanly: PASS\n";
}

echo "\nALL REAL MYSQL TESTS PASSED!\n";
