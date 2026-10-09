<?php
/**
 * FileChi REST API Controller
 *
 * Exposes secure REST endpoints for the admin panel:
 * Provider CRUD, Test Connection (unsaved inputs), Settings, Migration, and Logs.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_REST extends WP_REST_Controller {

	/**
	 * Namespace for FileChi REST API.
	 */
	const REST_NAMESPACE = 'filechi/v1';

	/**
	 * Registers all FileChi REST API routes.
	 */
	public function register_routes() {
		// Providers CRUD
		register_rest_route(
			self::REST_NAMESPACE,
			'/providers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_providers'),
					'permission_callback' => array($this, 'check_admin_permissions'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'create_provider'),
					'permission_callback' => array($this, 'check_admin_permissions'),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/providers/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array($this, 'update_provider'),
					'permission_callback' => array($this, 'check_admin_permissions'),
					'args'                => array('id' => array('validate_callback' => 'is_numeric')),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array($this, 'delete_provider'),
					'permission_callback' => array($this, 'check_admin_permissions'),
					'args'                => array('id' => array('validate_callback' => 'is_numeric')),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/providers/(?P<id>\d+)/set-default',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'set_default_provider'),
				'permission_callback' => array($this, 'check_admin_permissions'),
				'args'                => array('id' => array('validate_callback' => 'is_numeric')),
			)
		);

		// Test connection against unsaved form values
		register_rest_route(
			self::REST_NAMESPACE,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'test_connection'),
				'permission_callback' => array($this, 'check_admin_permissions'),
			)
		);

		// Settings
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'get_settings'),
					'permission_callback' => array($this, 'check_admin_permissions'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'save_settings'),
					'permission_callback' => array($this, 'check_admin_permissions'),
				),
			)
		);

		// Migration controls and stats
		register_rest_route(
			self::REST_NAMESPACE,
			'/migration/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_migration_stats'),
				'permission_callback' => array($this, 'check_admin_permissions'),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/migration/(?P<action>start|pause|retry)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'handle_migration_action'),
				'permission_callback' => array($this, 'check_admin_permissions'),
			)
		);

		// Logs
		register_rest_route(
			self::REST_NAMESPACE,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_logs'),
				'permission_callback' => array($this, 'check_admin_permissions'),
			)
		);
	}

	/**
	 * Permission check: user must have manage_options capability.
	 *
	 * @return bool
	 */
	public function check_admin_permissions() {
		return current_user_can('manage_options');
	}

	/**
	 * Returns list of providers.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_providers($request) {
		$providers = FileChi_DB::get_providers(false);
		return rest_ensure_response($providers);
	}

	/**
	 * Creates a new provider.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_provider($request) {
		$params = $request->get_json_params();

		if (empty($params['name']) || empty($params['driver'])) {
			return new WP_Error('invalid_data', __('Provider name and driver are required.', 'filechi'), array('status' => 400));
		}

		$id = FileChi_DB::insert_provider($params);
		if (is_wp_error($id)) {
			return $id;
		}
		if (!$id) {
			return new WP_Error('db_error', __('Failed to create provider record.', 'filechi'), array('status' => 500));
		}

		return rest_ensure_response(FileChi_DB::get_provider($id, false));
	}

	/**
	 * Updates a provider.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_provider($request) {
		$id     = absint($request->get_param('id'));
		$params = $request->get_json_params();

		$success = FileChi_DB::update_provider($id, $params);
		if (is_wp_error($success)) {
			return $success;
		}
		if (!$success) {
			return new WP_Error('db_error', __('Failed to update provider record.', 'filechi'), array('status' => 500));
		}

		return rest_ensure_response(FileChi_DB::get_provider($id, false));
	}

	/**
	 * Deletes a provider.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_provider($request) {
		$id     = absint($request->get_param('id'));
		$result = FileChi_DB::delete_provider($id);

		if (is_wp_error($result)) {
			return $result;
		}

		if (!$result) {
			return new WP_Error('db_error', __('Failed to delete provider.', 'filechi'), array('status' => 500));
		}

		return rest_ensure_response(array('deleted' => true, 'id' => $id));
	}

	/**
	 * Sets default provider.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function set_default_provider($request) {
		$id = absint($request->get_param('id'));
		FileChi_DB::set_default_provider($id);
		return rest_ensure_response(array('success' => true, 'id' => $id));
	}

	/**
	 * Real-time connection test against UNSAVED form values.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection($request) {
		$params = $request->get_json_params();

		if (empty($params['driver'])) {
			return new WP_Error('missing_driver', __('Storage driver is required.', 'filechi'), array('status' => 400));
		}

		$driver_type = sanitize_key($params['driver']);
		$settings    = is_array($params['settings'] ?? null) ? $params['settings'] : array();

		// If existing provider ID is provided and secret was masked, retrieve existing stored secret
		if (!empty($params['id'])) {
			$existing = FileChi_DB::get_provider($params['id'], true);
			if ($existing) {
				$sensitive = array('password', 'secret_key', 'private_key', 'passphrase');
				foreach ($sensitive as $k) {
					if (isset($settings[$k]) && ($settings[$k] === '********' || $settings[$k] === '')) {
						$settings[$k] = $existing['settings'][$k] ?? '';
					}
				}
			}
		}

		$driver = null;

		switch ($driver_type) {
			case 'sftp':
				require_once dirname(__FILE__) . '/storage/class-filechi-storage-sftp.php';
				$driver = new FileChi_Storage_SFTP($settings);
				break;

			case 'ftps':
				require_once dirname(__FILE__) . '/storage/class-filechi-storage-ftps.php';
				$driver = new FileChi_Storage_FTPS($settings);
				break;

			case 's3':
				require_once dirname(__FILE__) . '/storage/class-filechi-storage-s3.php';
				$driver = new FileChi_Storage_S3($settings);
				break;

			default:
				return new WP_Error('invalid_driver', __('Unsupported storage driver.', 'filechi'), array('status' => 400));
		}

		$result = $driver->test_connection();
		return rest_ensure_response($result);
	}

	/**
	 * Returns current settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$defaults = array(
			'keep_local_files'      => 0,
			'keep_remote_on_delete' => 0,
			'url_replacement'       => 1,
			'wc_signed_downloads'   => 1,
			'wc_download_expiry'    => 900,
			'migration_batch_size'  => 10,
		);
		$settings = wp_parse_args(get_option('filechi_settings', array()), $defaults);
		return rest_ensure_response($settings);
	}

	/**
	 * Saves settings.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function save_settings($request) {
		$params   = $request->get_json_params();
		$settings = array(
			'keep_local_files'      => !empty($params['keep_local_files']) ? 1 : 0,
			'keep_remote_on_delete' => !empty($params['keep_remote_on_delete']) ? 1 : 0,
			'url_replacement'       => !empty($params['url_replacement']) ? 1 : 0,
			'wc_signed_downloads'   => !empty($params['wc_signed_downloads']) ? 1 : 0,
			'wc_download_expiry'    => absint($params['wc_download_expiry'] ?? 900) ?: 900,
			'migration_batch_size'  => min(50, max(1, absint($params['migration_batch_size'] ?? 10))),
		);

		update_option('filechi_settings', $settings);
		return rest_ensure_response($settings);
	}

	/**
	 * Retrieves live migration statistics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_migration_stats() {
		$stats           = FileChi_DB::get_migration_stats();
		$stats['status'] = get_option('filechi_migration_status', 'stopped');
		return rest_ensure_response($stats);
	}

	/**
	 * Handles migration actions (start, pause, retry).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_migration_action($request) {
		$action = $request->get_param('action');

		switch ($action) {
			case 'start':
				FileChi_Migration::start_migration();
				break;
			case 'pause':
				FileChi_Migration::pause_migration();
				break;
			case 'retry':
				FileChi_Migration::retry_failed();
				break;
		}

		return $this->get_migration_stats();
	}

	/**
	 * Retrieves paginated transfer logs.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_logs($request) {
		$limit  = min(100, max(5, absint($request->get_param('limit') ?: 20)));
		$offset = absint($request->get_param('offset') ?: 0);
		$status = sanitize_key($request->get_param('status') ?: '');
		$search = sanitize_text_field($request->get_param('search') ?: '');

		$logs  = FileChi_DB::get_logs(array(
			'limit'  => $limit,
			'offset' => $offset,
			'status' => $status,
			'search' => $search,
		));
		$total = FileChi_DB::get_total_logs($status);

		return rest_ensure_response(array(
			'items' => $logs,
			'total' => $total,
			'page'  => floor($offset / $limit) + 1,
			'pages' => ceil($total / $limit),
		));
	}
}
