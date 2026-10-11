<?php
// MOCK
/**
 * Test: Handling of Providers Whose Secrets Cannot Be Decrypted (Item 2.3)
 * Uses REAL shape fixtures (provider rows use key 'driver', never 'type').
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/storage/interface-filechi-storage.php';
require_once FILECHI_DIR . 'includes/storage/class-filechi-storage-factory.php';
require_once FILECHI_DIR . 'includes/class-filechi-media.php';

global $wpdb;
$wpdb = new Mock_WPDB();

echo "=== 1. decrypt_settings() clears secret and flags _decryption_failed on invalid ciphertext ===\n";
// Create a malformed encrypted string that will fail decryption
$corrupted_secret = 'invalid:tampered:ciphertext';
$settings_with_bad_secret = array(
	'host'     => 'sftp.example.com',
	'username' => 'user1',
	'password' => $corrupted_secret,
);

$decrypted = FileChi_DB::decrypt_settings($settings_with_bad_secret);
assert(isset($decrypted['_decryption_failed']) && $decrypted['_decryption_failed'] === true, '_decryption_failed flag must be true');
assert(isset($decrypted['password_decryption_failed']) && $decrypted['password_decryption_failed'] === true, 'password_decryption_failed flag must be true');
assert($decrypted['password'] === '', 'Ciphertext must NOT be left in the secret field when decryption fails');
echo "[+] decrypt_settings() cleared ciphertext and set failure flags: PASS\n";

echo "\n=== 2. FileChi_Storage_Factory::create() returns null when _decryption_failed is set ===\n";
FileChi_Storage_Factory::clear_instances();

$provider_bad = filechi_create_test_provider(
	10,
	'sftp',
	$decrypted, // contains _decryption_failed => true
	'Broken SFTP Provider'
);

$driver = FileChi_Storage_Factory::create($provider_bad);
assert($driver === null, 'Factory MUST return null when _decryption_failed is set');
echo "[+] Factory returned null for decrypt-failed provider: PASS\n";

echo "\n=== 3. offload_attachment() records failure 'credentials cannot be decrypted' ===\n";
// Setup mock attachment
$attachment_id = 42;
$GLOBALS['mock_post_meta'][$attachment_id] = array(
	'_wp_attached_file' => '2026/10/photo.jpg',
);

$offload_result = FileChi_Media::offload_attachment($attachment_id, $provider_bad);
assert($offload_result === false, 'offload_attachment must return false');

// Verify that log_transfer recorded the failure
$logged_failure = false;
foreach ($wpdb->queries as $q) {
	if (stripos($q, 'filechi_logs') !== false && stripos($q, 'credentials cannot be decrypted') !== false) {
		$logged_failure = true;
		break;
	}
}
assert($logged_failure, "Transfer log must record failure with 'credentials cannot be decrypted'");
echo "[+] offload_attachment logged clear failure: PASS\n";

echo "\n=== 4. update_provider() drops _-prefixed and *_decryption_failed keys ===\n";
// Setup existing row in mock DB
$existing_encrypted_pass = FileChi_Crypto::encrypt('correct_password');
$existing_settings_json  = wp_json_encode(array(
	'host'     => 'ftp.example.com',
	'username' => 'ftpuser',
	'password' => $existing_encrypted_pass,
));

$wpdb->get_row_override = array(
	'id'         => 5,
	'name'       => 'Existing Provider',
	'driver'     => 'sftp',
	'is_default' => 0,
	'settings'   => $existing_settings_json,
);

// Submit form data that browser posts back containing runtime failure keys and masked secrets
$submitted_data = array(
	'name'     => 'Updated Provider',
	'driver'   => 'sftp',
	'settings' => array(
		'host'                       => 'ftp2.example.com',
		'username'                   => 'ftpuser2',
		'password'                   => '********', // masked, should not overwrite
		'_decryption_failed'         => true,
		'password_decryption_failed' => true,
		'_custom_temp_key'           => 'temp_val',
	),
);

// Override Mock_WPDB to capture the update
$last_updated_settings = null;
$wpdb->on_update = function($table, $data, $where) use (&$last_updated_settings) {
	if (isset($data['settings'])) {
		$last_updated_settings = json_decode($data['settings'], true);
	}
};

FileChi_DB::update_provider(5, $submitted_data);

assert(is_array($last_updated_settings), 'Settings must be updated as array');
assert(!isset($last_updated_settings['_decryption_failed']), '_decryption_failed must be dropped');
assert(!isset($last_updated_settings['password_decryption_failed']), 'password_decryption_failed must be dropped');
assert(!isset($last_updated_settings['_custom_temp_key']), '_-prefixed keys must be dropped');
assert($last_updated_settings['host'] === 'ftp2.example.com', 'Valid submitted host must be updated');
assert($last_updated_settings['password'] === $existing_encrypted_pass, 'Masked password must preserve existing encrypted secret');
echo "[+] update_provider() dropped _-prefixed and *_decryption_failed keys: PASS\n";

echo "\n=== 5. insert_provider() drops _-prefixed and *_decryption_failed keys ===\n";
$last_inserted_settings = null;
$wpdb->on_insert = function($table, $data) use (&$last_inserted_settings) {
	if (isset($data['settings'])) {
		$last_inserted_settings = json_decode($data['settings'], true);
	}
};

FileChi_DB::insert_provider(array(
	'name'     => 'New Provider',
	'driver'   => 'sftp',
	'settings' => array(
		'host'                         => 'sftp.new.com',
		'username'                     => 'user_new',
		'_decryption_failed'           => 1,
		'secret_key_decryption_failed' => true,
		'_csrf_token'                  => 'xyz123',
	),
));

assert(is_array($last_inserted_settings), 'Settings must be inserted as array');
assert(!isset($last_inserted_settings['_decryption_failed']), '_decryption_failed must be dropped on insert');
assert(!isset($last_inserted_settings['secret_key_decryption_failed']), '*_decryption_failed must be dropped on insert');
assert(!isset($last_inserted_settings['_csrf_token']), '_-prefixed keys must be dropped on insert');
assert($last_inserted_settings['host'] === 'sftp.new.com', 'Valid settings must be kept');
echo "[+] insert_provider() dropped _-prefixed and *_decryption_failed keys: PASS\n";

echo "\n=== 6. Recovery: updating provider with new secret replaces undecryptable credentials ===\n";
// Setup provider whose stored secret cannot be decrypted (corrupted or previous encryption scheme)
$old_undecryptable_secret = 'invalid:unreadable:legacy_ciphertext';
$provider_recov_id        = 7;
$stored_settings_json     = wp_json_encode(array(
	'host'     => 'sftp.example.com',
	'username' => 'sftpuser',
	'password' => $old_undecryptable_secret,
));

$wpdb->get_row_override = array(
	'id'         => $provider_recov_id,
	'name'       => 'Corrupted Provider',
	'driver'     => 'sftp',
	'is_default' => 0,
	'settings'   => $stored_settings_json,
);

// Verify before recovery: get_provider flags decryption failure and Factory refuses creation
$broken_provider_decrypted = FileChi_DB::get_provider($provider_recov_id, true);
assert(is_array($broken_provider_decrypted), 'Broken provider row must be loaded');
assert(!empty($broken_provider_decrypted['settings']['_decryption_failed']), '_decryption_failed must be set before recovery');
FileChi_Storage_Factory::clear_instances();
assert(FileChi_Storage_Factory::create($broken_provider_decrypted) === null, 'Driver creation must fail before recovery');

// Submit new valid secret through real FileChi_DB::update_provider()
$new_secret_plaintext = 'new_strong_password_2026';
$submitted_recovery   = array(
	'name'     => 'Corrupted Provider',
	'driver'   => 'sftp',
	'settings' => array(
		'host'     => 'sftp.example.com',
		'username' => 'sftpuser',
		'password' => $new_secret_plaintext,
	),
);

$updated_db_settings = null;
$wpdb->on_update = function($table, $data, $where) use (&$updated_db_settings, &$wpdb, $provider_recov_id) {
	if (isset($data['settings'])) {
		$updated_db_settings = json_decode($data['settings'], true);
		// Update DB state for subsequent queries
		$wpdb->get_row_override = array(
			'id'         => $provider_recov_id,
			'name'       => 'Corrupted Provider',
			'driver'     => 'sftp',
			'is_default' => 0,
			'settings'   => $data['settings'],
		);
	}
};

$update_success = FileChi_DB::update_provider($provider_recov_id, $submitted_recovery);
assert($update_success === true, 'update_provider must return true on recovery');

// 1. Verify new value is stored encrypted, not plaintext or old corrupted secret
assert(is_array($updated_db_settings), 'Updated settings must be saved');
assert($updated_db_settings['password'] !== $new_secret_plaintext, 'New secret must be stored encrypted at rest');
assert($updated_db_settings['password'] !== $old_undecryptable_secret, 'Old corrupted ciphertext must be replaced');
assert(FileChi_Crypto::decrypt($updated_db_settings['password']) === $new_secret_plaintext, 'Stored ciphertext must decrypt to new secret');

// 2. Verify _decryption_failed is gone from the response
$recovered_provider_rest = FileChi_DB::get_provider($provider_recov_id, false);
assert(is_array($recovered_provider_rest), 'Provider must be retrieved');
assert(empty($recovered_provider_rest['settings']['_decryption_failed']), '_decryption_failed must be gone from REST response');
assert(empty($recovered_provider_rest['settings']['password_decryption_failed']), 'password_decryption_failed must be gone');
assert($recovered_provider_rest['settings']['password'] === '********', 'Password must be redacted for REST response');

// 3. Verify driver can now be created successfully
$recovered_provider_full = FileChi_DB::get_provider($provider_recov_id, true);
assert(empty($recovered_provider_full['settings']['_decryption_failed']), '_decryption_failed must be gone from decrypted settings');
assert($recovered_provider_full['settings']['password'] === $new_secret_plaintext, 'Decrypted password must match new secret');

FileChi_Storage_Factory::clear_instances();
$recovered_driver = FileChi_Storage_Factory::create($recovered_provider_full);
assert($recovered_driver instanceof FileChi_Storage_SFTP, 'Storage driver must be successfully instantiated after recovery');
echo "[+] Recovery of provider whose credentials could not be decrypted: PASS\n";

echo "\nALL DECRYPTION FAILURE TESTS PASSED!\n";
