<?php
// MOCK
/**
 * Test: Provider Deletion Guard against Active Media References
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';

global $wpdb;
$wpdb = new Mock_WPDB();

echo "=== 1. Provider with 0 active attachments can be deleted ===\n";
$provider1 = filechi_create_test_provider(1, 'sftp', array(), 'Unused Provider', 0);
$wpdb->counts[1] = 0;
$result = FileChi_DB::delete_provider(1);
echo "Deletion result for provider 1 (0 attachments): " . ($result === true ? "PASS" : "FAIL") . "\n";
assert($result === true);
assert(in_array(1, $wpdb->deleted));

echo "\n=== 2. Provider with 42 active attachments REFUSES deletion ===\n";
$provider2 = filechi_create_test_provider(2, 'sftp', array(), 'Active Provider', 1);
$wpdb->counts[2] = 42;
$result2 = FileChi_DB::delete_provider(2);
echo "Deletion refused for provider 2 (42 attachments): " . (is_wp_error($result2) ? "PASS" : "FAIL") . "\n";
assert(is_wp_error($result2));
assert($result2->get_error_code() === 'provider_in_use');
assert(($result2->get_error_data()['status'] ?? 0) === 409);
assert(strpos($result2->get_error_message(), '42 media attachments') !== false);
assert(!in_array(2, $wpdb->deleted));

echo "\n=== 3. Provider with 1 active attachment has correct singular message ===\n";
$provider3 = filechi_create_test_provider(3, 's3', array(), 'Single Attachment Provider', 0);
$wpdb->counts[3] = 1;
$result3 = FileChi_DB::delete_provider(3);
assert(is_wp_error($result3));
echo "Singular Error Message: " . $result3->get_error_message() . "\n";
assert(strpos($result3->get_error_message(), '1 media attachment currently depends') !== false);

echo "\nALL PROVIDER DELETION GUARD TESTS PASSED!\n";
