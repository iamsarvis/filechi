<?php
// REAL
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

echo "\nALL DECRYPTION FAILURE TESTS PASSED!\n";
