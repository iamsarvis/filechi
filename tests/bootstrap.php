<?php
// MOCK
/**
 * Test Bootstrap & WordPress Environment Mocks for FileChi
 *
 * Provides shared WordPress and Action Scheduler stubs and the
 * standard provider fixture helper for all tests.
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('FILECHI_DIR')) {
	define('FILECHI_DIR', dirname(__DIR__) . '/');
}
if (!defined('FILECHI_FILE')) {
	define('FILECHI_FILE', FILECHI_DIR . 'filechi.php');
}
if (!defined('FILECHI_VERSION')) {
	define('FILECHI_VERSION', '2.0.0');
}
if (!defined('AUTH_KEY')) {
	define('AUTH_KEY', 'test_auth_key_1234567890abcdefghijklmnopqrstuvwxyz');
}
if (!defined('SECURE_AUTH_KEY')) {
	define('SECURE_AUTH_KEY', 'test_secure_key_1234567890abcdefghijklmnopqrstuvwxyz');
}
if (!defined('LOGGED_IN_SALT')) {
	define('LOGGED_IN_SALT', 'test_salt_1234567890abcdefghijklmnopqrstuvwxyz');
}
if (!defined('NONCE_SALT')) {
	define('NONCE_SALT', 'test_nonce_salt_1234567890abcdefghijklmnopqrstuvwxyz');
}
if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
	define('OBJECT', 'OBJECT');
}

// Global state arrays for mocks
if (!isset($GLOBALS['mock_options'])) {
	$GLOBALS['mock_options'] = array();
}
if (!isset($GLOBALS['mock_post_meta'])) {
	$GLOBALS['mock_post_meta'] = array();
}
if (!isset($GLOBALS['mock_scheduled_actions'])) {
	$GLOBALS['mock_scheduled_actions'] = array();
}
if (!isset($GLOBALS['mock_unscheduled_actions'])) {
	$GLOBALS['mock_unscheduled_actions'] = array();
}
if (!isset($GLOBALS['mock_scheduled_hooks_cleared'])) {
	$GLOBALS['mock_scheduled_hooks_cleared'] = array();
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512) {
		return json_encode($data, $options, $depth);
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct($code = '', $message = '', $data = '') {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing) {
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, $defaults = array()) {
		if (is_object($args)) {
			$args = get_object_vars($args);
		}
		if (is_array($args)) {
			return array_merge($defaults, $args);
		}
		return $defaults;
	}
}

if (!function_exists('absint')) {
	function absint($val) {
		return abs((int) $val);
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key) {
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
	}
}

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($text) {
		return trim(strip_tags((string) $text));
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($text) {
		return stripslashes((string) $text);
	}
}

if (!function_exists('esc_html')) {
	function esc_html($text) {
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default') {
		return $text;
	}
}

if (!function_exists('__')) {
	function __($text, $domain = 'default') {
		return $text;
	}
}

if (!function_exists('_n')) {
	function _n($single, $plural, $number, $domain = 'default') {
		return $number === 1 ? $single : $plural;
	}
}

if (!function_exists('wp_salt')) {
	function wp_salt($scheme = 'auth') {
		return 'test_wp_salt_' . $scheme . '_1234567890abcdef';
	}
}

if (!function_exists('home_url')) {
	function home_url($path = '') {
		return 'https://example.com' . $path;
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg($args, $url = '') {
		return $url . '?' . http_build_query($args);
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1) {
		return parse_url($url, $component);
	}
}

if (!function_exists('wp_normalize_path')) {
	function wp_normalize_path($path) {
		return str_replace('\\', '/', (string) $path);
	}
}

if (!function_exists('current_time')) {
	function current_time($type, $gmt = 0) {
		return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
	}
}

if (!function_exists('wp_upload_dir')) {
	function wp_upload_dir() {
		if (isset($GLOBALS['mock_upload_dir']) && is_array($GLOBALS['mock_upload_dir'])) {
			return $GLOBALS['mock_upload_dir'];
		}
		$basedir = wp_normalize_path(sys_get_temp_dir() . '/filechi_test_uploads');
		return array(
			'path'    => $basedir . '/2026/10',
			'url'     => 'https://example.com/wp-content/uploads/2026/10',
			'subdir'  => '/2026/10',
			'basedir' => $basedir,
			'baseurl' => 'https://example.com/wp-content/uploads',
			'error'   => false,
		);
	}
}

if (!function_exists('wp_check_filetype')) {
	function wp_check_filetype($filename, $mimes = null) {
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		return array('ext' => $ext, 'type' => 'application/octet-stream');
	}
}

if (!function_exists('get_option')) {
	function get_option($option, $default = false) {
		return $GLOBALS['mock_options'][$option] ?? $default;
	}
}

if (!function_exists('update_option')) {
	function update_option($option, $value, $autoload = null) {
		$GLOBALS['mock_options'][$option] = $value;
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($option) {
		unset($GLOBALS['mock_options'][$option]);
		return true;
	}
}

if (!function_exists('add_option')) {
	function add_option($option, $value, $deprecated = '', $autoload = 'yes') {
		$GLOBALS['mock_options'][$option] = $value;
		return true;
	}
}

if (!function_exists('get_post_meta')) {
	function get_post_meta($post_id, $key = '', $single = false) {
		if (empty($key)) {
			return $GLOBALS['mock_post_meta'][$post_id] ?? array();
		}
		return $GLOBALS['mock_post_meta'][$post_id][$key] ?? ($single ? '' : array());
	}
}

if (!function_exists('update_post_meta')) {
	function update_post_meta($post_id, $key, $value) {
		if (!isset($GLOBALS['mock_post_meta'][$post_id])) {
			$GLOBALS['mock_post_meta'][$post_id] = array();
		}
		$GLOBALS['mock_post_meta'][$post_id][$key] = $value;
		return true;
	}
}

if (!function_exists('delete_post_meta')) {
	function delete_post_meta($post_id, $key) {
		unset($GLOBALS['mock_post_meta'][$post_id][$key]);
		return true;
	}
}

if (!function_exists('as_schedule_single_action')) {
	function as_schedule_single_action($time, $hook, $args = array(), $group = '') {
		$GLOBALS['mock_scheduled_actions'][] = compact('time', 'hook', 'args', 'group');
		return 1;
	}
}

if (!function_exists('as_has_scheduled_action')) {
	function as_has_scheduled_action($hook, $args = array(), $group = '') {
		return false;
	}
}

if (!function_exists('as_unschedule_all_actions')) {
	function as_unschedule_all_actions($hook, $args = array(), $group = '') {
		$GLOBALS['mock_unscheduled_actions'][] = compact('hook', 'args', 'group');
	}
}

if (!function_exists('wp_clear_scheduled_hook')) {
	function wp_clear_scheduled_hook($hook) {
		$GLOBALS['mock_scheduled_hooks_cleared'][] = $hook;
	}
}

if (!function_exists('add_action')) {
	function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
}

if (!function_exists('add_filter')) {
	function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
}

if (!function_exists('do_action')) {
	function do_action($tag, ...$args) {}
}

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args) {
		return $value;
	}
}

if (!function_exists('is_multisite')) {
	function is_multisite() {
		return false;
	}
}

if (!class_exists('Mock_WPDB')) {
	class Mock_WPDB {
		public $prefix          = 'wp_';
		public $options         = 'wp_options';
		public $postmeta        = 'wp_postmeta';
		public $table_providers = 'wp_filechi_providers';
		public $table_logs      = 'wp_filechi_logs';
		public $queries         = array();
		public $counts          = array();
		public $deleted         = array();

		public function prepare($query, ...$args) {
			foreach ($args as $arg) {
				$val   = is_numeric($arg) ? $arg : "'" . addslashes((string) $arg) . "'";
				$query = preg_replace('/%[dfs]/', $val, $query, 1);
			}
			return $query;
		}

		public function get_var($query) {
			$this->queries[] = $query;
			if (strpos($query, 'COUNT(*)') !== false) {
				if (preg_match("/meta_value = '?(\d+)'?/", $query, $m)) {
					$pid = (int) $m[1];
					return $this->counts[$pid] ?? 0;
				}
			}
			if (strpos($query, 'SELECT is_default') !== false) {
				return 0;
			}
			return 0;
		}

		public function esc_like($text) {
			return addcslashes((string) $text, '_%\\');
		}

		public function query($sql) {
			$this->queries[] = $sql;
			return true;
		}

		public function delete($table, $where, $format) {
			$id = $where['id'];
			$this->deleted[] = $id;
			return 1;
		}

		public $get_row_override = null;
		public $on_insert        = null;
		public $on_update        = null;
		public $insert_id        = 1;

		public function get_row($query, $output = 'ARRAY_A') {
			$this->queries[] = $query;
			if ($this->get_row_override !== null) {
				return $this->get_row_override;
			}
			return null;
		}

		public function insert($table, $data, $format = null) {
			$vals = array_map(function($v) {
				return is_null($v) ? 'NULL' : (is_scalar($v) ? (string) $v : json_encode($v));
			}, $data);
			$this->queries[] = "INSERT INTO $table (" . implode(', ', array_keys($data)) . ") VALUES ('" . implode("', '", $vals) . "')";
			if (is_callable($this->on_insert)) {
				call_user_func($this->on_insert, $table, $data);
			}
			return 1;
		}

		public function update($table, $data, $where, $data_format = null, $where_format = null) {
			$this->queries[] = "UPDATE $table SET ...";
			if (is_callable($this->on_update)) {
				call_user_func($this->on_update, $table, $data, $where);
			}
			return 1;
		}

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}
	}
}

if (!function_exists('dbDelta')) {
	function dbDelta($queries = '') {
		return array();
	}
}

global $wpdb;
if (!isset($wpdb)) {
	$wpdb = new Mock_WPDB();
}

/**
 * Returns a provider fixture row matching the real shape of FileChi_DB.
 * Columns: id, name, driver, settings, is_default.
 *
 * @param int          $id Provider ID.
 * @param string       $driver Storage driver ('sftp', 'ftps', 's3').
 * @param array|string $settings Configuration settings.
 * @param string       $name Provider name.
 * @param int          $is_default Whether provider is default.
 * @return array
 */
function filechi_create_test_provider($id = 1, $driver = 'sftp', $settings = array(), $name = 'Test Provider', $is_default = 1) {
	if (is_string($settings)) {
		$settings = json_decode($settings, true) ?: array();
	}
	return array(
		'id'         => (int) $id,
		'name'       => (string) $name,
		'driver'     => (string) $driver,
		'settings'   => $settings,
		'is_default' => (int) $is_default,
	);
}

if (!class_exists('WP_REST_Controller')) {
	class WP_REST_Controller {
		protected $namespace = '';
		protected $rest_base = '';
	}
}

if (!class_exists('WP_REST_Response')) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct($data = null, $status = 200) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

if (!function_exists('rest_ensure_response')) {
	function rest_ensure_response($response) {
		if ($response instanceof WP_REST_Response) {
			return $response;
		}
		return new WP_REST_Response($response);
	}
}

if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request {
		protected $params = array();
		public function __construct($method = 'GET', $route = '') {}
		public function set_param($key, $value) { $this->params[$key] = $value; }
		public function get_params() { return $this->params; }
		public function get_param($key) { return $this->params[$key] ?? null; }
		public function get_json_params() { return $this->params; }
	}
}

require_once FILECHI_DIR . 'includes/class-filechi-activator.php';
