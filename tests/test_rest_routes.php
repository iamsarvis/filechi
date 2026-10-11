<?php
// MOCK
/**
 * Test: REST API Route Registration, Callbacks, and Handler Invocations
 */

require_once __DIR__ . '/bootstrap.php';
require_once FILECHI_DIR . 'includes/class-filechi-crypto.php';
require_once FILECHI_DIR . 'includes/class-filechi-db.php';
require_once FILECHI_DIR . 'includes/class-filechi-migration.php';
require_once FILECHI_DIR . 'includes/class-filechi-rest.php';

echo "=== 1. Register routes and verify recording ===\n";
$GLOBALS['mock_rest_routes'] = array();
$rest = new FileChi_REST();
$rest->register_routes();

$routes = $GLOBALS['mock_rest_routes'];
assert(!empty($routes), 'No routes were recorded by register_rest_route()');
echo "Registered " . count($routes) . " route definitions.\n";

$request_stub = new WP_REST_Request('GET', '/test');

echo "\n=== 2. Validate is_callable for callbacks and permission_callbacks ===\n";
$endpoint_count = 0;
foreach ($routes as $route_entry) {
	$endpoints = isset($route_entry['args'][0]) && is_array($route_entry['args'][0])
		? $route_entry['args']
		: array($route_entry['args']);

	foreach ($endpoints as $ep) {
		$endpoint_count++;
		if (isset($ep['callback'])) {
			assert(is_callable($ep['callback']), "Route {$route_entry['route']} callback is not callable");
		}
		if (isset($ep['permission_callback'])) {
			assert(is_callable($ep['permission_callback']), "Route {$route_entry['route']} permission_callback is not callable");
			$perm_res = call_user_func($ep['permission_callback'], $request_stub);
			assert(is_bool($perm_res) || is_wp_error($perm_res), "Permission callback did not return bool or WP_Error");
		}
	}
}
echo "Checked {$endpoint_count} endpoints: all callbacks and permission_callbacks are callable.\n";

echo "\n=== 3. Invoke validate_callback and sanitize_callback with 3 arguments ===\n";
$arg_callbacks_tested = 0;
foreach ($routes as $route_entry) {
	$endpoints = isset($route_entry['args'][0]) && is_array($route_entry['args'][0])
		? $route_entry['args']
		: array($route_entry['args']);

	foreach ($endpoints as $ep) {
		if (empty($ep['args']) || !is_array($ep['args'])) {
			continue;
		}

		foreach ($ep['args'] as $arg_name => $arg_config) {
			if (isset($arg_config['validate_callback'])) {
				$cb = $arg_config['validate_callback'];
				echo "Testing validate_callback for '{$arg_name}' on {$route_entry['route']}...\n";
				try {
					call_user_func($cb, '123', $request_stub, $arg_name);
				} catch (\Throwable $e) {
					echo "FATAL: validate_callback for '{$arg_name}' threw " . get_class($e) . ": " . $e->getMessage() . "\n";
					throw $e;
				}
				$arg_callbacks_tested++;
			}

			if (isset($arg_config['sanitize_callback'])) {
				$cb = $arg_config['sanitize_callback'];
				echo "Testing sanitize_callback for '{$arg_name}' on {$route_entry['route']}...\n";
				try {
					call_user_func($cb, '123', $request_stub, $arg_name);
				} catch (\Throwable $e) {
					echo "FATAL: sanitize_callback for '{$arg_name}' threw " . get_class($e) . ": " . $e->getMessage() . "\n";
					throw $e;
				}
				$arg_callbacks_tested++;
			}
		}
	}
}
echo "Tested {$arg_callbacks_tested} argument callbacks with 3 arguments without Throwable.\n";

echo "\n=== 4. Invoke all REST handlers once with request stub and fake DB ===\n";
global $wpdb;
$wpdb = new Mock_WPDB();

$handlers = array(
	'update_provider' => function() use ($rest) {
		$req = new WP_REST_Request('POST', '/filechi/v1/providers/1');
		$req->set_param('id', 1);
		$req->set_param('name', 'Updated Name');
		return $rest->update_provider($req);
	},
	'delete_provider' => function() use ($rest) {
		$req = new WP_REST_Request('DELETE', '/filechi/v1/providers/1');
		$req->set_param('id', 1);
		return $rest->delete_provider($req);
	},
	'set_default_provider' => function() use ($rest) {
		$req = new WP_REST_Request('POST', '/filechi/v1/providers/1/set-default');
		$req->set_param('id', 1);
		return $rest->set_default_provider($req);
	},
	'get_settings' => function() use ($rest) {
		return $rest->get_settings();
	},
	'save_settings' => function() use ($rest) {
		$req = new WP_REST_Request('POST', '/filechi/v1/settings');
		$req->set_param('keep_local_files', 1);
		return $rest->save_settings($req);
	},
	'get_migration_stats' => function() use ($rest) {
		return $rest->get_migration_stats();
	},
	'get_logs' => function() use ($rest) {
		$req = new WP_REST_Request('GET', '/filechi/v1/logs');
		return $rest->get_logs($req);
	},
	'create_provider' => function() use ($rest) {
		$req = new WP_REST_Request('POST', '/filechi/v1/providers');
		$req->set_param('name', 'New Provider');
		$req->set_param('driver', 'sftp');
		return $rest->create_provider($req);
	},
	'list_providers' => function() use ($rest) {
		$req = new WP_REST_Request('GET', '/filechi/v1/providers');
		return $rest->list_providers($req);
	},
);

foreach ($handlers as $name => $fn) {
	echo "Calling handler {$name}... ";
	try {
		$res = $fn();
		echo "OK (returned " . (is_wp_error($res) ? 'WP_Error' : (is_object($res) ? get_class($res) : gettype($res))) . ")\n";
	} catch (\Throwable $e) {
		echo "FAILED with " . get_class($e) . ": " . $e->getMessage() . "\n";
		throw $e;
	}
}

echo "\nALL REST ROUTE TESTS PASSED!\n";
