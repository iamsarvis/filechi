<?php
/**
 * FileChi FTPS Storage Driver
 *
 * Implements secure remote transport over explicit/implicit FTPS (FTP over TLS)
 * using PHP's cURL extension. Plain unencrypted FTP and insecure connections
 * are strictly forbidden.
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

class FileChi_Storage_FTPS implements FileChi_Storage_Interface {

	/**
	 * Provider settings.
	 *
	 * @var array<string, mixed>
	 */
	protected $settings;

	/**
	 * Most recent error message.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings Provider settings.
	 */
	public function __construct(array $settings) {
		$this->settings = wp_parse_args(
			$settings,
			array(
				'host'           => '',
				'port'           => 0,
				'username'       => '',
				'password'       => '',
				'passive'        => 1,
				'root_path'      => '',
				'protected_path' => '',
				'base_url'       => '',
				'timeout'        => 30,
				'ftps_mode'      => 'explicit',
				'tls_pin'        => '',
			)
		);
	}

	/**
	 * Validates a TLS pinned public key format.
	 * Format: 'sha256//' followed by 44 base64 characters.
	 *
	 * @param mixed $pin
	 * @return bool
	 */
	public static function is_valid_tls_pin($pin) {
		if (!is_string($pin)) {
			return false;
		}

		$trimmed = trim($pin);
		if (strlen($trimmed) !== 52) {
			return false;
		}

		return (bool) preg_match('/^sha256\/\/[A-Za-z0-9+\/]{43}[A-Za-z0-9+\/=]$/', $trimmed);
	}

	/**
	 * Returns the configured TLS pinned public key or empty string.
	 *
	 * @return string
	 */
	public function get_tls_pin() {
		return isset($this->settings['tls_pin']) ? trim((string) $this->settings['tls_pin']) : '';
	}

	/**
	 * Returns the FTPS mode: 'explicit' or 'implicit'.
	 *
	 * @return string
	 */
	public function get_ftps_mode() {
		return (isset($this->settings['ftps_mode']) && $this->settings['ftps_mode'] === 'implicit')
			? 'implicit'
			: 'explicit';
	}

	/**
	 * Returns the URI scheme based on the FTPS mode.
	 *
	 * @return string
	 */
	public function get_scheme() {
		return ($this->get_ftps_mode() === 'implicit') ? 'ftps' : 'ftp';
	}

	/**
	 * Returns the target port based on settings and FTPS mode.
	 *
	 * @return int
	 */
	public function get_port() {
		$port = isset($this->settings['port']) ? absint($this->settings['port']) : 0;
		if ($port > 0) {
			return $port;
		}

		return ($this->get_ftps_mode() === 'implicit') ? 990 : 21;
	}

	/**
	 * Validates a remote path to guard against control characters and FTP command injection.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function validate_remote_path($path) {
		if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
			$this->last_error = esc_html__('Invalid path: control characters detected.', 'filechi');
			return false;
		}

		$trimmed = trim($path);
		if ($trimmed === '' || $trimmed === '/' || $trimmed === '.' || $trimmed === '..' || substr($trimmed, -1) === '/') {
			$this->last_error = esc_html__('Invalid path: cannot target root or a directory.', 'filechi');
			return false;
		}

		$basename = basename($trimmed);
		if ($basename === '' || $basename === '.' || $basename === '..') {
			$this->last_error = esc_html__('Invalid path: cannot target root or a directory.', 'filechi');
			return false;
		}

		return true;
	}

	/**
	 * Resolves and normalizes remote path. Single place that builds paths.
	 *
	 * @param string $remote_path
	 * @return string
	 */
	public function resolve_path($remote_path) {
		$clean_path = str_replace(array('\\', '../', '..\\'), array('/', '', ''), $remote_path);
		$clean_path = ltrim($clean_path, '/');

		if (class_exists('FileChi_Media') && FileChi_Media::is_protected_file($clean_path)) {
			$prot_root = trim((string) ($this->settings['protected_path'] ?? ''));
			if (!empty($prot_root)) {
				$prot_root = rtrim(str_replace('\\', '/', $prot_root), '/');
				return $prot_root . '/' . $clean_path;
			}
		}

		$root = trim((string) ($this->settings['root_path'] ?? ''));
		if (!empty($root)) {
			$root = rtrim(str_replace('\\', '/', $root), '/');
			return $root . '/' . $clean_path;
		}

		return $clean_path;
	}

	/**
	 * Builds a full cURL URL for a resolved remote path with segments rawurlencoded.
	 *
	 * @param string $resolved_path
	 * @return string
	 */
	public function build_curl_url($resolved_path) {
		$scheme = $this->get_scheme();
		$host   = trim((string) ($this->settings['host'] ?? ''));
		$port   = $this->get_port();

		$is_absolute = (substr($resolved_path, 0, 1) === '/');
		$trimmed     = trim($resolved_path, '/');
		$segments    = explode('/', $trimmed);
		$encoded     = array_map('rawurlencode', $segments);
		$joined      = implode('/', $encoded);

		if ($is_absolute) {
			return "{$scheme}://{$host}:{$port}//{$joined}";
		}

		return "{$scheme}://{$host}:{$port}/{$joined}";
	}

	/**
	 * Builds base URL for directory/root operations.
	 *
	 * @return string
	 */
	public function build_base_curl_url() {
		$scheme = $this->get_scheme();
		$host   = trim((string) ($this->settings['host'] ?? ''));
		$port   = $this->get_port();

		return "{$scheme}://{$host}:{$port}/";
	}

	/**
	 * Creates and configures a cURL handle for FTPS operations.
	 *
	 * @param string $url Target cURL URL.
	 * @return \CurlHandle|resource
	 */
	protected function create_curl_handle($url) {
		$ch = curl_init($url);

		// Restrict protocols to ftp and ftps
		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
		}
		if (defined('CURLOPT_REDIR_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
		}

		// TLS Mode: Explicit FTPS forces TLS via CURLOPT_USE_SSL = CURLUSESSL_ALL
		if ($this->get_ftps_mode() === 'explicit') {
			curl_setopt($ch, CURLOPT_USE_SSL, defined('CURLUSESSL_ALL') ? CURLUSESSL_ALL : 3);
		}

		// TLS Verification ON: verify peer and host; load CA bundle from WordPress
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		$ca_bundle = defined('ABSPATH') && defined('WPINC') ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';
		if (!empty($ca_bundle) && file_exists($ca_bundle)) {
			curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
		}

		// Authentication
		$username = (string) ($this->settings['username'] ?? '');
		$password = (string) ($this->settings['password'] ?? '');
		if ($username !== '' || $password !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
		}

		// Passive mode and NAT traversal: skip passive IP
		$passive = !empty($this->settings['passive']);
		curl_setopt($ch, CURLOPT_FTP_USE_EPSV, $passive);
		if (defined('CURLOPT_FTP_SKIP_PASV_IP')) {
			curl_setopt($ch, CURLOPT_FTP_SKIP_PASV_IP, true);
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		return $ch;
	}

	/**
	 * Configures connection and transfer timeouts.
	 *
	 * @param \CurlHandle|resource $ch
	 * @param int                  $filesize
	 */
	protected function apply_timeouts($ch, $filesize = 0) {
		// Connection timeout fixed at 15s
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

		// Total timeout scaled by file size, never below configured timeout (default 30s)
		$base_timeout = max(30, absint($this->settings['timeout'] ?? 30));
		if ($filesize > 0) {
			$scaled_timeout = max($base_timeout, (int) ceil($filesize / 50000) + 30);
		} else {
			$scaled_timeout = $base_timeout;
		}
		curl_setopt($ch, CURLOPT_TIMEOUT, $scaled_timeout);

		// Fail stalled transfers instead of hanging
		curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
		curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
	}

	/**
	 * Maps cURL errno and server reply codes to descriptive error messages.
	 *
	 * @param int    $errno
	 * @param string $errstr
	 * @param int    $response_code
	 * @param string $default_msg
	 * @return string
	 */
	public function map_curl_error($errno, $errstr, $response_code = 0, $default_msg = '') {
		$code_prefix  = $errno > 0 ? "[curl {$errno}] " : '';
		$reply_suffix = $response_code > 0 ? " (FTP reply {$response_code})" : '';

		// cURL 60 (CURLE_PEER_FAILED_VERIFICATION) or 51 (CURLE_SSL_PEER_CERTIFICATE)
		if ($errno === 60 || $errno === 51) {
			return $code_prefix . "the server's TLS certificate is not trusted; use the host name that matches the certificate" . $reply_suffix;
		}

		// cURL 67 (CURLE_LOGIN_DENIED)
		if ($errno === 67) {
			return $code_prefix . 'login denied' . $reply_suffix . ($errstr ? ": {$errstr}" : '');
		}

		// cURL 7 (CURLE_COULDNT_CONNECT)
		if ($errno === 7) {
			return $code_prefix . 'connection failed' . $reply_suffix . ($errstr ? ": {$errstr}" : '');
		}

		// cURL 28 (CURLE_OPERATION_TIMEDOUT)
		if ($errno === 28) {
			return $code_prefix . 'timeout' . $reply_suffix . ($errstr ? ": {$errstr}" : '');
		}

		// FTP 425 / 426: cannot open data connection
		if ($response_code === 425 || $response_code === 426) {
			return $code_prefix . 'cannot open data connection' . $reply_suffix . ($errstr ? ": {$errstr}" : '');
		}

		// FTP 550: permission denied or file not found
		if ($response_code === 550) {
			if (stripos($errstr, 'no such file') !== false || stripos($errstr, 'not found') !== false) {
				return $code_prefix . 'file not found' . $reply_suffix . ": {$errstr}";
			}
			return $code_prefix . 'permission denied / 550' . $reply_suffix . ($errstr ? ": {$errstr}" : '');
		}

		if (!empty($errstr)) {
			return $code_prefix . $errstr . $reply_suffix;
		}

		return $code_prefix . ($default_msg ?: 'FTPS operation failed') . $reply_suffix;
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

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return false;
		}

		$fp = @fopen($local_file, 'rb');
		if (!$fp) {
			$this->last_error = "Could not open local file for reading: {$local_file}";
			return false;
		}

		$filesize = (int) filesize($local_file);
		$url      = $this->build_curl_url($resolved_path);

		$ch = $this->create_curl_handle($url);
		curl_setopt($ch, CURLOPT_UPLOAD, true);
		curl_setopt($ch, CURLOPT_INFILE, $fp);
		curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
		curl_setopt(
			$ch,
			CURLOPT_FTP_CREATE_MISSING_DIRS,
			defined('CURLFTP_CREATE_DIR_RETRY') ? CURLFTP_CREATE_DIR_RETRY : 2
		);
		$this->apply_timeouts($ch, $filesize);

		$exec_result   = curl_exec($ch);
		$curl_error    = curl_error($ch);
		$curl_errno    = curl_errno($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		fclose($fp);

		if ($exec_result === false) {
			$this->last_error = $this->map_curl_error($curl_errno, $curl_error, $response_code, "FTPS put failed for {$remote_path}");
			return false;
		}

		// Verify upload by checking remote size
		$remote_size = $this->get_size($remote_path);
		if ($remote_size >= 0 && $remote_size !== $filesize) {
			$this->last_error = "Remote size mismatch (expected: {$filesize}, actual: {$remote_size})";
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload_content($content, $remote_path, $mime_type = '') {
		$this->last_error = '';

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return false;
		}

		$stream = fopen('php://temp', 'r+');
		if (!$stream) {
			$this->last_error = 'Could not allocate temporary memory stream for upload.';
			return false;
		}

		fwrite($stream, $content);
		rewind($stream);
		$size = strlen($content);

		$url = $this->build_curl_url($resolved_path);
		$ch  = $this->create_curl_handle($url);
		curl_setopt($ch, CURLOPT_UPLOAD, true);
		curl_setopt($ch, CURLOPT_INFILE, $stream);
		curl_setopt($ch, CURLOPT_INFILESIZE, $size);
		curl_setopt(
			$ch,
			CURLOPT_FTP_CREATE_MISSING_DIRS,
			defined('CURLFTP_CREATE_DIR_RETRY') ? CURLFTP_CREATE_DIR_RETRY : 2
		);
		$this->apply_timeouts($ch, $size);

		$exec_result   = curl_exec($ch);
		$curl_error    = curl_error($ch);
		$curl_errno    = curl_errno($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		fclose($stream);

		if ($exec_result === false) {
			$this->last_error = $this->map_curl_error($curl_errno, $curl_error, $response_code, "FTPS content upload failed for {$remote_path}");
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($remote_path) {
		$this->last_error = '';

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return false;
		}

		$url = $this->build_base_curl_url();
		$ch  = $this->create_curl_handle($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_QUOTE, array('DELE ' . $resolved_path));
		$this->apply_timeouts($ch, 0);

		$exec_result   = curl_exec($ch);
		$curl_errno    = curl_errno($ch);
		$curl_error    = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($exec_result === false) {
			$this->last_error = $this->map_curl_error($curl_errno, $curl_error, $response_code, "Delete failed for {$remote_path}");
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($remote_path) {
		$size = $this->get_size($remote_path);
		return $size >= 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_size($remote_path) {
		$this->last_error = '';

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return -1;
		}

		$url = $this->build_curl_url($resolved_path);
		$ch  = $this->create_curl_handle($url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_FILETIME, true);
		$this->apply_timeouts($ch, 0);

		$exec_result   = curl_exec($ch);
		$curl_errno    = curl_errno($ch);
		$curl_error    = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$download_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
		curl_close($ch);

		if ($exec_result === false || $download_size < 0) {
			$this->last_error = $this->map_curl_error($curl_errno, $curl_error, $response_code, "Size check failed for {$remote_path}");
			return -1;
		}

		return (int) $download_size;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_url($remote_path) {
		$base_url   = rtrim((string) ($this->settings['base_url'] ?? ''), '/');
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
		$expires    = time() + absint($expires_in_seconds);
		$clean_path = ltrim(str_replace('\\', '/', $remote_path), '/');

		$token = hash_hmac('sha256', $clean_path . '|' . $expires, wp_salt('nonce'));

		return add_query_arg(
			array(
				'filechi_action'  => 'download',
				'filechi_file'    => rawurlencode($clean_path),
				'filechi_expires' => $expires,
				'filechi_token'   => $token,
			),
			home_url('/')
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		$this->last_error = '';

		$resolved_path = $this->resolve_path($remote_path);
		if (!$this->validate_remote_path($resolved_path)) {
			return false;
		}

		$url = $this->build_curl_url($resolved_path);
		$ch  = $this->create_curl_handle($url);

		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ($ch, $data) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary download content streamed directly to client.
				echo $data;
				if (ob_get_level() > 0) {
					ob_flush();
				}
				flush();
				return strlen($data);
			}
		);
		$this->apply_timeouts($ch, 0);

		$exec_result   = curl_exec($ch);
		$curl_errno    = curl_errno($ch);
		$curl_error    = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($exec_result === false) {
			$this->last_error = $this->map_curl_error($curl_errno, $curl_error, $response_code, "Streaming failed for {$remote_path}");
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		$start_time = microtime(true);

		// Requirement check: cURL extension, ftp & ftps protocols, and SSL support
		if (!extension_loaded('curl')) {
			return array(
				'success' => false,
				'message' => esc_html__('PHP cURL with FTP and SSL is required.', 'filechi'),
				'details' => null,
			);
		}

		$version   = curl_version();
		$protocols = array_map('strtolower', $version['protocols'] ?? array());
		$has_ftp   = in_array('ftp', $protocols, true);
		$has_ftps  = in_array('ftps', $protocols, true);
		$has_ssl   = (bool) ($version['features'] & CURL_VERSION_SSL);

		if (!$has_ftp || !$has_ftps || !$has_ssl) {
			return array(
				'success' => false,
				'message' => esc_html__('PHP cURL with FTP and SSL is required.', 'filechi'),
				'details' => null,
			);
		}

		$host = trim((string) ($this->settings['host'] ?? ''));
		if (empty($host)) {
			return array(
				'success' => false,
				'message' => esc_html__('FTPS host cannot be empty.', 'filechi'),
				'details' => null,
			);
		}

		$url = $this->build_base_curl_url();
		$ch  = $this->create_curl_handle($url);
		curl_setopt($ch, CURLOPT_FTPLISTONLY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$this->apply_timeouts($ch, 0);

		$list_output   = curl_exec($ch);
		$curl_errno    = curl_errno($ch);
		$curl_error    = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($list_output === false) {
			$mapped = $this->map_curl_error($curl_errno, $curl_error, $response_code, 'FTPS connection test failed');
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Mapped error explanation */
					esc_html__('FTPS error: %s', 'filechi'),
					$mapped
				),
				'details' => array(
					'curl_errno'    => $curl_errno,
					'response_code' => $response_code,
				),
			);
		}

		$latency = round((microtime(true) - $start_time) * 1000);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: FTPS mode name, 2: Latency in milliseconds */
				esc_html__('FTPS (%1$s TLS) connection successful! (Round-trip: %2$dms)', 'filechi'),
				ucfirst($this->get_ftps_mode()),
				$latency
			),
			'details' => array(
				'latency_ms' => $latency,
				'mode'       => $this->get_ftps_mode(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
