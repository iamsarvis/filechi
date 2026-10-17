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
	 * Computes the sha256// pinned public key from a certificate PEM string.
	 *
	 * PEM -> openssl_pkey_get_details() -> DER SubjectPublicKeyInfo -> SHA-256 -> base64 -> sha256//...
	 *
	 * @param string $cert_pem
	 * @return string|false Pin string on success, false on failure.
	 */
	public static function compute_pin_from_certificate($cert_pem) {
		if (empty($cert_pem) || !function_exists('openssl_pkey_get_public') || !function_exists('openssl_pkey_get_details')) {
			return false;
		}

		$pubkey = @openssl_pkey_get_public($cert_pem);
		if (!$pubkey) {
			return false;
		}

		$details = @openssl_pkey_get_details($pubkey);
		if (!is_array($details) || empty($details['key'])) {
			return false;
		}

		$pubkey_pem = $details['key'];
		$lines      = explode("\n", trim($pubkey_pem));
		$der_base64 = '';
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || strpos($line, '-----') === 0) {
				continue;
			}
			$der_base64 .= $line;
		}

		$spki_der = base64_decode($der_base64);
		if (empty($spki_der)) {
			return false;
		}

		$hash_binary = hash('sha256', $spki_der, true);
		return 'sha256//' . base64_encode($hash_binary);
	}

	/**
	 * Formats key algorithm description (e.g. "RSA (2048 bits)" or "EC (256 bits)").
	 *
	 * @param array<string, mixed> $details Details from openssl_pkey_get_details().
	 * @return string
	 */
	public static function format_key_algorithm(array $details) {
		$type_num = $details['type'] ?? -1;
		$bits     = $details['bits'] ?? 0;

		$type_name = 'Unknown';
		if ($type_num === OPENSSL_KEYTYPE_RSA) {
			$type_name = 'RSA';
		} elseif ($type_num === OPENSSL_KEYTYPE_DSA) {
			$type_name = 'DSA';
		} elseif ($type_num === OPENSSL_KEYTYPE_DH) {
			$type_name = 'DH';
		} elseif ($type_num === OPENSSL_KEYTYPE_EC) {
			$type_name = 'EC';
		}

		if ($bits > 0) {
			return sprintf('%s (%d bits)', $type_name, $bits);
		}

		return $type_name;
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

		try {
			FileChi_Path::validate($path);
		} catch (FileChi_Path_Exception $e) {
			$this->last_error = $e->getMessage();
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
		$clean_path = FileChi_Path::validate($remote_path);

		if (class_exists('FileChi_Media') && FileChi_Media::is_protected_file($clean_path)) {
			$prot_root = trim((string) ($this->settings['protected_path'] ?? ''));
			if (!empty($prot_root)) {
				$prot_root = rtrim(str_replace('\\', '/', $prot_root), '/');
				return $prot_root . '/' . $clean_path;
			}
			throw new RuntimeException(esc_html__('Protected file cannot be stored: FTPS provider has no protected_path configured.', 'filechi'));
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

		// TLS Verification modes:
		// Hard rule: never connect with verification disabled unless a valid pin is set.
		$tls_pin = $this->get_tls_pin();
		if ($tls_pin !== '') {
			if (!self::is_valid_tls_pin($tls_pin)) {
				throw new InvalidArgumentException(
					esc_html__('Invalid TLS pin format. Verification cannot be disabled without a valid pin.', 'filechi')
				);
			}

			if (!defined('CURLOPT_PINNEDPUBLICKEY')) {
				throw new RuntimeException(
					esc_html__('CURLOPT_PINNEDPUBLICKEY is not supported by this PHP environment.', 'filechi')
				);
			}

			curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $tls_pin);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		} else {
			// TLS Verification ON: verify peer and host; load CA bundle from WordPress
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

			$ca_bundle = defined('ABSPATH') && defined('WPINC') ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';
			if (!empty($ca_bundle) && file_exists($ca_bundle)) {
				curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
			}
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

		// cURL 90 (CURLE_SSL_PINNEDPUBKEYNOTMATCH)
		if ($errno === 90) {
			return $code_prefix . "PINNED KEY MISMATCH — the server's key changed or someone is intercepting the connection" . $reply_suffix;
		}

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
			if ($response_code === 227 || $response_code === 229) {
				$passive_info = self::parse_passive_info(array(), $errstr);
				$target       = ($passive_info['ip'] ? $passive_info['ip'] . ':' : '') . ($passive_info['port'] > 0 ? $passive_info['port'] : 'passive-port');
				return $code_prefix . "data connection failed to {$target} — firewall or passive port blocked" . $reply_suffix . ($errstr ? ": {$errstr}" : '');
			}
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

		try {
			$resolved_path = $this->resolve_path($remote_path);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
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

		try {
			$resolved_path = $this->resolve_path($remote_path);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
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

		try {
			$resolved_path = $this->resolve_path($remote_path);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
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

		try {
			$resolved_path = $this->resolve_path($remote_path);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();
			return -1;
		}
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

		$expires = time() + absint($expires_in_seconds);
		$token   = hash_hmac('sha256', $clean_path . '|' . $expires, wp_salt('nonce'));

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

		try {
			$resolved_path = $this->resolve_path($remote_path);
		} catch (\Exception $e) {
			$this->last_error = $e->getMessage();
			return false;
		}
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
	 * Discovers and extracts the server's public key certificate info.
	 *
	 * Connects with VERIFYPEER=0, VERIFYHOST=0, CURLOPT_CERTINFO=true and anonymous credentials
	 * so user credentials are NEVER sent to an untrusted host.
	 *
	 * @param array<int, array<string, mixed>>|null $cert_info_override Optional cert info for testing.
	 * @return array<string, mixed>
	 */
	public function discover_certificate($cert_info_override = null) {
		$cert_info = $cert_info_override;

		if ($cert_info === null) {
			$discovery_url = $this->build_base_curl_url();
			$discovery_ch  = curl_init($discovery_url);

			if (defined('CURLOPT_PROTOCOLS')) {
				curl_setopt($discovery_ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
			}
			if (defined('CURLOPT_REDIR_PROTOCOLS')) {
				curl_setopt($discovery_ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
			}

			if ($this->get_ftps_mode() === 'explicit') {
				curl_setopt($discovery_ch, CURLOPT_USE_SSL, defined('CURLUSESSL_ALL') ? CURLUSESSL_ALL : 3);
			}

			// Read certificate only: anonymous credentials, verification off
			curl_setopt($discovery_ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($discovery_ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($discovery_ch, CURLOPT_CERTINFO, true);
			curl_setopt($discovery_ch, CURLOPT_USERPWD, 'anonymous:anonymous@example.com');

			$passive = !empty($this->settings['passive']);
			curl_setopt($discovery_ch, CURLOPT_FTP_USE_EPSV, $passive);
			if (defined('CURLOPT_FTP_SKIP_PASV_IP')) {
				curl_setopt($discovery_ch, CURLOPT_FTP_SKIP_PASV_IP, true);
			}

			curl_setopt($discovery_ch, CURLOPT_FTPLISTONLY, true);
			curl_setopt($discovery_ch, CURLOPT_RETURNTRANSFER, true);
			$this->apply_timeouts($discovery_ch, 0);

			@curl_exec($discovery_ch);
			$cert_info = curl_getinfo($discovery_ch, CURLINFO_CERTINFO);
			curl_close($discovery_ch);
		}

		if (empty($cert_info) || empty($cert_info[0]) || empty($cert_info[0]['Cert'])) {
			return array();
		}

		$first_cert = $cert_info[0];
		$cert_pem   = (string) $first_cert['Cert'];

		$fingerprint = self::compute_pin_from_certificate($cert_pem);
		if (!$fingerprint) {
			return array();
		}

		$algorithm = 'Unknown';
		$pubkey    = @openssl_pkey_get_public($cert_pem);
		if ($pubkey) {
			$details = @openssl_pkey_get_details($pubkey);
			if (is_array($details)) {
				$algorithm = self::format_key_algorithm($details);
			}
		}

		$subject    = (string) ($first_cert['Subject'] ?? '');
		$issuer     = (string) ($first_cert['Issuer'] ?? '');
		$valid_from = (string) ($first_cert['Start date'] ?? '');
		$valid_to   = (string) ($first_cert['Expire date'] ?? '');
		$san        = (string) ($first_cert['X509v3 Subject Alternative Name'] ?? '');

		if (function_exists('openssl_x509_parse')) {
			$parsed = @openssl_x509_parse($cert_pem);
			if (is_array($parsed)) {
				if ($subject === '' && isset($parsed['name'])) {
					$subject = (string) $parsed['name'];
				}
				if ($issuer === '' && isset($parsed['issuer'])) {
					$issuer = is_array($parsed['issuer'])
						? implode(', ', array_map(function ($k, $v) { return "{$k}={$v}"; }, array_keys($parsed['issuer']), $parsed['issuer']))
						: (string) $parsed['issuer'];
				}
				if ($valid_from === '' && isset($parsed['validFrom_time_t'])) {
					$valid_from = gmdate('M d H:i:s Y e', $parsed['validFrom_time_t']);
				}
				if ($valid_to === '' && isset($parsed['validTo_time_t'])) {
					$valid_to = gmdate('M d H:i:s Y e', $parsed['validTo_time_t']);
				}
				if ($san === '' && isset($parsed['extensions']['subjectAltName'])) {
					$san = (string) $parsed['extensions']['subjectAltName'];
				}
			}
		}

		return array(
			'success'     => false,
			'needs_trust' => true,
			'fingerprint' => $fingerprint,
			'algorithm'   => $algorithm,
			'subject'     => $subject,
			'issuer'      => $issuer,
			'valid_from'  => $valid_from,
			'valid_to'    => $valid_to,
			'san'         => $san,
			'message'     => esc_html__('Server TLS certificate is untrusted. Review details and trust its public key once to enable pinned connection.', 'filechi'),
		);
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

		$tls_pin = $this->get_tls_pin();
		if ($tls_pin !== '') {
			$ver_num = isset($version['version_number']) ? (int) $version['version_number'] : 0;
			if (!defined('CURLOPT_PINNEDPUBLICKEY') || $ver_num < 0x072700) {
				return array(
					'success' => false,
					'message' => esc_html__('FTPS public key pinning requires libcurl 7.39.0 or higher with CURLOPT_PINNEDPUBLICKEY support.', 'filechi'),
					'details' => null,
				);
			}
		}

		$host = trim((string) ($this->settings['host'] ?? ''));
		if (empty($host)) {
			return array(
				'success' => false,
				'message' => esc_html__('FTPS host cannot be empty.', 'filechi'),
				'details' => null,
			);
		}

		$port      = $this->get_port();
		$scheme    = $this->get_scheme();
		$ftps_mode = $this->get_ftps_mode();
		$stages    = array();

		// Stage 1: connect (control channel)
		$err = $this->test_stage_connect($stages, $host, $port, $scheme, $ftps_mode);
		if ($err !== null) {
			return $err;
		}

		// Stage 2: tls (AUTH TLS and the pin or CA check)
		$err = $this->test_stage_tls($stages, $host, $port, $scheme, $ftps_mode, $tls_pin);
		if ($err !== null) {
			return $err;
		}

		// Stage 3: login (credentials check)
		$err = $this->test_stage_login($stages, $scheme, $host, $port);
		if ($err !== null) {
			return $err;
		}

		// Stage 4: directory (reach the root path)
		$err = $this->test_stage_directory($stages);
		if ($err !== null) {
			return $err;
		}

		// Stage 5: data_connection (directory listing via passive data channel)
		$err = $this->test_stage_data_connection($stages, $host, $port);
		if ($err !== null) {
			return $err;
		}

		// Stage 6: write_probe (upload a small probe file and delete it)
		$err = $this->test_stage_write_probe($stages);
		if ($err !== null) {
			return $err;
		}

		$latency = round((microtime(true) - $start_time) * 1000);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: FTPS mode name, 2: Latency in milliseconds */
				esc_html__('FTPS (%1$s TLS) connection successful! (Round-trip: %2$dms)', 'filechi'),
				ucfirst($ftps_mode),
				$latency
			),
			'stages'  => $stages,
			'details' => array(
				'latency_ms' => $latency,
				'mode'       => $ftps_mode,
			),
		);
	}

	/**
	 * Stage 1: Connect (control channel).
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @param string                                              $host
	 * @param int                                                 $port
	 * @param string                                              $scheme
	 * @param string                                              $ftps_mode
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_connect(array &$stages, $host, $port, $scheme, $ftps_mode) {
		$connect_url = "{$scheme}://{$host}:{$port}/";
		$ch          = curl_init($connect_url);

		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
		}
		curl_setopt($ch, CURLOPT_CONNECT_ONLY, 1);
		// In Stage 1: verify control channel reachability (allow TLS negotiation if server requires it on control channel)
		curl_setopt($ch, CURLOPT_USE_SSL, defined('CURLUSESSL_TRY') ? CURLUSESSL_TRY : 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$res           = curl_exec($ch);
		$errno         = curl_errno($ch);
		$error         = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		// Control channel failed if network / socket connection failed
		if ($res === false && ($errno === 7 || $errno === 28 || $errno === 6)) {
			$detail = sprintf(
				/* translators: 1: Host, 2: Port, 3: Error */
				esc_html__('Failed to connect to %1$s:%2$d (control channel): %3$s', 'filechi'),
				$host,
				$port,
				$error
			);
			$stages[] = array(
				'name'   => 'connect',
				'ok'     => false,
				'detail' => $detail,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					esc_html__('FTPS connection test failed at control channel: %s', 'filechi'),
					$this->map_curl_error($errno, $error, $response_code, 'Control connection failed')
				),
				'stages'  => $stages,
				'details' => array(
					'failed_stage'  => 'connect',
					'curl_errno'    => $errno,
					'response_code' => $response_code,
				),
			);
		}

		$stages[] = array(
			'name'   => 'connect',
			'ok'     => true,
			'detail' => sprintf(
				/* translators: 1: Host, 2: Port */
				esc_html__('Connected to %1$s:%2$d (control channel)', 'filechi'),
				$host,
				$port
			),
		);

		return null;
	}

	/**
	 * Stage 2: TLS (AUTH TLS and the pin or CA check).
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @param string                                              $host
	 * @param int                                                 $port
	 * @param string                                              $scheme
	 * @param string                                              $ftps_mode
	 * @param string                                              $tls_pin
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_tls(array &$stages, $host, $port, $scheme, $ftps_mode, $tls_pin) {
		$tls_url = "{$scheme}://{$host}:{$port}/";
		$ch      = curl_init($tls_url);

		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP | CURLPROTO_FTPS);
		}
		curl_setopt($ch, CURLOPT_CONNECT_ONLY, 1);
		if ($ftps_mode === 'explicit') {
			curl_setopt($ch, CURLOPT_USE_SSL, defined('CURLUSESSL_ALL') ? CURLUSESSL_ALL : 3);
		}

		$username = (string) ($this->settings['username'] ?? '');
		$password = (string) ($this->settings['password'] ?? '');
		if ($username !== '' || $password !== '') {
			curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
		}

		if ($tls_pin !== '' && defined('CURLOPT_PINNEDPUBLICKEY')) {
			curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $tls_pin);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		} else {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$res           = curl_exec($ch);
		$errno         = curl_errno($ch);
		$error         = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		// If cURL progressed to login rejection (67 / 530), TLS handshake and pin check succeeded
		if ($res === false && ($errno === 67 || $response_code === 530)) {
			$res = true;
		}

		if ($res === false) {
			// If no pin is configured and verification failed (self-signed / IP access), discover cert
			if ($tls_pin === '' && ($errno === 60 || $errno === 51)) {
				$discovery = $this->discover_certificate();
				if (!empty($discovery['needs_trust'])) {
					$discovery['stages'] = array_merge($stages, array(
						array(
							'name'   => 'tls',
							'ok'     => false,
							'detail' => esc_html__('Server TLS certificate is untrusted. Review details and trust its public key once to enable pinned connection.', 'filechi'),
						),
					));
					return $discovery;
				}
			}

			$mapped   = $this->map_curl_error($errno, $error, $response_code, 'TLS handshake failed');
			$stages[] = array(
				'name'   => 'tls',
				'ok'     => false,
				'detail' => $mapped,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					esc_html__('FTPS TLS handshake failed: %s', 'filechi'),
					$mapped
				),
				'stages'  => $stages,
				'details' => array(
					'failed_stage'  => 'tls',
					'curl_errno'    => $errno,
					'response_code' => $response_code,
				),
			);
		}

		$stages[] = array(
			'name'   => 'tls',
			'ok'     => true,
			'detail' => $tls_pin !== ''
				? esc_html__('AUTH TLS successful, public key pin verified', 'filechi')
				: esc_html__('AUTH TLS successful, server certificate verified', 'filechi'),
		);

		return null;
	}

	/**
	 * Stage 3: Login (credentials check on control channel).
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @param string                                              $scheme
	 * @param string                                              $host
	 * @param int                                                 $port
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_login(array &$stages, $scheme, $host, $port) {
		$login_url = "{$scheme}://{$host}:{$port}/";
		$ch        = $this->create_curl_handle($login_url);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		$this->apply_timeouts($ch, 0);

		$res           = curl_exec($ch);
		$errno         = curl_errno($ch);
		$error         = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($res === false && ($errno === 67 || $response_code === 530)) {
			$detail   = esc_html__('Login denied: incorrect username or password.', 'filechi');
			$stages[] = array(
				'name'   => 'login',
				'ok'     => false,
				'detail' => $detail,
			);
			return array(
				'success' => false,
				'message' => esc_html__('FTPS authentication failed: login denied.', 'filechi'),
				'stages'  => $stages,
				'details' => array(
					'failed_stage'  => 'login',
					'curl_errno'    => $errno,
					'response_code' => $response_code,
				),
			);
		} elseif ($res === false && $errno !== 0 && $errno !== 19 && $errno !== 9) {
			$mapped   = $this->map_curl_error($errno, $error, $response_code, 'Login failed');
			$stages[] = array(
				'name'   => 'login',
				'ok'     => false,
				'detail' => $mapped,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					esc_html__('FTPS authentication failed: %s', 'filechi'),
					$mapped
				),
				'stages'  => $stages,
				'details' => array(
					'failed_stage'  => 'login',
					'curl_errno'    => $errno,
					'response_code' => $response_code,
				),
			);
		}

		$username = (string) ($this->settings['username'] ?? '');
		$stages[] = array(
			'name'   => 'login',
			'ok'     => true,
			'detail' => sprintf(
				/* translators: %s: Username */
				esc_html__('Authenticated as %s', 'filechi'),
				$username !== '' ? $username : 'anonymous'
			),
		);

		return null;
	}

	/**
	 * Stage 4: Directory (reach the root path).
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_directory(array &$stages) {
		$raw_root = trim((string) ($this->settings['root_path'] ?? ''));
		if ($raw_root !== '') {
			$ch = $this->create_curl_handle($this->build_base_curl_url());
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_QUOTE, array('CWD ' . $raw_root));
			$this->apply_timeouts($ch, 0);

			$res           = curl_exec($ch);
			$errno         = curl_errno($ch);
			$error         = curl_error($ch);
			$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($res === false || ($response_code >= 400 && $response_code <= 599)) {
				$detail   = sprintf(
					/* translators: 1: Path, 2: Error */
					esc_html__('Could not reach remote base path "%1$s": %2$s', 'filechi'),
					$raw_root,
					$this->map_curl_error($errno, $error, $response_code, 'Directory change failed')
				);
				$stages[] = array(
					'name'   => 'directory',
					'ok'     => false,
					'detail' => $detail,
				);
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: Detail */
						esc_html__('FTPS remote directory check failed: %s', 'filechi'),
						$detail
					),
					'stages'  => $stages,
					'details' => array(
						'failed_stage'  => 'directory',
						'curl_errno'    => $errno,
						'response_code' => $response_code,
					),
				);
			}

			$stages[] = array(
				'name'   => 'directory',
				'ok'     => true,
				'detail' => sprintf(
					/* translators: %s: Remote path */
					esc_html__('Remote path "%s" reached', 'filechi'),
					$raw_root
				),
			);
		} else {
			$stages[] = array(
				'name'   => 'directory',
				'ok'     => true,
				'detail' => esc_html__('Remote root directory reached', 'filechi'),
			);
		}

		return null;
	}

	/**
	 * Parses passive IP and port from FTP status lines or cURL error messages.
	 *
	 * Supports:
	 * - 227 Entering Passive Mode (h1,h2,h3,h4,p1,p2)
	 * - 229 Entering Extended Passive Mode (|||port|)
	 * - cURL error messages mentioning "port <port>" or "to <ip> port <port>"
	 *
	 * @param array<string> $headers
	 * @param string        $errstr
	 * @param string        $default_host
	 * @return array{ip: string, port: int}
	 */
	public static function parse_passive_info(array $headers = array(), $errstr = '', $default_host = '') {
		$port = 0;
		$ip   = $default_host;

		foreach ($headers as $line) {
			if (preg_match('/227\s+Entering\s+Passive\s+Mode\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i', $line, $m)) {
				$ip   = "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";
				$port = ((int) $m[5] * 256) + (int) $m[6];
				return array('ip' => $ip, 'port' => $port);
			}
			if (preg_match('/229\s+Entering\s+Extended\s+Passive\s+Mode\s*\(\s*\|\|\|(\d+)\|\s*\)/i', $line, $m)) {
				$port = (int) $m[1];
				return array('ip' => $ip, 'port' => $port);
			}
		}

		if (!empty($errstr)) {
			if (preg_match('/to\s+([0-9a-zA-Z\.\:\-]+)\s+port\s+(\d+)/i', $errstr, $m)) {
				$ip   = $m[1];
				$port = (int) $m[2];
			} elseif (preg_match('/port\s+(\d+)/i', $errstr, $m)) {
				$port = (int) $m[1];
			}
		}

		return array('ip' => $ip, 'port' => $port);
	}

	/**
	 * Stage 5: Data Connection (directory listing requiring passive data channel).
	 *
	 * Diagnoses high-port firewall blocks and detects passive port.
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @param string                                              $host
	 * @param int                                                 $port
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_data_connection(array &$stages, $host, $port) {
		$list_headers = array();
		$ch           = $this->create_curl_handle($this->build_base_curl_url());
		curl_setopt($ch, CURLOPT_FTPLISTONLY, true);
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ($c, $hdr) use (&$list_headers) {
				$list_headers[] = trim((string) $hdr);
				return strlen($hdr);
			}
		);
		$this->apply_timeouts($ch, 0);

		$list_res      = curl_exec($ch);
		$errno         = curl_errno($ch);
		$error         = curl_error($ch);
		$response_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		$passive_info = self::parse_passive_info($list_headers, $error, $host);
		$passive_port = $passive_info['port'];
		$passive_ip   = $passive_info['ip'];

		if ($list_res === false) {
			// Check if curl 7 occurred after control channel worked (reply 227/229 or passive port != control port)
			$is_data_conn_failed = (
				$errno === 7 &&
				(
					$response_code === 227 ||
					$response_code === 229 ||
					($passive_port > 0 && $passive_port !== $port)
				)
			);

			if ($is_data_conn_failed) {
				$target       = ($passive_ip ?: $host) . ':' . ($passive_port > 0 ? $passive_port : 'passive-port');
				$diag_message = sprintf(
					/* translators: %s: Target IP and port */
					esc_html__("Login succeeded, but the data connection to %s could not be opened. Either the FTP server's passive port range is closed in its firewall, or this web host blocks outgoing connections to high ports. Test from FileZilla on another network, then ask the host to open the range or allow outgoing connections.", 'filechi'),
					$target
				);

				$stages[] = array(
					'name'   => 'data_connection',
					'ok'     => false,
					'detail' => $diag_message,
				);
				return array(
					'success' => false,
					'message' => $diag_message,
					'stages'  => $stages,
					'details' => array(
						'failed_stage'  => 'data_connection',
						'reason'        => 'ftp_data_connection_failed',
						'curl_errno'    => 7,
						'response_code' => $response_code,
						'passive_ip'    => $passive_ip ?: $host,
						'passive_port'  => $passive_port,
					),
				);
			}

			$mapped   = $this->map_curl_error($errno, $error, $response_code, 'Data connection failed');
			$stages[] = array(
				'name'   => 'data_connection',
				'ok'     => false,
				'detail' => $mapped,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Error message */
					esc_html__('FTPS data connection failed: %s', 'filechi'),
					$mapped
				),
				'stages'  => $stages,
				'details' => array(
					'failed_stage'  => 'data_connection',
					'curl_errno'    => $errno,
					'response_code' => $response_code,
					'passive_port'  => $passive_port,
				),
			);
		}

		$port_info = $passive_port > 0 ? " (passive port {$passive_port})" : '';
		$stages[]  = array(
			'name'   => 'data_connection',
			'ok'     => true,
			'detail' => sprintf(
				/* translators: %s: Port info */
				esc_html__('Directory listing OK via passive data channel%s', 'filechi'),
				$port_info
			),
		);

		return null;
	}

	/**
	 * Stage 6: Write Probe (upload a small probe file and delete it).
	 *
	 * @param array<array{name: string, ok: bool, detail: string}> $stages
	 * @return array<string, mixed>|null Returns failure array to stop or null on success.
	 */
	public function test_stage_write_probe(array &$stages) {
		$rand_suffix   = function_exists('wp_generate_password') ? wp_generate_password(8, false) : bin2hex(random_bytes(4));
		$probe_name    = '.filechi-probe-' . $rand_suffix . '.tmp';
		$probe_content = 'FileChi connection test probe ' . gmdate('c');

		$put_ok = $this->upload_content($probe_content, $probe_name, 'text/plain');
		if (!$put_ok) {
			$detail   = sprintf(
				/* translators: %s: Error message */
				esc_html__('Write probe upload failed: %s', 'filechi'),
				$this->get_last_error() ?: esc_html__('Could not write probe file to remote storage.', 'filechi')
			);
			$stages[] = array(
				'name'   => 'write_probe',
				'ok'     => false,
				'detail' => $detail,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Detail */
					esc_html__('FTPS write probe failed: %s', 'filechi'),
					$detail
				),
				'stages'  => $stages,
				'details' => array(
					'failed_stage' => 'write_probe',
					'last_error'   => $this->get_last_error(),
				),
			);
		}

		// Delete probe file
		$del_ok = $this->delete($probe_name);
		if (!$del_ok) {
			$stages[] = array(
				'name'   => 'write_probe',
				'ok'     => true,
				'detail' => esc_html__('Probe file uploaded successfully (warning: could not remove temporary probe file)', 'filechi'),
			);
		} else {
			$stages[] = array(
				'name'   => 'write_probe',
				'ok'     => true,
				'detail' => esc_html__('Probe file uploaded and cleaned up successfully', 'filechi'),
			);
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
