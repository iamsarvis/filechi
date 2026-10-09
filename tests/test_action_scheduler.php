<?php
// MOCK
/**
 * Test: Action Scheduler bundling and elimination of WP-Cron fallbacks
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== 1. Action Scheduler Bundled File Verification ===\n";
$as_file = FILECHI_DIR . 'includes/vendor/woocommerce/action-scheduler/action-scheduler.php';
$exists  = file_exists($as_file);
echo "Action Scheduler bootstrap exists at $as_file: " . ($exists ? 'PASS' : 'FAIL') . "\n";
assert($exists === true);

$as_license  = FILECHI_DIR . 'includes/vendor/woocommerce/action-scheduler/license.txt';
$has_license = file_exists($as_license);
echo "Action Scheduler license exists at $as_license: " . ($has_license ? 'PASS' : 'FAIL') . "\n";
assert($has_license === true);

echo "\n=== 2. FileChi_Media::schedule_retry uses Action Scheduler ===\n";
require_once FILECHI_DIR . 'includes/class-filechi-media.php';
$GLOBALS['mock_scheduled_actions'] = array();
FileChi_Media::schedule_retry(99);
$scheduled_count = count($GLOBALS['mock_scheduled_actions']);
echo "Scheduled actions count: $scheduled_count -> " . ($scheduled_count === 1 ? 'PASS' : 'FAIL') . "\n";
assert($scheduled_count === 1);
assert($GLOBALS['mock_scheduled_actions'][0]['hook'] === 'filechi_retry_attachment_offload');
assert($GLOBALS['mock_scheduled_actions'][0]['args']['attachment_id'] === 99);

echo "\n=== 3. FileChi_Migration::start_migration uses Action Scheduler ===\n";
require_once FILECHI_DIR . 'includes/class-filechi-migration.php';
FileChi_Migration::start_migration();
$total_count = count($GLOBALS['mock_scheduled_actions']);
echo "Total scheduled actions count: $total_count -> " . ($total_count === 2 ? 'PASS' : 'FAIL') . "\n";
assert($total_count === 2);
assert($GLOBALS['mock_scheduled_actions'][1]['hook'] === 'filechi_process_migration_batch');

echo "\n=== 4. FileChi_Migration::pause_migration uses as_unschedule_all_actions ===\n";
$GLOBALS['mock_unscheduled_actions'] = array();
FileChi_Migration::pause_migration();
$unscheduled_count = count($GLOBALS['mock_unscheduled_actions']);
echo "Unscheduled actions count: $unscheduled_count -> " . ($unscheduled_count === 1 ? 'PASS' : 'FAIL') . "\n";
assert($unscheduled_count === 1);
assert($GLOBALS['mock_unscheduled_actions'][0]['hook'] === 'filechi_process_migration_batch');

echo "\nALL ACTION SCHEDULER TESTS PASSED!\n";
