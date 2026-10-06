<?php
/**
 * FileChi Cryptography Helper
 *
 * Provides authenticated symmetric encryption and decryption for sensitive provider credentials
 * (passwords, secret access keys, private key passphrases) using libsodium (sodium_crypto_secretbox)
 * with OpenSSL AES-256-GCM fallback.
 *
 * Threat Model:
 * Authenticated credential encryption protects against database-only disclosures
 * (e.g. leaked database backups, SQL injection dumps, or compromised read-only DB access).
 * It does not protect against an attacker who has obtained both the database contents
 * and wp-config.php (or server filesystem access), since encryption keys are derived
 * directly from WordPress salts and constants.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Crypto {

	/**
	 * Versioned prefix identifier for sodium_crypto_secretbox payloads.
	 */
	const SODIUM_PREFIX = 'fc_sod2:';

	/**
	 * Versioned prefix identifier for OpenSSL AES-256-GCM fallback payloads.
	 */
	const OPENSSL_PREFIX = 'fc_gcm2:';

	/**
	 * Simulation flags for unit/verification testing.
	 *
	 * @var bool
	 */
	public static $simulate_no_sodium  = false;
	public static $simulate_no_ciphers = false;

	/**
	 * Derives a 32-byte cryptographic key from WordPress salts and constants using HKDF.
	 *
	 * @return string 32-byte binary key.
	 */
	public static function get_encryption_key() {
		if (function_exists('wp_salt')) {
			$ikm = wp_salt('auth') . wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
		} else {
			$ikm  = defined('AUTH_KEY') ? AUTH_KEY : '';
			$ikm .= defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';
			$ikm .= defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '';
			$ikm .= defined('NONCE_SALT') ? NONCE_SALT : '';
		}

		return hash_hkdf('sha256', $ikm, 32, 'filechi-credentials-v1');
	}

	/**
	 * Encrypts a plaintext string using authenticated encryption.
	 *
	 * Fail-closed: returns WP_Error (or throws RuntimeException) if neither cipher is available.
	 *
	 * @param string      $plaintext Data to encrypt.
	 * @param string|null $key       Optional 32-byte binary key override for testing.
	 * @return string|WP_Error Base64-encoded encrypted payload or WP_Error on failure.
	 */
	public static function encrypt($plaintext, $key = null) {
		if ($plaintext === '' || $plaintext === null) {
			return '';
		}

		$plaintext = (string) $plaintext;
		if ($key === null) {
			$key = self::get_encryption_key();
		}

		if (!self::$simulate_no_ciphers) {
			// Use libsodium if available (preferred, standard in PHP 7.2+)
			if (!self::$simulate_no_sodium && function_exists('sodium_crypto_secretbox') && function_exists('random_bytes')) {
				try {
					$nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
					$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
					return base64_encode(self::SODIUM_PREFIX . $nonce . $ciphertext);
				} catch (\Exception $e) {
					// Fall through to OpenSSL if random_bytes fails
				}
			}

			// Authenticated AES-256-GCM fallback via OpenSSL
			if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
				$ivLength   = openssl_cipher_iv_length('aes-256-gcm');
				$iv         = function_exists('random_bytes') ? random_bytes($ivLength) : openssl_random_pseudo_bytes($ivLength);
				$tag        = '';
				$ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

				if ($ciphertext !== false) {
					return base64_encode(self::OPENSSL_PREFIX . $iv . $tag . $ciphertext);
				}
			}
		}

		// Fail closed: Refuse to store plaintext or unencrypted secrets!
		if (class_exists('WP_Error')) {
			return new WP_Error(
				'filechi_crypto_unavailable',
				__('Neither libsodium nor OpenSSL AES-256-GCM is available for secure credential storage. Cannot store credential.', 'filechi')
			);
		}

		throw new \RuntimeException('Neither libsodium nor OpenSSL AES-256-GCM is available for secure credential storage.');
	}

	/**
	 * Decrypts an authenticated encrypted payload.
	 *
	 * Fail-closed: returns distinguishable false on tampering, bad key, or corrupt data.
	 *
	 * @param string      $encrypted_str Base64-encoded encrypted string.
	 * @param string|null $key           Optional 32-byte binary key override for testing.
	 * @return string|false Decrypted plaintext, '' if input empty, or false on decryption failure/tampering.
	 */
	public static function decrypt($encrypted_str, $key = null) {
		if ($encrypted_str === '' || $encrypted_str === null) {
			return '';
		}

		$raw = base64_decode((string) $encrypted_str, true);
		if ($raw === false) {
			return false;
		}

		if ($key === null) {
			$key = self::get_encryption_key();
		}

		// Libsodium decrypt
		$sod_prefix_len = strlen(self::SODIUM_PREFIX);
		if (strncmp($raw, self::SODIUM_PREFIX, $sod_prefix_len) === 0) {
			if (!function_exists('sodium_crypto_secretbox_open')) {
				return false;
			}

			$payload   = substr($raw, $sod_prefix_len);
			$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

			if (strlen($payload) < $nonce_len) {
				return false;
			}

			$nonce      = substr($payload, 0, $nonce_len);
			$ciphertext = substr($payload, $nonce_len);

			$decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
			return $decrypted !== false ? $decrypted : false;
		}

		// OpenSSL AES-256-GCM decrypt
		$gcm_prefix_len = strlen(self::OPENSSL_PREFIX);
		if (strncmp($raw, self::OPENSSL_PREFIX, $gcm_prefix_len) === 0) {
			if (!function_exists('openssl_decrypt')) {
				return false;
			}

			$payload = substr($raw, $gcm_prefix_len);
			$iv_len  = openssl_cipher_iv_length('aes-256-gcm');
			$tag_len = 16;

			if (strlen($payload) < ($iv_len + $tag_len)) {
				return false;
			}

			$iv         = substr($payload, 0, $iv_len);
			$tag        = substr($payload, $iv_len, $tag_len);
			$ciphertext = substr($payload, $iv_len + $tag_len);

			$decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
			return $decrypted !== false ? $decrypted : false;
		}

		// Fail closed: unrecognized payload or raw text is an explicit failure
		return false;
	}
}
