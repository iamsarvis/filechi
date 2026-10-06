<?php
/**
 * FileChi FTPS Storage Driver
 *
 * Implements secure remote transport over explicit FTPS (FTP over TLS)
 * using PHP's native ftp_ssl_connect(). Plain unencrypted FTP is strictly forbidden.
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

class FileChi_Storage_FTPS implements FileChi_Storage_Interface {

	/**
	 * Provider settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Active FTP connection resource/object.
	 *
	 * @var resource|\FTP\Connection|null
	 */
	private $connection = null;

	/**
	 * Constructor.
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings) {
		$this->settings = wp_parse_args(
			$settings,
			array(
				'host'      => '',
				'port'      => 21,
				'username'  => '',
				'password'  => '',
				'passive'   => 1,
				'root_path' => '',
				'base_url'  => '',
				'timeout'   => 30,
			)
		);
	}

	/**
	 * Destructor to close FTP connection.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Closes the FTP connection if open.
	 */
	public function close() {
		if ($this->connection) {
			@ftp_close($this->connection);
			$this->connection = null;
		}
	}

	/**
	 * Initializes and authenticates explicit FTPS connection.
	 *
	 * @return resource|\FTP\Connection
	 * @throws \Exception
	 */
	private function get_connection() {
		if ($this->connection) {
			return $this->connection;
		}

		if (!function_exists('ftp_ssl_connect')) {
			throw new \Exception(__('The PHP ftp_ssl_connect() function is not available. Please ensure the PHP OpenSSL and FTP extensions are enabled.', 'filechi'));
		}

		$host    = trim($this->settings['host']);
		$port    = absint($this->settings['port']) ?: 21;
		$timeout = absint($this->settings['timeout']) ?: 30;

		if (empty($host)) {
			throw new \Exception(__('FTPS host cannot be empty.', 'filechi'));
		}

		// Connect strictly via explicit TLS
		$conn = @ftp_ssl_connect($host, $port, $timeout);
		if (!$conn) {
			throw new \Exception(sprintf(__('Failed to establish secure FTPS connection to %1$s:%2$d (Timeout: %3$ds).', 'filechi'), $host, $port, $timeout));
		}

		// Login
		$login = @ftp_login($conn, $this->settings['username'], $this->settings['password']);
		if (!$login) {
			@ftp_close($conn);
			throw new \Exception(__('FTPS authentication failed. Please check your username and password.', 'filechi'));
		}

		// Set passive mode (recommended for modern firewalls & NATs)
		$passive = !empty($this->settings['passive']);
		@ftp_pasv($conn, $passive);

		$this->connection = $conn;
		return $this->connection;
	}

	/**
	 * Resolves and normalizes remote path.
	 *
	 * @param string $remote_path
	 * @return string
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
	 * Recursively ensures remote directories exist on FTP server.
	 *
	 * @param resource|\FTP\Connection $conn
	 * @param string $dir
	 */
	private function ensure_dir($conn, $dir) {
		$dir = trim($dir, '/');
		if (empty($dir) || $dir === '.') {
			return;
		}

		$parts = explode('/', $dir);
		$path  = '';

		foreach ($parts as $part) {
			$path .= '/' . $part;
			if (@ftp_chdir($conn, $path) === false) {
				@ftp_mkdir($conn, $path);
				@ftp_chmod($conn, 0755, $path);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload($local_file, $remote_path) {
		if (!file_exists($local_file) || !is_readable($local_file)) {
			return false;
		}

		try {
			$conn             = $this->get_connection();
			$full_remote_path = $this->resolve_path($remote_path);
			$remote_dir       = dirname($full_remote_path);

			$this->ensure_dir($conn, $remote_dir);

			$uploaded = @ftp_put($conn, $full_remote_path, $local_file, FTP_BINARY);
			if (!$uploaded) {
				return false;
			}

			// Verify upload cheaply by comparing remote file size
			$remote_size = @ftp_size($conn, $full_remote_path);
			$local_size  = filesize($local_file);
			if ($remote_size !== -1 && $remote_size !== false && $remote_size !== $local_size) {
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
			$conn             = $this->get_connection();
			$full_remote_path = $this->resolve_path($remote_path);
			$remote_dir       = dirname($full_remote_path);

			$this->ensure_dir($conn, $remote_dir);

			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $content);
			rewind($stream);

			$result = @ftp_fput($conn, $full_remote_path, $stream, FTP_BINARY);
			fclose($stream);

			return $result;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($remote_path) {
		try {
			$conn             = $this->get_connection();
			$full_remote_path = $this->resolve_path($remote_path);

			return @ftp_delete($conn, $full_remote_path);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($remote_path) {
		try {
			$conn             = $this->get_connection();
			$full_remote_path = $this->resolve_path($remote_path);

			$size = @ftp_size($conn, $full_remote_path);
			return $size !== -1;
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
			$conn = $this->get_connection();
			$pwd  = @ftp_pwd($conn);

			if ($pwd === false) {
				return array(
					'success' => false,
					'message' => __('FTPS connection established, but could not determine current remote directory.', 'filechi'),
					'details' => null,
				);
			}

			// Validate configured root path if present
			$root = trim($this->settings['root_path']);
			if (!empty($root)) {
				$clean_root = rtrim(str_replace('\\', '/', $root), '/');
				if (@ftp_chdir($conn, $clean_root) === false) {
					return array(
						'success' => false,
						'message' => sprintf(__('FTPS login succeeded, but root path "%s" does not exist or is not a directory.', 'filechi'), esc_html($root)),
						'details' => null,
					);
				}
			}

			$latency = round((microtime(true) - $start_time) * 1000);

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: Latency in ms, 2: Remote working directory */
					__('FTPS (explicit TLS) connection successful! (Round-trip: %1$dms, Remote PWD: %2$s)', 'filechi'),
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
				'message' => sprintf(__('FTPS error: %s', 'filechi'), $e->getMessage()),
				'details' => null,
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		try {
			$conn             = $this->get_connection();
			$full_remote_path = $this->resolve_path($remote_path);

			$size = @ftp_size($conn, $full_remote_path);
			if ($size === -1) {
				return false;
			}

			$fp = fopen('php://output', 'wb');
			if (!$fp) {
				return false;
			}

			$result = @ftp_fget($conn, $fp, $full_remote_path, FTP_BINARY);
			fclose($fp);

			if (ob_get_level() > 0) {
				ob_flush();
			}
			flush();

			return $result;
		} catch (\Exception $e) {
			return false;
		}
	}
}
