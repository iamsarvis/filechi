<?php
// MOCK
/**
 * Test: Safe Uninstall and Deactivation handling
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	define('WP_UNINSTALL_PLUGIN', true);
}
require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$wpdb = new Mock_WPDB();

echo "=== 1. Uninstall with delete_data_on_uninstall = 0 (Safe Default) ===\n";
$GLOBALS['mock_options'] = array(
	'filechi_settings'         => array('delete_data_on_uninstall' => 0),
	'filechi_db_version'       => '1.0.0',
	'filechi_migration_status' => 'idle',
);

require FILECHI_DIR . 'uninstall.php';

// Check: tables must NOT be dropped
$dropped_tables = array_filter($wpdb->queries, function($q) {
	return stripos($q, 'DROP TABLE') !== false;
});
echo 'Tables dropped when delete_data_on_uninstall is 0: ' . count($dropped_tables) . ' (Expected: 0) -> ' . (count($dropped_tables) === 0 ? 'PASS' : 'FAIL') . "\n";
assert(count($dropped_tables) === 0);

// Check: options must remain intact
echo 'filechi_settings option preserved: ' . (isset($GLOBALS['mock_options']['filechi_settings']) ? 'PASS' : 'FAIL') . "\n";
echo 'filechi_db_version option preserved: ' . (isset($GLOBALS['mock_options']['filechi_db_version']) ? 'PASS' : 'FAIL') . "\n";
assert(isset($GLOBALS['mock_options']['filechi_settings']));
assert(isset($GLOBALS['mock_options']['filechi_db_version']));

// Check: postmeta must NOT be deleted
$deleted_postmeta = array_filter($wpdb->queries, function($q) {
	return stripos($q, 'DELETE FROM wp_postmeta') !== false;
});
echo 'Postmeta deleted when delete_data_on_uninstall is 0: ' . count($deleted_postmeta) . ' (Expected: 0) -> ' . (count($deleted_postmeta) === 0 ? 'PASS' : 'FAIL') . "\n";
assert(count($deleted_postmeta) === 0);

// Check: transients deleted and scheduled actions cleared
$transient_queries = array_filter($wpdb->queries, function($q) {
	return stripos($q, '_transient_filechi_') !== false || stripos(stripslashes($q), '_transient_filechi_') !== false;
});
echo 'Transients cleaned up: ' . (count($transient_queries) > 0 ? 'PASS' : 'FAIL') . "\n";
assert(count($transient_queries) > 0);
assert(in_array('filechi_process_migration_batch', $GLOBALS['mock_scheduled_hooks_cleared']));

echo "\n=== 2. Uninstall with delete_data_on_uninstall = 1 (Opt-in full wipe) ===\n";
$wpdb->queries          = array();
$GLOBALS['mock_options'] = array(
	'filechi_settings'                      => array('delete_data_on_uninstall' => 1),
	'filechi_db_version'                    => '1.0.0',
	'filechi_migration_status'              => 'idle',
	'filechi_protected_path_missing_notice' => array('attachment_id' => 1),
);

filechi_uninstall_site();

// Check: tables must be dropped
$dropped_tables = array_filter($wpdb->queries, function($q) {
	return stripos($q, 'DROP TABLE') !== false;
});
echo 'Tables dropped when delete_data_on_uninstall is 1: ' . count($dropped_tables) . ' (Expected: 2) -> ' . (count($dropped_tables) === 2 ? 'PASS' : 'FAIL') . "\n";
assert(count($dropped_tables) === 2);

// Check: options must be deleted
echo 'filechi_settings deleted: ' . (!isset($GLOBALS['mock_options']['filechi_settings']) ? 'PASS' : 'FAIL') . "\n";
echo 'filechi_db_version deleted: ' . (!isset($GLOBALS['mock_options']['filechi_db_version']) ? 'PASS' : 'FAIL') . "\n";
assert(!isset($GLOBALS['mock_options']['filechi_settings']));
assert(!isset($GLOBALS['mock_options']['filechi_db_version']));
assert(!isset($GLOBALS['mock_options']['filechi_migration_status']));
assert(!isset($GLOBALS['mock_options']['filechi_protected_path_missing_notice']));

// Check: postmeta deleted
$deleted_postmeta = array_filter($wpdb->queries, function($q) {
	return stripos($q, 'DELETE FROM wp_postmeta') !== false;
});
echo 'Postmeta deleted: ' . (count($deleted_postmeta) > 0 ? 'PASS' : 'FAIL') . "\n";
assert(count($deleted_postmeta) > 0);

echo "\n=== 3. Deactivation Hook Clears Jobs Without Data Deletion ===\n";
$GLOBALS['mock_scheduled_hooks_cleared'] = array();
$GLOBALS['mock_unscheduled_actions']     = array();
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
FileChi_Activator::deactivate();
echo 'Deactivation cleared Action Scheduler migration batch: ' . (!empty($GLOBALS['mock_unscheduled_actions']) ? 'PASS' : 'FAIL') . "\n";
echo 'Deactivation cleared WP-Cron hooks: ' . (in_array('filechi_process_migration_batch', $GLOBALS['mock_scheduled_hooks_cleared']) ? 'PASS' : 'FAIL') . "\n";
assert(!empty($GLOBALS['mock_unscheduled_actions']));
assert(in_array('filechi_process_migration_batch', $GLOBALS['mock_scheduled_hooks_cleared']));

echo "\nALL UNINSTALL TESTS PASSED!\n";
