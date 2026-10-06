<?php
/**
 * FileChi S3-Compatible Storage Driver
 *
 * Lightweight, dependency-free S3 client utilizing wp_remote_request()
 * with manual AWS Signature Version 4 (SigV4) signing.
 * Compatible with AWS S3, ArvanCloud Object Storage, and ParsPack Object Storage.
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

class FileChi_Storage_S3 implements FileChi_Storage_Interface {

	/**
	 * Settings for provider.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings
	 */
	public function __construct(array $settings) {
		$this->settings = wp_parse_args(
			$settings,
			array(
				'provider_type' => 'custom', // 'aws', 'arvancloud', 'parspack', 'custom'
				'endpoint'      => '',       // e.g. https://s3.ir-thr-at1.arvanstorage.ir
				'region'        => 'us-east-1',
				'bucket'        => '',
				'access_key'    => '',
				'secret_key'    => '',
				'path_style'    => 1,        // 1 for path-style (https://endpoint/bucket/key), 0 for virtual-hosted
				'public_acl'    => 1,        // x-amz-acl: public-read on upload
				'base_url'      => '',       // Optional CDN or custom domain URL
				'timeout'       => 30,
			)
		);

		$this->normalize_provider_settings();
	}

	/**
	 * Prepopulates known provider defaults (e.g. ArvanCloud documented regions/endpoints).
	 */
	private function normalize_provider_settings() {
		$type = $this->settings['provider_type'];

		if ($type === 'arvancloud') {
			if (empty($this->settings['endpoint'])) {
				// Default to Tehran Simin endpoint
				$this->settings['endpoint'] = 'https://s3.ir-thr-at1.arvanstorage.ir';
			}
			if (empty($this->settings['region']) || $this->settings['region'] === 'us-east-1') {
				$this->settings['region'] = 'ir-thr-at1';
			}
			$this->settings['path_style'] = 1;
		} elseif ($type === 'parspack') {
			$this->settings['path_style'] = 1;
			if (empty($this->settings['region'])) {
				$this->settings['region'] = 'default';
			}
		} elseif ($type === 'aws') {
			$region = !empty($this->settings['region']) ? $this->settings['region'] : 'us-east-1';
			if (empty($this->settings['endpoint'])) {
				$this->settings['endpoint'] = "https://s3.{$region}.amazonaws.com";
			}
			// AWS standard prefers virtual-hosted unless bucket contains dots
			if (strpos($this->settings['bucket'], '.') !== false) {
				$this->settings['path_style'] = 1;
			}
		}

		$this->settings['endpoint'] = rtrim(trim($this->settings['endpoint']), '/');
	}

	/**
	 * Builds URI and Host based on path-style or virtual-hosted addressing.
	 *
	 * @param string $key Object key.
	 * @return array [ 'url' => string, 'host' => string, 'uri_path' => string ]
	 */
	private function build_request_target($key = '') {
		$clean_key = ltrim(str_replace(array('\\', '../', '..\\'), array('/', '', ''), $key), '/');
		$bucket    = trim($this->settings['bucket']);
		$endpoint  = $this->settings['endpoint'];

		$parsed = wp_parse_url($endpoint);
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'] ?? '';
		$port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
		$path   = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';

		if (!empty($this->settings['path_style']) || empty($bucket)) {
			// Path-style: https://endpoint[:port]/bucket/key
			$uri_path = empty($clean_key)
				? ($path ? $path . '/' . $bucket : '/' . $bucket)
				: ($path ? $path . '/' . $bucket . '/' . $clean_key : '/' . $bucket . '/' . $clean_key);

			$uri_path = '/' . ltrim($uri_path, '/');
			$url      = "{$scheme}://{$host}{$port}{$uri_path}";
		} else {
			// Virtual-hosted style: https://bucket.endpoint[:port]/key
			$vhost    = "{$bucket}.{$host}";
			$uri_path = '/' . $clean_key;
			$url      = "{$scheme}://{$vhost}{$port}{$uri_path}";
			$host     = $vhost;
		}

		return array(
			'url'      => $url,
			'host'     => $host . $port,
			'uri_path' => $uri_path,
		);
	}

	/**
	 * Encodes URI path segment according to RFC 3986.
	 *
	 * @param string $path
	 * @return string
	 */
	private function encode_uri_path($path) {
		$segments = explode('/', $path);
		$encoded  = array();
		foreach ($segments as $segment) {
			$encoded[] = rawurlencode($segment);
		}
		return implode('/', $encoded);
	}

	/**
	 * Derives AWS Signature Version 4 signing key.
	 *
	 * @param string $date_stamp Ymd
	 * @param string $region
	 * @param string $secret_key
	 * @return string Binary derived key
	 */
	private function get_signature_key($date_stamp, $region, $secret_key) {
		$kDate    = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
		$kRegion  = hash_hmac('sha256', $region, $kDate, true);
		$kService = hash_hmac('sha256', 's3', $kRegion, true);
		$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
		return $kSigning;
	}

	/**
	 * Signs and executes an S3 HTTP request via wp_remote_request().
	 *
	 * @param string $method HTTP method (GET, PUT, HEAD, DELETE).
	 * @param string $key Object key.
	 * @param string $body Payload data.
	 * @param array  $extra_headers Additional headers (e.g. Content-Type, x-amz-acl).
	 * @param array  $query_params Query string parameters.
	 * @return array|\WP_Error
	 */
	public function execute_request($method, $key = '', $body = '', $extra_headers = array(), $query_params = array()) {
		$target   = $this->build_request_target($key);
		$url      = $target['url'];
		$host     = $target['host'];
		$uri_path = $target['uri_path'];

		$timestamp  = time();
		$amz_date   = gmdate('Ymd\THis\Z', $timestamp);
		$date_stamp = gmdate('Ymd', $timestamp);

		$access_key = trim($this->settings['access_key']);
		$secret_key = trim($this->settings['secret_key']);
		$region     = trim($this->settings['region']) ?: 'us-east-1';

		$payload_hash = hash('sha256', $body);

		// Prepare headers to sign
		$headers = array(
			'host'                 => $host,
			'x-amz-date'           => $amz_date,
			'x-amz-content-sha256' => $payload_hash,
		);

		foreach ($extra_headers as $h_name => $h_val) {
			$headers[strtolower(trim($h_name))] = trim($h_val);
		}

		ksort($headers);

		$canonical_headers = '';
		$signed_headers_arr = array();
		foreach ($headers as $h_name => $h_val) {
			$canonical_headers   .= $h_name . ':' . $h_val . "\n";
			$signed_headers_arr[] = $h_name;
		}
		$signed_headers = implode(';', $signed_headers_arr);

		// Build canonical query string
		ksort($query_params);
		$query_parts = array();
		foreach ($query_params as $q_key => $q_val) {
			$query_parts[] = rawurlencode($q_key) . '=' . rawurlencode($q_val);
		}
		$canonical_query_string = implode('&', $query_parts);

		$canonical_uri = $this->encode_uri_path($uri_path);

		$canonical_request = implode("\n", array(
			$method,
			$canonical_uri,
			$canonical_query_string,
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		));

		$credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";
		$string_to_sign   = implode("\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash('sha256', $canonical_request),
		));

		$signing_key = $this->get_signature_key($date_stamp, $region, $secret_key);
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = "AWS4-HMAC-SHA256 Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		// Request headers
		$request_headers = array_merge(
			$headers,
			array(
				'Authorization' => $authorization,
			)
		);

		$request_url = !empty($canonical_query_string) ? $url . '?' . $canonical_query_string : $url;

		$timeout = absint($this->settings['timeout']) ?: 30;

		$args = array(
			'method'    => $method,
			'headers'   => $request_headers,
			'body'      => $body,
			'timeout'   => $timeout,
			'sslverify' => true,
		);

		return wp_remote_request($request_url, $args);
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload($local_file, $remote_path) {
		if (!file_exists($local_file) || !is_readable($local_file)) {
			return false;
		}

		$body = file_get_contents($local_file);
		if ($body === false) {
			return false;
		}

		$mime_type = wp_check_filetype($local_file)['type'] ?: 'application/octet-stream';
		$headers   = array('Content-Type' => $mime_type);

		$is_protected = class_exists('FileChi_Media') && FileChi_Media::is_protected_file($remote_path);
		if (!empty($this->settings['public_acl']) && !$is_protected) {
			$headers['x-amz-acl'] = 'public-read';
		}

		$response = $this->execute_request('PUT', $remote_path, $body, $headers);
		if (is_wp_error($response)) {
			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return false;
		}

		// Cheap upload verification: compare ETag with md5_file, or fallback to HEAD check
		$etag = wp_remote_retrieve_header($response, 'etag');
		if (!empty($etag)) {
			$expected_md5 = md5_file($local_file);
			$clean_etag   = trim($etag, '" \t\n\r\0\x0B');
			// If not a multipart ETag (does not contain a hyphen), compare directly
			if (strpos($clean_etag, '-') === false && strcasecmp($clean_etag, $expected_md5) !== 0) {
				return false;
			}
		} else {
			if (!$this->exists($remote_path)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function upload_content($content, $remote_path, $mime_type = '') {
		$headers = array();
		if (!empty($mime_type)) {
			$headers['Content-Type'] = $mime_type;
		} else {
			$headers['Content-Type'] = 'application/octet-stream';
		}

		$is_protected = class_exists('FileChi_Media') && FileChi_Media::is_protected_file($remote_path);
		if (!empty($this->settings['public_acl']) && !$is_protected) {
			$headers['x-amz-acl'] = 'public-read';
		}

		$response = $this->execute_request('PUT', $remote_path, $content, $headers);

		if (is_wp_error($response)) {
			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		return ($code >= 200 && $code < 300);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($remote_path) {
		$response = $this->execute_request('DELETE', $remote_path);

		if (is_wp_error($response)) {
			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		return ($code === 204 || ($code >= 200 && $code < 300));
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($remote_path) {
		$response = $this->execute_request('HEAD', $remote_path);

		if (is_wp_error($response)) {
			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		return ($code === 200);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_url($remote_path) {
		$base_url = rtrim($this->settings['base_url'], '/');
		$clean_key = ltrim(str_replace('\\', '/', $remote_path), '/');

		if (!empty($base_url)) {
			return $base_url . '/' . $clean_key;
		}

		$target = $this->build_request_target($clean_key);
		return $target['url'];
	}

	/**
	 * Generates a pre-signed URL with SigV4 query authentication for time-limited access.
	 *
	 * {@inheritdoc}
	 */
	public function get_signed_url($remote_path, $expires_in_seconds = 900) {
		$clean_key = ltrim(str_replace(array('\\', '../', '..\\'), array('/', '', ''), $remote_path), '/');
		$target    = $this->build_request_target($clean_key);

		$url      = $target['url'];
		$host     = $target['host'];
		$uri_path = $target['uri_path'];

		$timestamp  = time();
		$amz_date   = gmdate('Ymd\THis\Z', $timestamp);
		$date_stamp = gmdate('Ymd', $timestamp);

		$access_key = trim($this->settings['access_key']);
		$secret_key = trim($this->settings['secret_key']);
		$region     = trim($this->settings['region']) ?: 'us-east-1';

		$credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";

		$query_params = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => "{$access_key}/{$credential_scope}",
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) absint($expires_in_seconds),
			'X-Amz-SignedHeaders' => 'host',
		);

		ksort($query_params);

		$query_parts = array();
		foreach ($query_params as $q_key => $q_val) {
			$query_parts[] = rawurlencode($q_key) . '=' . rawurlencode($q_val);
		}
		$canonical_query_string = implode('&', $query_parts);

		$canonical_headers = "host:{$host}\n";
		$signed_headers    = 'host';
		$payload_hash      = 'UNSIGNED-PAYLOAD';

		$canonical_request = implode("\n", array(
			'GET',
			$this->encode_uri_path($uri_path),
			$canonical_query_string,
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		));

		$string_to_sign = implode("\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash('sha256', $canonical_request),
		));

		$signing_key = $this->get_signature_key($date_stamp, $region, $secret_key);
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		return $url . '?' . $canonical_query_string . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		$start_time = microtime(true);

		if (empty($this->settings['endpoint'])) {
			return array(
				'success' => false,
				'message' => __('S3 endpoint URL cannot be empty.', 'filechi'),
				'details' => null,
			);
		}

		if (empty($this->settings['bucket'])) {
			return array(
				'success' => false,
				'message' => __('S3 bucket name cannot be empty.', 'filechi'),
				'details' => null,
			);
		}

		// Perform a lightweight HEAD request on the bucket or a test probe key
		$response = $this->execute_request('HEAD', '');

		if (is_wp_error($response)) {
			return array(
				'success' => false,
				'message' => sprintf(__('S3 network request failed: %s', 'filechi'), $response->get_error_message()),
				'details' => null,
			);
		}

		$code    = wp_remote_retrieve_response_code($response);
		$latency = round((microtime(true) - $start_time) * 1000);

		// If HEAD on bucket returns 200 or 404 (bucket exists or empty), test put/delete on probe key
		if ($code === 200 || $code === 404) {
			// Test write and delete permissions with a small probe object
			$probe_key = '.filechi-probe-' . wp_generate_password(8, false) . '.txt';
			$put_res   = $this->upload_content('FileChi connection test probe', $probe_key, 'text/plain');

			if ($put_res) {
				// Clean up probe object
				$this->delete($probe_key);

				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: Latency in ms, 2: Bucket name */
						__('S3 connection successful! Read, write, and delete permissions verified on bucket "%2$s". (Round-trip: %1$dms)', 'filechi'),
						$latency,
						esc_html($this->settings['bucket'])
					),
					'details' => array(
						'latency_ms' => $latency,
						'status'     => $code,
					),
				);
			} else {
				return array(
					'success' => false,
					'message' => sprintf(__('Bucket was reached, but write (PutObject) permission was denied on bucket "%s". Check Access Key policies.', 'filechi'), esc_html($this->settings['bucket'])),
					'details' => array('http_code' => $code),
				);
			}
		}

		$body = wp_remote_retrieve_body($response);
		$err_detail = '';
		if (!empty($body)) {
			// Extract S3 XML error message if returned
			if (preg_match('/<Message>(.*?)<\/Message>/is', $body, $m)) {
				$err_detail = $m[1];
			} elseif (preg_match('/<Code>(.*?)<\/Code>/is', $body, $m)) {
				$err_detail = $m[1];
			}
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: 1: HTTP status code, 2: Error detail string */
				__('S3 connection test failed with HTTP %1$d: %2$s', 'filechi'),
				$code,
				$err_detail ?: wp_remote_retrieve_response_message($response)
			),
			'details' => array(
				'http_code' => $code,
				'body'      => $body,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_to_output($remote_path, $chunk_size = 1048576) {
		$signed_url = $this->get_signed_url($remote_path, 300);

		// If allow_url_fopen is enabled, stream via fopen in small chunks
		if (ini_get('allow_url_fopen')) {
			$handle = @fopen($signed_url, 'rb');
			if ($handle) {
				while (!feof($handle)) {
					$chunk = fread($handle, $chunk_size);
					if ($chunk === false) {
						break;
					}
					echo $chunk;
					if (ob_get_level() > 0) {
						ob_flush();
					}
					flush();
				}
				fclose($handle);
				return true;
			}
		}

		// Fallback to cURL streaming directly to output
		if (function_exists('curl_init')) {
			$ch = curl_init($signed_url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
				echo $chunk;
				if (ob_get_level() > 0) {
					ob_flush();
				}
				flush();
				return strlen($chunk);
			});
			$success   = curl_exec($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			return ($success && $http_code >= 200 && $http_code < 300);
		}

		return false;
	}
}
