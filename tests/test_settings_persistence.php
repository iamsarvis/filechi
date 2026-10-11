<?php
// MOCK
/**
 * Test: Settings Persistence and Default Settings Source of Truth
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
require_once FILECHI_DIR . 'includes/class-filechi-rest.php';

echo "=== 1. Verify FileChi_Activator::default_settings() Single Source of Truth ===\n";
$defaults = FileChi_Activator::default_settings();
assert(is_array($defaults), 'default_settings must return an array');
assert($defaults['keep_local_files'] === 1, 'keep_local_files default must be 1');
assert($defaults['keep_remote_on_delete'] === 0, 'keep_remote_on_delete default must be 0');
assert($defaults['remote_path_format'] === 'basedir', 'remote_path_format default must be basedir');
assert($defaults['url_replacement'] === 1, 'url_replacement default must be 1');
assert($defaults['wc_signed_downloads'] === 1, 'wc_signed_downloads default must be 1');
assert($defaults['wc_download_expiry'] === 900, 'wc_download_expiry default must be 900');
assert($defaults['migration_batch_size'] === 10, 'migration_batch_size default must be 10');
assert($defaults['delete_data_on_uninstall'] === 0, 'delete_data_on_uninstall default must be 0');
echo "[+] Activator defaults verified: PASS\n";

echo "\n=== 2. get_settings() returns identical defaults ===\n";
$GLOBALS['mock_options'] = array(); // No saved options
$rest = new FileChi_REST();
$res = $rest->get_settings();
$rest_settings = $res->get_data();
assert($rest_settings === $defaults, 'get_settings() with no stored options must exactly match Activator defaults');
echo "[+] get_settings() matches Activator defaults: PASS\n";

echo "\n=== 3. save_settings() merges over stored option and preserves delete_data_on_uninstall ===\n";
// Setup stored options with delete_data_on_uninstall = 1 and custom key
$GLOBALS['mock_options'] = array(
	'filechi_settings' => array(
		'keep_local_files'         => 0,
		'delete_data_on_uninstall' => 1,
		'remote_path_format'       => 'basedir',
		'custom_preserved_key'     => 'keep_me',
	),
);

// Submit partial save without delete_data_on_uninstall or remote_path_format
$save_payload = array(
	'keep_local_files' => 1,
	'url_replacement'  => 1,
);
$res_save = $rest->save_settings($save_payload);
$saved_settings = get_option('filechi_settings');

assert($saved_settings['delete_data_on_uninstall'] === 1, 'delete_data_on_uninstall must NOT be lost on save');
assert($saved_settings['remote_path_format'] === 'basedir', 'remote_path_format must NOT be lost on save');
assert($saved_settings['custom_preserved_key'] === 'keep_me', 'Stored options must be merged, never rebuilt from scratch');
assert($saved_settings['keep_local_files'] === 1, 'Submitted key must be updated');
echo "[+] save_settings() preserves unsubmitted keys: PASS\n";

echo "\n=== 4. save_settings() clamps wc_download_expiry and migration_batch_size ===\n";
// Test low bounds
$res_low = $rest->save_settings(array(
	'wc_download_expiry'   => 10,  // below 60 -> clamp to 60
	'migration_batch_size' => 0,   // below 1 -> clamp to 1
));
$saved_low = get_option('filechi_settings');
assert($saved_low['wc_download_expiry'] === 60, 'wc_download_expiry below 60 must clamp to 60');
assert($saved_low['migration_batch_size'] === 1, 'migration_batch_size below 1 must clamp to 1');

// Test high bounds
$res_high = $rest->save_settings(array(
	'wc_download_expiry'   => 999999, // above 86400 -> clamp to 86400
	'migration_batch_size' => 999,    // above 50 -> clamp to 50
));
$saved_high = get_option('filechi_settings');
assert($saved_high['wc_download_expiry'] === 86400, 'wc_download_expiry above 86400 must clamp to 86400');
assert($saved_high['migration_batch_size'] === 50, 'migration_batch_size above 50 must clamp to 50');
echo "[+] Clamping 60-86400 and 1-50 verified: PASS\n";

echo "\n=== 5. Existing installs keep their stored keep_local_files ===\n";
// If an install already stored keep_local_files = 0, activator does not overwrite it
$GLOBALS['mock_options'] = array(
	'filechi_settings' => array(
		'keep_local_files' => 0,
	),
);
FileChi_Activator::activate();
$after_activation = get_option('filechi_settings');
assert($after_activation['keep_local_files'] === 0, 'Existing install settings must not be overwritten by activate()');
echo "[+] Existing installs keep stored values: PASS\n";

echo "\nALL SETTINGS PERSISTENCE TESTS PASSED!\n";
