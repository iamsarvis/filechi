<?php
// MOCK
/**
 * Test: Database upgrade routine, concurrency lock, and error notice (Item L1.1).
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';

global $wpdb;
$wpdb = new Mock_WPDB();

echo "=== 1. Simulate an old version and upgrade ===\n";
update_option('filechi_db_version', '1.0.0');
delete_transient('filechi_db_upgrading');
delete_option('filechi_db_upgrade_error');

$migrated_actions = array();
add_action('filechi_db_upgrade_1_1_0', function() use (&$migrated_actions) {
	$migrated_actions[] = '1.1.0';
});

$result = FileChi_Activator::maybe_upgrade();
echo 'Upgrade returned true: ' . ($result === true ? 'PASS' : 'FAIL') . "\n";
assert($result === true);

$current_version = get_option('filechi_db_version');
echo 'DB version updated to ' . FileChi_Activator::DB_VERSION . ': ' . ($current_version === FileChi_Activator::DB_VERSION ? 'PASS' : 'FAIL') . "\n";
assert($current_version === FileChi_Activator::DB_VERSION);

echo 'Migration action fired: ' . (in_array('1.1.0', $migrated_actions, true) ? 'PASS' : 'FAIL') . "\n";
assert(in_array('1.1.0', $migrated_actions, true));

echo 'Lock transient cleared after run: ' . (get_transient('filechi_db_upgrading') === false ? 'PASS' : 'FAIL') . "\n";
assert(get_transient('filechi_db_upgrading') === false);

echo "\n=== 2. Run twice (Idempotence) ===\n";
$migrated_count_before = count($migrated_actions);
$second_run = FileChi_Activator::maybe_upgrade();
echo 'Second run returned true: ' . ($second_run === true ? 'PASS' : 'FAIL') . "\n";
assert($second_run === true);

echo 'DB version remains ' . FileChi_Activator::DB_VERSION . ': ' . (get_option('filechi_db_version') === FileChi_Activator::DB_VERSION ? 'PASS' : 'FAIL') . "\n";
assert(get_option('filechi_db_version') === FileChi_Activator::DB_VERSION);

echo 'No additional migrations fired on second run: ' . (count($migrated_actions) === $migrated_count_before ? 'PASS' : 'FAIL') . "\n";
assert(count($migrated_actions) === $migrated_count_before);

echo "\n=== 3. Simulate a parallel request (Concurrency Lock) ===\n";
update_option('filechi_db_version', '1.0.0');
set_transient('filechi_db_upgrading', time(), 300);

$parallel_run = FileChi_Activator::maybe_upgrade();
echo 'Parallel run while locked returned false: ' . ($parallel_run === false ? 'PASS' : 'FAIL') . "\n";
assert($parallel_run === false);

echo 'DB version stayed at 1.0.0 while locked: ' . (get_option('filechi_db_version') === '1.0.0' ? 'PASS' : 'FAIL') . "\n";
assert(get_option('filechi_db_version') === '1.0.0');

// Unlock and re-run
delete_transient('filechi_db_upgrading');
$unlocked_run = FileChi_Activator::maybe_upgrade();
echo 'Run after lock release returned true: ' . ($unlocked_run === true ? 'PASS' : 'FAIL') . "\n";
assert($unlocked_run === true);
assert(get_option('filechi_db_version') === FileChi_Activator::DB_VERSION);

echo "\n=== 4. Failure handling and admin notice ===\n";
update_option('filechi_db_version', '1.0.0');
delete_transient('filechi_db_upgrading');

// Add failing migration
add_action('filechi_db_upgrade_1_1_0', function() {
	throw new \RuntimeException('Simulated table failure');
}, 5);

$fail_run = FileChi_Activator::maybe_upgrade();
echo 'Failing upgrade handled without breaking site: ' . ($fail_run === true ? 'PASS' : 'FAIL') . "\n";
echo 'Lock transient cleared even on failure: ' . (get_transient('filechi_db_upgrading') === false ? 'PASS' : 'FAIL') . "\n";
assert(get_transient('filechi_db_upgrading') === false);

$saved_error = get_option('filechi_db_upgrade_error');
echo 'Upgrade error recorded in option: ' . ($saved_error === 'Simulated table failure' ? 'PASS' : 'FAIL') . "\n";
assert($saved_error === 'Simulated table failure');

// Check admin notice rendering
$GLOBALS['current_user_can_override'] = true;
ob_start();
FileChi_Activator::render_upgrade_error_notice();
$notice_html = ob_get_clean();

echo 'Admin notice rendered with error message: ' . (strpos($notice_html, 'Simulated table failure') !== false ? 'PASS' : 'FAIL') . "\n";
assert(strpos($notice_html, 'Simulated table failure') !== false);

echo "\n=== 5. Activation uses the same upgrade routine ===\n";
update_option('filechi_db_version', '1.0.0');
delete_option('filechi_db_upgrade_error');
// Remove the throwing hook by resetting actions for 1_1_0
$GLOBALS['wp_filter']['filechi_db_upgrade_1_1_0'] = array();

FileChi_Activator::activate();
echo 'Activation upgraded DB version: ' . (get_option('filechi_db_version') === FileChi_Activator::DB_VERSION ? 'PASS' : 'FAIL') . "\n";
assert(get_option('filechi_db_version') === FileChi_Activator::DB_VERSION);
echo 'Activation cleared previous upgrade error: ' . (get_option('filechi_db_upgrade_error') === false ? 'PASS' : 'FAIL') . "\n";
assert(get_option('filechi_db_upgrade_error') === false);

echo "\nALL DB UPGRADE TESTS PASSED!\n";
