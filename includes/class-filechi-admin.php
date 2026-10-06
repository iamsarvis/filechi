<?php
/**
 * FileChi Admin Menu & Enqueuing
 *
 * Registers the wp-admin menu page, enqueues WordPress component scripts
 * (@wordpress/components, @wordpress/element, @wordpress/api-fetch),
 * and renders the admin application mount point.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Admin {

	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'filechi';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('admin_notices', array($this, 'render_admin_notices'));
		add_action('wp_ajax_filechi_dismiss_protected_notice', array($this, 'ajax_dismiss_protected_notice'));
	}

	/**
	 * Registers the FileChi admin menu page.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__('FileChi Remote Storage', 'filechi'),
			__('FileChi', 'filechi'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_admin_page'),
			'dashicons-cloud-upload',
			58
		);
	}

	/**
	 * Enqueues WordPress components, styles, and FileChi admin app script.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets($hook_suffix) {
		if ($hook_suffix !== 'toplevel_page_' . self::MENU_SLUG) {
			return;
		}

		// Enqueue WordPress core React component libraries
		wp_enqueue_style('wp-components');
		wp_enqueue_script('wp-element');
		wp_enqueue_script('wp-components');
		wp_enqueue_script('wp-api-fetch');
		wp_enqueue_script('wp-i18n');

		// FileChi Admin CSS
		wp_enqueue_style(
			'filechi-admin-css',
			FILECHI_URL . 'assets/css/admin.css',
			array('wp-components'),
			FILECHI_VERSION
		);

		// FileChi Admin JS
		wp_enqueue_script(
			'filechi-admin-js',
			FILECHI_URL . 'assets/js/admin.js',
			array('wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'),
			FILECHI_VERSION,
			true
		);

		// Localize script data for REST API communication
		wp_localize_script(
			'filechi-admin-js',
			'filechiAdminData',
			array(
				'restUrl'                 => esc_url_raw(rest_url('filechi/v1')),
				'nonce'                   => wp_create_nonce('wp_rest'),
				'isActionSchedulerActive' => FileChi_Migration::is_action_scheduler_active(),
				'pluginUrl'               => FILECHI_URL,
				'strings'                 => array(
					'connections'        => __('Connections', 'filechi'),
					'mediaRules'         => __('Media Rules', 'filechi'),
					'woocommerce'        => __('WooCommerce', 'filechi'),
					'migrationLogs'      => __('Migration & Logs', 'filechi'),
					'addProvider'        => __('Add Connection Profile', 'filechi'),
					'editProvider'       => __('Edit Connection Profile', 'filechi'),
					'testConnection'     => __('Test Connection', 'filechi'),
					'testing'            => __('Testing...', 'filechi'),
					'save'               => __('Save Changes', 'filechi'),
					'saving'             => __('Saving...', 'filechi'),
					'delete'             => __('Delete', 'filechi'),
					'setDefault'         => __('Set as Default', 'filechi'),
					'defaultBadge'       => __('Active Default', 'filechi'),
					'status'             => __('Status', 'filechi'),
					'startMigration'     => __('Start Migration', 'filechi'),
					'pauseMigration'     => __('Pause', 'filechi'),
					'retryFailed'        => __('Retry Failed Items', 'filechi'),
				),
			)
		);
	}

	/**
	 * Renders the admin view container.
	 */
	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'filechi'));
		}
		?>
		<div class="wrap filechi-admin-wrap">
			<div id="filechi-admin-app">
				<div class="filechi-loading-state">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
					<?php esc_html_e('Loading FileChi Control Panel...', 'filechi'); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders security notices in wp-admin if a protected WooCommerce file cannot be offloaded.
	 */
	public function render_admin_notices() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$notice = get_option('filechi_protected_path_missing_notice');
		if ($notice) {
			$provider_name = is_array($notice) ? ($notice['provider_name'] ?? 'SFTP/FTPS') : 'SFTP/FTPS';
			$att_id        = is_array($notice) ? ($notice['attachment_id'] ?? 0) : 0;
			?>
			<div class="notice notice-warning is-dismissible filechi-notice">
				<p>
					<strong><?php esc_html_e('FileChi Security Notice:', 'filechi'); ?></strong>
					<?php
					echo esc_html(sprintf(
						/* translators: 1: Attachment ID, 2: Provider name */
						__('A protected WooCommerce file (attachment #%1$d) was kept on local disk and NOT offloaded because provider "%2$s" does not have a Protected Path configured. Protected downloads must never be placed in a public web root.', 'filechi'),
						$att_id,
						$provider_name
					));
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * AJAX handler to dismiss the protected path notice.
	 */
	public function ajax_dismiss_protected_notice() {
		check_ajax_referer('filechi_admin_nonce', 'nonce');
		if (current_user_can('manage_options')) {
			delete_option('filechi_protected_path_missing_notice');
		}
		wp_send_json_success();
	}
}
