<?php
/**
 * FileChi SFTP Storage Driver
 *
 * Implements remote transport over SFTP using namespace-scoped phpseclib 3.x.
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

use FileChi\Vendor\phpseclib3\Net\SFTP;
use FileChi\Vendor\phpseclib3\Crypt\PublicKeyLoader;

class FileChi_Storage_SFTP implements FileChi_Storage_Interface {

	/**
	 * Provider settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Connected SFTP client instance.
	 *
	 * @var SFTP|null
	 */
	private $client = null;

	/**
	 * Most recent error message.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Provider ID associated with this driver.
	 *
	 * @var int
	 */
	protected $provider_id = 0;

	/**
	 * Internal flag indicating whether a probe is being executed.
	 *
	 * @var bool
	 */
	protected $internal_probe_mode = false;

	/**
	 * Constructor.
	 *
	 * @param array $settings
	 * @param int   $provider_id
	 */
	public function __construct(array $settings, $provider_id = 0) {
		$this->provider_id = !empty($provider_id) ? absint($provider_id) : (!empty($settings['provider_id']) ? absint($settings['provider_id']) : 0);
		$this->settings    = wp_parse_args(
			$settings,
			array(
				'host'        => '',
				'port'        => 22,
				'username'    => '',
				'auth_type'   => 'password', // 'password' or 'key'
				'password'    => '',
				'private_key' => '',
				'passphrase'  => '',
				'root_path'   => '',
				'base_url'    => '',
				'timeout'     => 30,
			)
		);
		$this->settings['provider_id'] = $this->provider_id;

		// Ensure autoload is loaded
		$autoload = dirname(dirname(__FILE__)) . '/vendor/autoload.php';
		if (file_exists($autoload)) {
			require_once $autoload;
		}
	}

	/**
	 * Initializes and authenticates the SFTP connection.
	 *
	 * @return SFTP
	 * @throws \Exception On connection or authentication failure.
	 */
	private function get_client() {
		if ($this->client !== null && $this->client->isConnected()) {
			return $this->client;
		}

		$host    = trim($this->settings['host']);
		$port    = absint($this->settings['port']) ?: 22;
		$timeout = absint($this->settings['timeout']) ?: 30;

		if (empty($host)) {
			throw new \Exception(esc_html__('SFTP host cannot be empty.', 'filechi'));
		}

		$sftp = new SFTP($host, $port, $timeout);

		$username = $this->settings['username'];
		$login_ok = false;

		if ($this->settings['auth_type'] === 'key' && !empty($this->settings['private_key'])) {
			$key = PublicKeyLoader::load($this->settings['private_key'], $this->settings['passphrase'] ?: false);
			$login_ok = $sftp->login($username, $key);
		} else {
			$login_ok = $sftp->login($username, $this->settings['password']);
		}

		if (!$login_ok) {
			$errors = $sftp->getErrors();
			$err_msg = !empty($errors) ? implode('; ', (array) $errors) : esc_html__('SFTP login failed. Please check your credentials.', 'filechi');
			throw new \Exception(esc_html($err_msg));
		}

		$this->client = $sftp;
		return $this->client;
	}

	/**
	 * Resolves and normalizes the full remote path.
	 * Protects against path traversal attacks.
	 *
	 * @param string $remote_path Relative attachment key, probe file name, or empty string for root.
	 * @param bool   $is_probe    Whether this is a diagnostic probe file.
	 * @return string Normalized full remote path.
	 * @throws FileChi_Path_Exception When root or key fails validation.
	 * @throws RuntimeException When protected file is targeted without protected_path configured.
	 */
	public function resolve_path($remote_path = '', $is_probe = false) {
		$raw_prot = (string) ($this->settings['protected_path'] ?? '');
		$raw_root = (string) ($this->settings['root_path'] ?? '');

		// If this is a protected WooCommerce file, route to protected_path (outside web root)
		$is_protected = false;
		if ($remote_path !== '' && class_exists('FileChi_Media') && FileChi_Media::is_protected_file($remote_path)) {
			$is_protected = true;
		}

		if ($is_protected) {
			if ($raw_prot === '') {
				throw new RuntimeException(esc_html__('Protected file cannot be stored: SFTP provider has no protected_path configured.', 'filechi'));
			}
			$norm_root = FileChi_Path::normalize_root($raw_prot);
		} else {
			$norm_root = FileChi_Path::normalize_root($raw_root);
		}

		if ($remote_path === '') {
			return $norm_root;
		}

		$effective_is_probe = ($is_probe === true || !empty($this->internal_probe_mode));
		if ($effective_is_probe) {
			if (!preg_match('/^\.filechi-probe-[a-f0-9]{8,32}\.tmp$/', $remote_path)) {
				throw new FileChi_Path_Exception(esc_html__('Invalid probe file name format.', 'filechi'));
			}
			$clean_path = $remote_path;
		} else {
			$clean_path = FileChi_Path::validate($remote_path);
		}

		if ($norm_root === '') {
			return $clean_path;
		}
		if ($norm_root === '/') {
			return '/' . $clean_path;
		}

		return $norm_root . '/' . $clean_path;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload($local_file, $remote_path) {
		$this->last_error = '';

		if (!file_exists($local_file) || !is_readable($local_file)) {
			$this->last_error = "Local file does not exist or is not readable: {$local_file}";
			return false;
		}

		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);
			$remote_dir       = dirname($full_remote_path);

			// Recursively create directory structure if not exists
			if (!$sftp->is_dir($remote_dir)) {
				$sftp->mkdir($remote_dir, -1, true);
			}

			// Stream upload from local file without exhausting PHP memory
			$uploaded = $sftp->put($full_remote_path, $local_file, SFTP::SOURCE_LOCAL_FILE);
			if (!$uploaded) {
				$this->last_error = "SFTP put failed for {$remote_path}";
				return false;
			}

			// Verify upload cheaply by comparing remote file size
			$remote_size = $sftp->filesize($full_remote_path);
			$local_size  = (int) filesize($local_file);
			if ($remote_size !== false && $remote_size !== $local_size) {
				$this->last_error = "Remote size mismatch (expected: {$local_size}, actual: {$remote_size})";
				return false;
			}

			return true;
		} catch (\Exception $e) {
			$this->last_error = 'SFTP upload error: ' . $e->getMessage();
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload_content($content, $remote_path, $mime_type = '') {
		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);
			$remote_dir       = dirname($full_remote_path);

			if (!$sftp->is_dir($remote_dir)) {
				$sftp->mkdir($remote_dir, -1, true);
			}

			return $sftp->put($full_remote_path, $content, SFTP::SOURCE_STRING);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($remote_path) {
		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);

			if ($sftp->file_exists($full_remote_path)) {
				return $sftp->delete($full_remote_path);
			}
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($remote_path) {
		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);
			return $sftp->file_exists($full_remote_path);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_url($remote_path) {
		try {
			$clean_path = FileChi_Path::validate($remote_path);
		} catch (FileChi_Path_Exception $e) {
			FileChi_Path::log_error($remote_path, $e->getMessage());
			$uploads = wp_upload_dir();
			return rtrim($uploads['baseurl'], '/') . '/' . ltrim(str_replace('\\', '/', (string) $remote_path), '/');
		}

		$base_url = rtrim((string) ($this->settings['base_url'] ?? ''), '/');
		if (!empty($base_url)) {
			return $base_url . '/' . $clean_path;
		}

		// Fallback to upload_dir baseurl if no custom CDN base_url provided
		$uploads = wp_upload_dir();
		return rtrim($uploads['baseurl'], '/') . '/' . $clean_path;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_signed_url($remote_path, $expires_in_seconds = 900) {
		try {
			$clean_path = FileChi_Path::validate($remote_path);
		} catch (FileChi_Path_Exception $e) {
			FileChi_Path::log_error($remote_path, $e->getMessage());
			$uploads = wp_upload_dir();
			return rtrim($uploads['baseurl'], '/') . '/' . ltrim(str_replace('\\', '/', (string) $remote_path), '/');
		}

		$expires     = time() + absint($expires_in_seconds);
		$provider_id = (int) $this->provider_id;
		$token       = hash_hmac('sha256', $provider_id . '|' . $clean_path . '|' . $expires, wp_salt('nonce'));

		return add_query_arg(
			array(
				'filechi_action'   => 'download',
				'filechi_file'     => rawurlencode($clean_path),
				'filechi_expires'  => $expires,
				'filechi_provider' => $provider_id,
				'filechi_token'    => $token,
			),
			home_url('/')
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function set_provider_id($provider_id) {
		$this->provider_id             = absint($provider_id);
		$this->settings['provider_id'] = $this->provider_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_provider_id() {
		return (int) $this->provider_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		$start_time = microtime(true);

		try {
			$sftp = $this->get_client();
			$pwd  = $sftp->pwd();

			if ($pwd === false) {
				return array(
					'success' => false,
					'message' => __('Connected to SFTP host, but failed to retrieve current working directory.', 'filechi'),
					'details' => $sftp->getErrors(),
				);
			}

			// Test listing root directory or writing a temporary check
			$root = (string) ($this->settings['root_path'] ?? '');
			if ($root !== '') {
				try {
					$clean_root = $this->resolve_path('');
				} catch (FileChi_Path_Exception $e) {
					return array(
						'success' => false,
						'message' => sprintf(esc_html__('Invalid remote root path: %s', 'filechi'), $e->getMessage()),
						'details' => array(
							'reason' => 'invalid_root_path',
						),
					);
				}
				if ($clean_root !== '' && !$sftp->is_dir($clean_root)) {
					return array(
						'success' => false,
						'message' => sprintf(__('SFTP authentication succeeded, but specified root directory "%s" does not exist or is inaccessible.', 'filechi'), esc_html($clean_root)),
						'details' => null,
					);
				}
			}

			$latency = round((microtime(true) - $start_time) * 1000);

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: Latency in milliseconds, 2: Remote working directory */
					__('SFTP connection successful! (Round-trip: %1$dms, Remote PWD: %2$s)', 'filechi'),
					$latency,
					$pwd
				),
				'details' => array(
					'latency_ms' => $latency,
					'pwd'        => $pwd,
				),
			);
		} catch (\Exception $e) {
			return array(
				'success' => false,
				'message' => sprintf(__('SFTP connection error: %s', 'filechi'), $e->getMessage()),
				'details' => null,
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);

			if (!$sftp->file_exists($full_remote_path)) {
				return false;
			}

			// Stream directly to output without loading file into PHP memory
			$result = $sftp->get($full_remote_path, function ($chunk) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream output for file download.
				echo $chunk;
				if (ob_get_level() > 0) {
					ob_flush();
				}
				flush();
				return true;
			});

			return $result !== false;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_size($remote_path) {
		try {
			$sftp             = $this->get_client();
			$full_remote_path = $this->resolve_path($remote_path);
			$size             = $sftp->filesize($full_remote_path);
			if ($size === false) {
				return -1;
			}
			return (int) $size;
		} catch (\Exception $e) {
			return -1;
		}
	}
}
