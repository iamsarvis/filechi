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

		if (!is_array($provider) || empty($provider['driver'])) {
			return null;
		}

		$driver   = sanitize_key($provider['driver']);
		$settings = is_array($provider['settings'] ?? null) ? $provider['settings'] : array();

		$instance = null;

		switch ($driver) {
			case 'sftp':
				require_once dirname(__FILE__) . '/class-filechi-storage-sftp.php';
				$instance = new FileChi_Storage_SFTP($settings);
				break;

			case 'ftps':
				require_once dirname(__FILE__) . '/class-filechi-storage-ftps.php';
				$instance = new FileChi_Storage_FTPS($settings);
				break;

			case 's3':
				require_once dirname(__FILE__) . '/class-filechi-storage-s3.php';
				$instance = new FileChi_Storage_S3($settings);
				break;
		}

		if ($instance && !empty($provider['id'])) {
			self::$instances[(int) $provider['id']] = $instance;
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
}
