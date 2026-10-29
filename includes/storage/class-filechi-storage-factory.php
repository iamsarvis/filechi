<?php
/**
 * FileChi Storage Driver Factory
 *
 * Instantiates the appropriate storage driver (SFTP, FTPS, S3)
 * based on provider configuration.
 *
 * @package FileChi\Storage
 */

defined('ABSPATH') || exit;

class FileChi_Storage_Factory {

	/**
	 * Cache of active driver instances by provider ID.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Tracks providers whose decryption failure has been logged in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $logged_decryption_failures = array();

	/**
	 * Creates a storage driver from a provider database array or provider ID.
	 *
	 * @param array|int $provider Provider record or ID.
	 * @return FileChi_Storage_Interface|null
	 */
	public static function create($provider) {
		if (is_numeric($provider)) {
			$provider_id = (int) $provider;
			if (isset(self::$instances[$provider_id])) {
				return self::$instances[$provider_id];
			}
			$provider = FileChi_DB::get_provider($provider_id, true);
		}

		if (is_array($provider) && !empty($provider['id']) && isset(self::$instances[(int) $provider['id']])) {
			return self::$instances[(int) $provider['id']];
		}

		if (!is_array($provider) || empty($provider['driver'])) {
			return null;
		}

		$driver   = sanitize_key($provider['driver']);
		$settings = is_array($provider['settings'] ?? null) ? $provider['settings'] : array();

		// If secrets could not be decrypted, do not build driver with ciphertext credentials
		if (!empty($settings['_decryption_failed']) || !empty($provider['_decryption_failed'])) {
			$provider_key = !empty($provider['id']) ? (string) $provider['id'] : (!empty($provider['name']) ? (string) $provider['name'] : 'unknown');
			if (!isset(self::$logged_decryption_failures[$provider_key])) {
				self::$logged_decryption_failures[$provider_key] = true;
				error_log(sprintf('FileChi: Stored credentials for provider "%s" could not be decrypted. Aborting storage driver creation.', $provider_key));
			}
			return null;
		}

		$provider_id = !empty($provider['id']) ? (int) $provider['id'] : 0;
		$instance    = null;

		switch ($driver) {
			case 'sftp':
				require_once dirname(__FILE__) . '/class-filechi-storage-sftp.php';
				$instance = new FileChi_Storage_SFTP($settings, $provider_id);
				break;

			case 'ftps':
				require_once dirname(__FILE__) . '/class-filechi-storage-ftps.php';
				$instance = new FileChi_Storage_FTPS($settings, $provider_id);
				break;

			case 's3':
				require_once dirname(__FILE__) . '/class-filechi-storage-s3.php';
				$instance = new FileChi_Storage_S3($settings, $provider_id);
				break;
		}

		if ($instance) {
			if ($provider_id > 0) {
				$instance->set_provider_id($provider_id);
			}
			if (!empty($provider['id'])) {
				self::$instances[(int) $provider['id']] = $instance;
			}
		}

		return $instance;
	}

	/**
	 * Retrieves the storage driver for the default active provider.
	 *
	 * @return FileChi_Storage_Interface|null
	 */
	public static function get_default() {
		$default_provider = FileChi_DB::get_default_provider();
		if (!$default_provider) {
			return null;
		}
		return self::create($default_provider);
	}

	/**
	 * Sets or overrides a driver instance for a provider ID (useful in testing).
	 *
	 * @param int                     $provider_id Provider ID.
	 * @param FileChi_Storage_Interface $instance  Driver instance.
	 */
	public static function set_instance($provider_id, $instance) {
		self::$instances[(int) $provider_id] = $instance;
	}

	/**
	 * Clears static instance and logging cache (useful in testing).
	 */
	public static function clear_instances() {
		self::$instances                   = array();
		self::$logged_decryption_failures = array();
	}
}
