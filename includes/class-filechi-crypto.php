<?php
/**
 * FileChi Cryptography Helper
 *
 * Provides authenticated symmetric encryption and decryption for sensitive provider credentials
 * (passwords, secret access keys, private key passphrases) using libsodium (sodium_crypto_secretbox).
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_Crypto {

	/**
	 * Prefix identifier for sodium_crypto_secretbox payloads.
	 */
	const SODIUM_PREFIX = 'fc_sod:';

	/**
	 * Prefix identifier for OpenSSL AES-256-GCM fallback payloads.
	 */
	const OPENSSL_PREFIX = 'fc_gcm:';

	/**
	 * Derives a 32-byte cryptographic key from WordPress salts and constants.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_encryption_key() {
		$material = '';

		if (defined('AUTH_KEY')) {
			$material .= AUTH_KEY;
		}
		if (defined('SECURE_AUTH_KEY')) {
			$material .= SECURE_AUTH_KEY;
		}
		if (defined('LOGGED_IN_SALT')) {
			$material .= LOGGED_IN_SALT;
		}
		if (defined('NONCE_SALT')) {
			$material .= NONCE_SALT;
		}

		// Fallback if constants are surprisingly missing (e.g. CLI tests)
		if (empty($material)) {
			$material = 'filechi_default_static_salt_do_not_use_in_prod';
		}

		return hash('sha256', $material, true);
	}

	/**
	 * Encrypts a plaintext string using authenticated encryption.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded encrypted payload with authentication tag and nonce.
	 */
	public static function encrypt($plaintext) {
		if ($plaintext === '' || $plaintext === null) {
			return '';
		}

		$plaintext = (string) $plaintext;
		$key       = self::get_encryption_key();

		// Use libsodium if available (preferred, standard in PHP 7.2+)
		if (function_exists('sodium_crypto_secretbox') && function_exists('random_bytes')) {
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

		// Should not be reached under any standard PHP 7.4-8.4 installation
		return base64_encode($plaintext);
	}

	/**
	 * Decrypts an authenticated encrypted payload.
	 *
	 * @param string $encrypted_str Base64-encoded encrypted string.
	 * @return string Decrypted plaintext, or empty string on failure/tampering.
	 */
	public static function decrypt($encrypted_str) {
		if (empty($encrypted_str)) {
			return '';
		}

		$raw = base64_decode($encrypted_str, true);
		if ($raw === false) {
			return '';
		}

		$key = self::get_encryption_key();

		// Libsodium decrypt
		$sod_prefix_len = strlen(self::SODIUM_PREFIX);
		if (strncmp($raw, self::SODIUM_PREFIX, $sod_prefix_len) === 0) {
			if (!function_exists('sodium_crypto_secretbox_open')) {
				return '';
			}

			$payload    = substr($raw, $sod_prefix_len);
			$nonce_len  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

			if (strlen($payload) < $nonce_len) {
				return '';
			}

			$nonce      = substr($payload, 0, $nonce_len);
			$ciphertext = substr($payload, $nonce_len);

			$decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
			return $decrypted !== false ? $decrypted : '';
		}

		// OpenSSL AES-256-GCM decrypt
		$gcm_prefix_len = strlen(self::OPENSSL_PREFIX);
		if (strncmp($raw, self::OPENSSL_PREFIX, $gcm_prefix_len) === 0) {
			if (!function_exists('openssl_decrypt')) {
				return '';
			}

			$payload   = substr($raw, $gcm_prefix_len);
			$iv_len    = openssl_cipher_iv_length('aes-256-gcm');
			$tag_len   = 16;

			if (strlen($payload) < ($iv_len + $tag_len)) {
				return '';
			}

			$iv         = substr($payload, 0, $iv_len);
			$tag        = substr($payload, $iv_len, $tag_len);
			$ciphertext = substr($payload, $iv_len + $tag_len);

			$decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
			return $decrypted !== false ? $decrypted : '';
		}

		// Raw fallback
		return $raw;
	}
}
