<?php
// MOCK
/**
 * Test: Admin Localized Data & Protected Path Notice Behavior
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-admin.php';

echo "=== 1. Test get_admin_data() structure without WooCommerce ===\n";
$admin = new FileChi_Admin();
$data = $admin->get_admin_data();

assert(is_array($data), 'get_admin_data must return an array');
assert(isset($data['version']), 'version must be present');
assert($data['version'] === FILECHI_VERSION, 'version must match FILECHI_VERSION');
assert(isset($data['woocommerce']), 'woocommerce data must be present');
assert(is_array($data['woocommerce']), 'woocommerce must be an array');
assert($data['woocommerce']['active'] === false, 'WooCommerce active should be false when not loaded');
assert($data['woocommerce']['version'] === '', 'WooCommerce version should be empty when not loaded');
assert($data['woocommerce']['hpos_enabled'] === false, 'HPOS should be false when not loaded');
assert(!empty($data['restUrl']), 'restUrl must be non-empty');
assert(!empty($data['nonce']), 'nonce must be non-empty');
echo "PASS: Localized data structure verified when WooCommerce is absent.\n";

echo "\n=== 2. Test protected path admin notice render ===\n";
$GLOBALS['mock_current_user_caps']['manage_options'] = true;
update_option('filechi_protected_path_missing_notice', array(
	'attachment_id' => 123,
	'provider_name' => 'Test SFTP',
));

ob_start();
$admin->render_admin_notices();
$output = ob_get_clean();

assert(strpos($output, 'filechi-protected-notice') !== false, 'Notice element ID must be rendered');
assert(strpos($output, 'Test SFTP') !== false, 'Provider name must be rendered in notice');
assert(strpos($output, 'attachment #123') !== false, 'Attachment ID must be rendered in notice');
assert(strpos($output, 'filechi_dismiss_protected_notice') !== false, 'Inline script must reference dismissal action');
echo "PASS: Admin notice renders with inline script and dismissal action.\n";

echo "\n=== 3. Test protected path notice AJAX dismissal ===\n";
$_POST['nonce'] = wp_create_nonce('filechi_admin_nonce');
$_REQUEST['nonce'] = $_POST['nonce'];

$admin->ajax_dismiss_protected_notice();
assert(get_option('filechi_protected_path_missing_notice') === false, 'Notice option must be deleted after dismissal');
echo "PASS: AJAX handler successfully dismissed notice option.\n";

echo "\n=== 4. Test automatic notice deletion on provider save with protected_path ===\n";
global $wpdb;
$wpdb = new Mock_WPDB();

// A. Re-create the notice option
update_option('filechi_protected_path_missing_notice', array('attachment_id' => 456));
assert(get_option('filechi_protected_path_missing_notice') !== false, 'Notice option must be set');

// B. Save an S3 provider with protected_path: should NOT delete the notice
FileChi_DB::insert_provider(array(
	'name'     => 'S3 Storage',
	'driver'   => 's3',
	'settings' => array('protected_path' => '/some/path', 'bucket' => 'my-bucket'),
));
assert(get_option('filechi_protected_path_missing_notice') !== false, 'S3 provider save must not delete notice');

// C. Save an SFTP provider with empty protected_path: should NOT delete the notice
FileChi_DB::insert_provider(array(
	'name'     => 'SFTP Storage No Prot',
	'driver'   => 'sftp',
	'settings' => array('host' => 'example.com', 'protected_path' => ''),
));
assert(get_option('filechi_protected_path_missing_notice') !== false, 'SFTP provider with empty protected_path must not delete notice');

// D. Save an SFTP provider with non-empty protected_path: MUST delete the notice
FileChi_DB::insert_provider(array(
	'name'     => 'SFTP Storage With Prot',
	'driver'   => 'sftp',
	'settings' => array('host' => 'example.com', 'protected_path' => '/var/protected'),
));
assert(get_option('filechi_protected_path_missing_notice') === false, 'SFTP provider with protected_path must auto-delete notice');

// E. Test update_provider with non-empty protected_path: MUST delete the notice
update_option('filechi_protected_path_missing_notice', array('attachment_id' => 789));
assert(get_option('filechi_protected_path_missing_notice') !== false, 'Notice option must be set before update');

$wpdb->get_row_override = array(
	'id'         => 1,
	'name'       => 'Existing FTPS',
	'driver'     => 'ftps',
	'is_default' => 0,
	'settings'   => json_encode(array('host' => 'ftps.example.com')),
);

FileChi_DB::update_provider(1, array(
	'driver'   => 'ftps',
	'settings' => array('host' => 'ftps.example.com', 'protected_path' => '/ftps/protected'),
));
assert(get_option('filechi_protected_path_missing_notice') === false, 'FTPS update_provider with protected_path must auto-delete notice');

echo "PASS: Automatic deletion of notice verified across provider insert and update.\n";

echo "\nALL ADMIN UI DATA TESTS PASSED!\n";
