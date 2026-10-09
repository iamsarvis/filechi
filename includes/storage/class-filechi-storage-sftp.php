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
	 * Constructor.
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings) {
		$this->settings = wp_parse_args(
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
	 * @param string $remote_path
	 * @return string Normalized full remote path.
	 */
	private function resolve_path($remote_path) {
		$remote_path = str_replace(array('\\', '../', '..\\'), array('/', '', ''), $remote_path);
		$remote_path = ltrim($remote_path, '/');

		// If this is a protected WooCommerce file, route to protected_path (outside web root)
		if (class_exists('FileChi_Media') && FileChi_Media::is_protected_file($remote_path)) {
			$prot_root = trim($this->settings['protected_path'] ?? '');
			if (!empty($prot_root)) {
				$prot_root = rtrim(str_replace('\\', '/', $prot_root), '/');
				return $prot_root . '/' . $remote_path;
			}
		}

		$root = trim($this->settings['root_path'] ?? '');
		if (!empty($root)) {
			$root = rtrim(str_replace('\\', '/', $root), '/');
			return $root . '/' . $remote_path;
		}

		return $remote_path;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload($local_file, $remote_path) {
		if (!file_exists($local_file) || !is_readable($local_file)) {
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
				return false;
			}

			// Verify upload cheaply by comparing remote file size
			$remote_size = $sftp->filesize($full_remote_path);
			$local_size  = filesize($local_file);
			if ($remote_size !== false && $remote_size !== $local_size) {
				return false;
			}

			return true;
		} catch (\Exception $e) {
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
		$base_url = rtrim($this->settings['base_url'], '/');
		$clean_path = ltrim(str_replace('\\', '/', $remote_path), '/');

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
		$expires = time() + absint($expires_in_seconds);
		$clean_path = ltrim(str_replace('\\', '/', $remote_path), '/');

		$token = hash_hmac('sha256', $clean_path . '|' . $expires, wp_salt('nonce'));

		return add_query_arg(
			array(
				'filechi_action'   => 'download',
				'filechi_file'     => rawurlencode($clean_path),
				'filechi_expires'  => $expires,
				'filechi_token'    => $token,
			),
			home_url('/')
		);
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
			$root = trim($this->settings['root_path']);
			if (!empty($root)) {
				$clean_root = rtrim(str_replace('\\', '/', $root), '/');
				if (!$sftp->is_dir($clean_root)) {
					return array(
						'success' => false,
						'message' => sprintf(__('SFTP authentication succeeded, but specified root directory "%s" does not exist or is inaccessible.', 'filechi'), esc_html($root)),
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
}
