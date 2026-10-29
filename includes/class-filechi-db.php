<?php
/**
 * FileChi Database Access Layer
 *
 * Provides safe, prepared query access to FileChi custom tables:
 * {$wpdb->prefix}filechi_providers and {$wpdb->prefix}filechi_logs.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

class FileChi_DB {

	/**
	 * Sensitive keys in settings that must be encrypted at rest.
	 */
	private static $sensitive_keys = array(
		'password',
		'secret_key',
		'private_key',
		'passphrase',
	);

	/**
	 * Returns table name for providers.
	 *
	 * @return string
	 */
	public static function get_providers_table() {
		global $wpdb;
		return $wpdb->prefix . 'filechi_providers';
	}

	/**
	 * Returns table name for logs.
	 *
	 * @return string
	 */
	public static function get_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'filechi_logs';
	}

	/**
	 * Returns table name for files index.
	 *
	 * @return string
	 */
	public static function get_files_table() {
		global $wpdb;
		return $wpdb->prefix . 'filechi_files';
	}

	/**
	 * Retrieves all connection providers.
	 *
	 * @param bool $decrypt Whether to decrypt sensitive settings fields.
	 * @return array List of provider records.
	 */
	public static function get_providers($decrypt = false) {
		global $wpdb;
		$table = self::get_providers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY is_default DESC, id ASC", ARRAY_A);
		if (!is_array($results)) {
			return array();
		}

		foreach ($results as &$row) {
			$row['id']         = (int) $row['id'];
			$row['is_default'] = (int) $row['is_default'];
			$row['settings']   = json_decode($row['settings'], true) ?: array();

			if ($decrypt) {
				$row['settings'] = self::decrypt_settings($row['settings']);
			} else {
				// Redact secrets for safe API output
				$row['settings'] = self::redact_settings($row['settings']);
			}
		}

		return $results;
	}

	/**
	 * Retrieves a single provider by ID.
	 *
	 * @param int  $id Provider ID.
	 * @param bool $decrypt Whether to decrypt sensitive settings.
	 * @return array|null Provider row or null if not found.
	 */
	public static function get_provider($id, $decrypt = false) {
		global $wpdb;
		$table = self::get_providers_table();
		$id    = absint($id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id), ARRAY_A);

		if (!$row) {
			return null;
		}

		$row['id']         = (int) $row['id'];
		$row['is_default'] = (int) $row['is_default'];
		$row['settings']   = json_decode($row['settings'], true) ?: array();

		if ($decrypt) {
			$row['settings'] = self::decrypt_settings($row['settings']);
		} else {
			$row['settings'] = self::redact_settings($row['settings']);
		}

		return $row;
	}

	/**
	 * Retrieves the default active provider with credentials decrypted.
	 *
	 * @return array|null
	 */
	public static function get_default_provider() {
		global $wpdb;
		$table = self::get_providers_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$row = $wpdb->get_row("SELECT * FROM {$table} WHERE is_default = 1 LIMIT 1", ARRAY_A);

		if (!$row) {
			// Fallback: first provider if none explicitly marked default
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$row = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id ASC LIMIT 1", ARRAY_A);
		}

		if (!$row) {
			return null;
		}

		$row['id']         = (int) $row['id'];
		$row['is_default'] = (int) $row['is_default'];
		$row['settings']   = json_decode($row['settings'], true) ?: array();
		$row['settings']   = self::decrypt_settings($row['settings']);

		return $row;
	}

	/**
	 * Inserts a new provider profile.
	 *
	 * @param array $data Provider details (name, driver, is_default, settings).
	 * @return int|false|WP_Error Inserted ID, false on failure, or WP_Error on encryption error.
	 */
	public static function insert_provider($data) {
		global $wpdb;
		$table = self::get_providers_table();

		$name       = sanitize_text_field($data['name'] ?? '');
		$driver     = sanitize_key($data['driver'] ?? 'sftp');
		$is_default = !empty($data['is_default']) ? 1 : 0;
		$raw_settings = is_array($data['settings'] ?? null) ? $data['settings'] : array();
		$settings     = array();
		foreach ($raw_settings as $k => $v) {
			$k_str = (string) $k;
			if (strpos($k_str, '_') === 0 || substr($k_str, -18) === '_decryption_failed') {
				continue;
			}
			$settings[$k] = $v;
		}

		// Validate root_path and protected_path if provided
		if (!empty($settings['root_path'])) {
			try {
				$settings['root_path'] = FileChi_Path::normalize_root($settings['root_path']);
			} catch (FileChi_Path_Exception $e) {
				return new WP_Error('invalid_root_path', $e->getMessage(), array('status' => 400));
			}
		}
		if (!empty($settings['protected_path'])) {
			try {
				$settings['protected_path'] = FileChi_Path::normalize_root($settings['protected_path']);
			} catch (FileChi_Path_Exception $e) {
				return new WP_Error('invalid_root_path', $e->getMessage(), array('status' => 400));
			}
		}

		// Encrypt credentials before storing
		$encrypted_settings = self::encrypt_settings($settings);
		if (is_wp_error($encrypted_settings)) {
			return $encrypted_settings;
		}

		// If this is set as default or the first provider, clear any previous default
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		if ($is_default || $wpdb->get_var("SELECT COUNT(*) FROM {$table}") == 0) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$wpdb->query("UPDATE {$table} SET is_default = 0");
			$is_default = 1;
		}

		$now = current_time('mysql');

		$result = $wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'driver'     => $driver,
				'is_default' => $is_default,
				'settings'   => wp_json_encode($encrypted_settings),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%d', '%s', '%s', '%s')
		);

		if ($result) {
			if (in_array($driver, array('sftp', 'ftps'), true) && !empty(trim((string) ($settings['protected_path'] ?? '')))) {
				delete_option('filechi_protected_path_missing_notice');
			}
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Updates an existing provider profile.
	 *
	 * Merges submitted settings over existing settings and protects stored credentials from
	 * being overwritten by empty strings or failed decryptions.
	 *
	 * @param int   $id Provider ID.
	 * @param array $data New provider data.
	 * @return bool|WP_Error
	 */
	public static function update_provider($id, $data) {
		global $wpdb;
		$table = self::get_providers_table();
		$id    = absint($id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
		if (!$existing_row) {
			return false;
		}

		$existing_settings = json_decode($existing_row['settings'], true) ?: array();

		$update = array();
		$format = array();

		if (isset($data['name'])) {
			$update['name'] = sanitize_text_field($data['name']);
			$format[]       = '%s';
		}

		if (isset($data['driver'])) {
			$update['driver'] = sanitize_key($data['driver']);
			$format[]         = '%s';
		}

		if (isset($data['is_default'])) {
			$is_default = !empty($data['is_default']) ? 1 : 0;
			if ($is_default) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
				$wpdb->query("UPDATE {$table} SET is_default = 0");
			}
			$update['is_default'] = $is_default;
			$format[]             = '%d';
		}

		if (isset($data['settings']) && is_array($data['settings'])) {
			$submitted = array();
			foreach ($data['settings'] as $k => $v) {
				$k_str = (string) $k;
				if (strpos($k_str, '_') === 0 || substr($k_str, -18) === '_decryption_failed') {
					continue;
				}
				$submitted[$k] = $v;
			}

			// Clean existing settings and merge non-sensitive submitted fields
			$merged_settings = array();
			foreach ($existing_settings as $k => $v) {
				$k_str = (string) $k;
				if (strpos($k_str, '_') === 0 || substr($k_str, -18) === '_decryption_failed') {
					continue;
				}
				$merged_settings[$k] = $v;
			}

			// Copy non-sensitive submitted fields
			foreach ($submitted as $k => $v) {
				if (!in_array($k, self::$sensitive_keys, true)) {
					$merged_settings[$k] = $v;
				}
			}

			// Validate root_path and protected_path if provided
			if (!empty($merged_settings['root_path'])) {
				try {
					$merged_settings['root_path'] = FileChi_Path::normalize_root($merged_settings['root_path']);
				} catch (FileChi_Path_Exception $e) {
					return new WP_Error('invalid_root_path', $e->getMessage(), array('status' => 400));
				}
			}
			if (!empty($merged_settings['protected_path'])) {
				try {
					$merged_settings['protected_path'] = FileChi_Path::normalize_root($merged_settings['protected_path']);
				} catch (FileChi_Path_Exception $e) {
					return new WP_Error('invalid_root_path', $e->getMessage(), array('status' => 400));
				}
			}

			// If host or port changes while tls_pin is set, clear the pin (the user must trust again)
			$existing_pin = (string) ($existing_settings['tls_pin'] ?? '');
			if ($existing_pin !== '') {
				$old_host = (string) ($existing_settings['host'] ?? '');
				$new_host = isset($submitted['host']) ? (string) $submitted['host'] : $old_host;
				$old_port = isset($existing_settings['port']) ? (string) $existing_settings['port'] : '';
				$new_port = isset($submitted['port']) ? (string) $submitted['port'] : $old_port;

				if ($old_host !== $new_host || $old_port !== $new_port) {
					$merged_settings['tls_pin'] = '';
				}
			}

			// Handle sensitive keys: never overwrite stored secret with empty value when decryption failed or field was not submitted / was masked
			foreach (self::$sensitive_keys as $key) {
				if (isset($submitted[$key])) {
					$sub_val = (string) $submitted[$key];
					if ($sub_val !== '' && $sub_val !== '********') {
						$encrypted = FileChi_Crypto::encrypt($sub_val);
						if (is_wp_error($encrypted)) {
							return $encrypted;
						}
						$merged_settings[$key] = $encrypted;
					}
				}
			}

			$update['settings'] = wp_json_encode($merged_settings);
			$format[]           = '%s';
		}

		$update['updated_at'] = current_time('mysql');
		$format[]             = '%s';

		$res = $wpdb->update($table, $update, array('id' => $id), $format, array('%d'));
		if ($res !== false) {
			$driver = $update['driver'] ?? $existing_row['driver'];
			$protected_path = isset($merged_settings['protected_path']) ? $merged_settings['protected_path'] : ($existing_settings['protected_path'] ?? '');
			if (in_array($driver, array('sftp', 'ftps'), true) && !empty(trim((string) $protected_path))) {
				delete_option('filechi_protected_path_missing_notice');
			}
			return true;
		}
		return false;
	}

	/**
	 * Returns the count of attachments currently referencing a given provider ID.
	 *
	 * @param int $id Provider ID.
	 * @return int
	 */
	public static function get_provider_attachment_count($id) {
		global $wpdb;
		$id = absint($id);
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_filechi_provider_id' AND meta_value = %s",
			(string) $id
		));
	}

	/**
	 * Deletes a provider profile if no media attachments reference it.
	 *
	 * @param int $id Provider ID.
	 * @return bool|\WP_Error True on success, false or WP_Error on failure.
	 */
	public static function delete_provider($id) {
		global $wpdb;
		$table = self::get_providers_table();
		$id    = absint($id);

		// Safety Guard: Check if any attachments depend on this provider
		$count = self::get_provider_attachment_count($id);
		if ($count > 0) {
			return new \WP_Error(
				'provider_in_use',
				sprintf(
					/* translators: %d: number of attachments */
					_n(
						'Cannot delete provider: %d media attachment currently depends on this remote storage. Re-assign or migrate it before deleting.',
						'Cannot delete provider: %d media attachments currently depend on this remote storage. Re-assign or migrate them before deleting.',
						$count,
						'filechi'
					),
					$count
				),
				array('status' => 409, 'count' => $count)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$was_default = (int) $wpdb->get_var($wpdb->prepare("SELECT is_default FROM {$table} WHERE id = %d", $id));

		$res = $wpdb->delete($table, array('id' => $id), array('%d'));

		// If deleted was default, promote another
		if ($res && $was_default) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$next_id = $wpdb->get_var("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1");
			if ($next_id) {
				$wpdb->update($table, array('is_default' => 1), array('id' => $next_id), array('%d'), array('%d'));
			}
		}

		return (bool) $res;
	}

	/**
	 * Sets a specific provider as default.
	 *
	 * @param int $id Provider ID.
	 * @return bool
	 */
	public static function set_default_provider($id) {
		global $wpdb;
		$table = self::get_providers_table();
		$id    = absint($id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$wpdb->query("UPDATE {$table} SET is_default = 0");
		$res = $wpdb->update($table, array('is_default' => 1), array('id' => $id), array('%d'), array('%d'));
		return $res !== false;
	}

	/**
	 * Records a transfer/migration attempt in the log table.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param int         $provider_id   Provider ID.
	 * @param string      $file_path     Relative path or key.
	 * @param int         $file_size     Size in bytes.
	 * @param string      $status        'pending', 'transferred', 'failed', 'deleted'.
	 * @param string|null $error_message Optional error details.
	 * @return int Log ID.
	 */
	public static function log_transfer($attachment_id, $provider_id, $file_path, $file_size, $status, $error_message = null) {
		global $wpdb;
		$table = self::get_logs_table();

		$now = current_time('mysql');

		// Check if record exists for this attachment and file_path
		$existing_id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$wpdb->prepare("SELECT id FROM {$table} WHERE attachment_id = %d AND file_path = %s LIMIT 1", $attachment_id, $file_path)
		);

		if ($existing_id) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
				$wpdb->prepare("UPDATE {$table} SET provider_id = %d, file_size = %d, status = %s, error_message = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d", $provider_id, $file_size, $status, $error_message, $now, $existing_id)
			);
			return (int) $existing_id;
		}

		$wpdb->insert(
			$table,
			array(
				'attachment_id' => absint($attachment_id),
				'provider_id'   => absint($provider_id),
				'file_path'     => sanitize_text_field($file_path),
				'file_size'     => (int) $file_size,
				'status'        => sanitize_key($status),
				'error_message' => $error_message,
				'attempts'      => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array('%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s')
		);

		return $wpdb->insert_id;
	}

	/**
	 * Records or updates the current state of a file in the files index table.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $provider_id   Provider ID.
	 * @param string $local_path    Path relative to uploads base.
	 * @param string $status        'transferred' or 'failed'.
	 * @param array  $args          Optional parameters:
	 *                              - remote_key (string, defaults to $local_path)
	 *                              - size (int|null)
	 *                              - etag (string|null)
	 *                              - last_error (string|null)
	 *                              - failures (int|null)
	 *                              - role (string, default 'primary')
	 *                              - created_at (string|null)
	 *                              - updated_at (string|null)
	 * @return int|false Inserted/updated row ID or false on failure.
	 */
	public static function record_file_state($attachment_id, $provider_id, $local_path, $status, $args = array()) {
		global $wpdb;
		$table = self::get_files_table();

		$attachment_id = absint($attachment_id);
		$provider_id   = absint($provider_id);
		$local_path = sanitize_text_field($local_path);
		$path_hash  = sha1($local_path);
		$status     = sanitize_key($status);

		$remote_key = isset($args['remote_key']) && !empty($args['remote_key']) ? sanitize_text_field($args['remote_key']) : $local_path;
		$role       = isset($args['role']) && !empty($args['role']) ? sanitize_key($args['role']) : 'primary';
		$size       = isset($args['size']) ? (int) $args['size'] : null;
		$etag       = isset($args['etag']) && !empty($args['etag']) ? sanitize_text_field($args['etag']) : null;
		$last_error = isset($args['last_error']) ? $args['last_error'] : null;
		$now        = current_time('mysql');
		$created_at = !empty($args['created_at']) ? $args['created_at'] : $now;
		$updated_at = !empty($args['updated_at']) ? $args['updated_at'] : $now;

		// Check if record exists for this attachment_id, provider_id, and path_hash
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, failures FROM {$table} WHERE attachment_id = %d AND provider_id = %d AND path_hash = %s LIMIT 1",
				$attachment_id,
				$provider_id,
				$path_hash
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ($existing) {
			$existing_id       = (int) ($existing['id'] ?? 0);
			$existing_failures = (int) ($existing['failures'] ?? 0);

			if (isset($args['failures'])) {
				$failures = absint($args['failures']);
			} elseif ($status === 'failed') {
				$failures = $existing_failures + 1;
			} else {
				$failures = 0;
			}

			$update_data = array(
				'role'       => $role,
				'local_path' => $local_path,
				'remote_key' => $remote_key,
				'path_hash'  => $path_hash,
				'status'     => $status,
				'last_error' => $last_error,
				'failures'   => $failures,
				'updated_at' => $updated_at,
			);
			$format = array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s');

			if ($size !== null) {
				$update_data['size'] = $size;
				$format[]            = '%d';
			}
			if ($etag !== null) {
				$update_data['etag'] = $etag;
				$format[]            = '%s';
			}

			$res = $wpdb->update($table, $update_data, array('id' => $existing_id), $format, array('%d'));
			return ($res !== false) ? $existing_id : false;
		}

		$failures = isset($args['failures']) ? absint($args['failures']) : ($status === 'failed' ? 1 : 0);

		$insert_data = array(
			'attachment_id' => $attachment_id,
			'provider_id'   => $provider_id,
			'role'          => $role,
			'local_path'    => $local_path,
			'remote_key'    => $remote_key,
			'path_hash'     => $path_hash,
			'status'        => $status,
			'last_error'    => $last_error,
			'failures'      => $failures,
			'created_at'    => $created_at,
			'updated_at'    => $updated_at,
		);
		$format = array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s');

		if ($size !== null) {
			$insert_data['size'] = $size;
			$format[]            = '%d';
		}
		if ($etag !== null) {
			$insert_data['etag'] = $etag;
			$format[]            = '%s';
		}

		$res = $wpdb->insert($table, $insert_data, $format);
		return ($res !== false) ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Deletes all file index rows associated with an attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of deleted rows.
	 */
	public static function delete_attachment_files($attachment_id) {
		global $wpdb;
		$table         = self::get_files_table();
		$attachment_id = absint($attachment_id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE attachment_id = %d", $attachment_id));
	}

	/**
	 * Migrates a batch of status 'transferred', 'reconciled', and 'failed' rows from filechi_logs to filechi_files.
	 *
	 * @param int $batch_size Maximum rows to process in one batch.
	 * @return bool True if there are more rows to process, false if migration is complete.
	 */
	public static function migrate_logs_to_files_batch($batch_size = 500) {
		global $wpdb;

		$progress = get_option('filechi_files_migration_progress', array(
			'last_id'        => 0,
			'completed'      => false,
			'migrated_count' => 0,
		));

		if (!empty($progress['completed'])) {
			return false;
		}

		$last_id    = isset($progress['last_id']) ? absint($progress['last_id']) : 0;
		$batch_size = absint($batch_size) > 0 ? absint($batch_size) : 500;

		$logs_table = self::get_logs_table();

		// Fetch batch of rows ordered strictly by ID
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, provider_id, file_path, file_size, status, error_message, attempts, created_at, updated_at
				 FROM {$logs_table}
				 WHERE id > %d AND status IN ('transferred', 'reconciled', 'failed')
				 ORDER BY id ASC
				 LIMIT %d",
				$last_id,
				$batch_size
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if (empty($rows)) {
			$progress['completed'] = true;
			update_option('filechi_files_migration_progress', $progress);
			return false;
		}

		$now = current_time('mysql');

		foreach ($rows as $row) {
			$row_id        = absint($row['id']);
			$attachment_id = absint($row['attachment_id']);
			$provider_id   = absint($row['provider_id']);
			$local_path    = sanitize_text_field($row['file_path']);
			$remote_key    = $local_path;
			$size          = isset($row['file_size']) ? (int) $row['file_size'] : 0;
			$status        = ($row['status'] === 'failed') ? 'failed' : 'transferred';
			$last_error    = !empty($row['error_message']) ? (string) $row['error_message'] : null;
			$failures      = isset($row['attempts']) ? absint($row['attempts']) : 0;
			$created_at    = (!empty($row['created_at']) && $row['created_at'] !== '0000-00-00 00:00:00') ? $row['created_at'] : $now;
			$updated_at    = (!empty($row['updated_at']) && $row['updated_at'] !== '0000-00-00 00:00:00') ? $row['updated_at'] : $now;

			self::record_file_state(
				$attachment_id,
				$provider_id,
				$local_path,
				$status,
				array(
					'remote_key' => $remote_key,
					'size'       => $size,
					'last_error' => $last_error,
					'failures'   => $failures,
					'role'       => 'primary',
					'created_at' => $created_at,
					'updated_at' => $updated_at,
				)
			);

			$last_id = $row_id;
			$progress['migrated_count'] = ($progress['migrated_count'] ?? 0) + 1;
		}

		$progress['last_id'] = $last_id;

		if (count($rows) < $batch_size) {
			$progress['completed'] = true;
			update_option('filechi_files_migration_progress', $progress);
			return false;
		}

		update_option('filechi_files_migration_progress', $progress);
		return true;
	}

	/**
	 * Runs the files index migration with a time limit, scheduling background batches if more remain.
	 *
	 * @param float $time_limit Time limit in seconds for synchronous processing (default 3.0s).
	 * @return bool True if migration complete, false if pending further batches.
	 */
	public static function run_files_migration($time_limit = 3.0) {
		$start_time = microtime(true);

		while (microtime(true) - $start_time < $time_limit) {
			$has_more = self::migrate_logs_to_files_batch(500);
			if (!$has_more) {
				return true;
			}
		}

		// If more rows remain, enqueue async action via Action Scheduler
		if (function_exists('as_enqueue_async_action')) {
			if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('filechi_migrate_files_batch', array(), 'filechi')) {
				as_enqueue_async_action('filechi_migrate_files_batch', array(), 'filechi');
			}
		}

		return false;
	}

	/**
	 * Checks whether the initial migration from filechi_logs to filechi_files is completed.
	 *
	 * Until migration is completed, readers fall back to filechi_logs for any missing data.
	 *
	 * @return bool True if completed, false if still in progress or pending.
	 */
	public static function is_files_migration_completed() {
		$progress = get_option('filechi_files_migration_progress', array());
		return !empty($progress['completed']);
	}

	/**
	 * Retrieves file index rows for an attachment and provider indexed by local path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $provider_id   Provider ID.
	 * @return array<string, array> Keyed by local_path.
	 */
	public static function get_attachment_files_by_path($attachment_id, $provider_id) {
		global $wpdb;
		$table         = self::get_files_table();
		$attachment_id = absint($attachment_id);
		$provider_id   = absint($provider_id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$rows = $wpdb->get_results($wpdb->prepare("SELECT id, attachment_id, provider_id, role, local_path, remote_key, path_hash, size, etag, status, last_error, failures, updated_at FROM {$table} WHERE attachment_id = %d AND provider_id = %d", $attachment_id, $provider_id), ARRAY_A);

		$by_file = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$path = isset($row['local_path']) ? $row['local_path'] : '';
				if ($path !== '') {
					$by_file[$path] = $row;
				}
			}
		}

		// Fall back to filechi_logs during partial migration for anything missing
		if (!self::is_files_migration_completed()) {
			$logs = self::get_attachment_logs_by_file($attachment_id, $provider_id);
			foreach ($logs as $log_path => $log) {
				if (!isset($by_file[$log_path])) {
					$by_file[$log_path] = array(
						'id'            => 0,
						'attachment_id' => $attachment_id,
						'provider_id'   => $provider_id,
						'role'          => 'primary',
						'local_path'    => $log_path,
						'remote_key'    => $log_path,
						'path_hash'     => sha1($log_path),
						'size'          => isset($log['file_size']) ? (int) $log['file_size'] : null,
						'etag'          => null,
						'status'        => ($log['status'] === 'failed') ? 'failed' : 'transferred',
						'last_error'    => $log['error_message'] ?? null,
						'failures'      => isset($log['attempts']) ? (int) $log['attempts'] : 0,
						'updated_at'    => $log['updated_at'] ?? current_time('mysql'),
					);
				}
			}
		}

		return $by_file;
	}

	/**
	 * Retrieves transfer log rows for an attachment and provider indexed by relative file path.
	 *
	 * @param int $attachment_id
	 * @param int $provider_id
	 * @return array<string, array> Keyed by file_path.
	 */
	public static function get_attachment_logs_by_file($attachment_id, $provider_id) {
		global $wpdb;
		$table = self::get_logs_table();
		$attachment_id = absint($attachment_id);
		$provider_id   = absint($provider_id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$rows = $wpdb->get_results($wpdb->prepare("SELECT id, file_path, file_size, status, error_message, attempts, updated_at FROM {$table} WHERE attachment_id = %d AND provider_id = %d", $attachment_id, $provider_id), ARRAY_A);

		if (!is_array($rows)) {
			return array();
		}

		$by_file = array();
		foreach ($rows as $row) {
			$by_file[$row['file_path']] = $row;
		}

		return $by_file;
	}

	/**
	 * Checks whether a given relative storage key has been transferred to a provider.
	 * Reads authoritatively from filechi_files index table with dual-read fallback to logs.
	 *
	 * @param int    $provider_id Provider ID.
	 * @param string $file_path   Relative storage key.
	 * @return bool True if a transferred file record exists for this provider, false otherwise.
	 */
	public static function is_file_transferred($provider_id, $file_path) {
		global $wpdb;
		$table       = self::get_files_table();
		$provider_id = absint($provider_id);
		$file_path   = sanitize_text_field($file_path);
		$path_hash   = sha1($file_path);

		// Check against path_hash, remote_key and local_path with status = 'transferred'
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE provider_id = %d AND (path_hash = %s OR remote_key = %s OR local_path = %s) AND status = 'transferred' LIMIT 1", $provider_id, $path_hash, $file_path, $file_path));

		if (!empty($status)) {
			return true;
		}

		// Fall back to filechi_logs during partial migration
		if (!self::is_files_migration_completed()) {
			$logs_table = self::get_logs_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$log_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$logs_table} WHERE provider_id = %d AND file_path = %s AND status = 'transferred' LIMIT 1", $provider_id, $file_path));
			return !empty($log_status);
		}

		return false;
	}

	/**
	 * Retrieves paginated transfer logs.
	 *
	 * @param array $args Query filters (status, limit, offset, search).
	 * @return array
	 */
	public static function get_logs($args = array()) {
		global $wpdb;
		$table = self::get_logs_table();

		$limit  = isset($args['limit']) ? absint($args['limit']) : 20;
		$offset = isset($args['offset']) ? absint($args['offset']) : 0;
		$status = isset($args['status']) ? sanitize_key($args['status']) : '';
		$search = isset($args['search']) ? sanitize_text_field($args['search']) : '';

		$where  = array('1=1');
		$params = array();

		if (!empty($status)) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if (!empty($search)) {
			$where[]  = '(file_path LIKE %s OR attachment_id = %d)';
			$params[] = '%' . $wpdb->esc_like($search) . '%';
			$params[] = absint($search);
		}

		$where_clause = implode(' AND ', $where);

		$query_sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[]  = $limit;
		$params[]  = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic query string with placeholders only; values are passed via $params.
		$results = $wpdb->get_results($wpdb->prepare($query_sql, $params), ARRAY_A);
		return is_array($results) ? $results : array();
	}

	/**
	 * Returns total count of logs matching status.
	 *
	 * @param string $status Filter by status.
	 * @return int
	 */
	public static function get_total_logs($status = '') {
		global $wpdb;
		$table = self::get_logs_table();

		if (!empty($status)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", sanitize_key($status)));
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	}

	/**
	 * Retrieves un-migrated attachment IDs from wp_posts.
	 *
	 * @param int $limit Number of IDs to return.
	 * @return array List of attachment IDs.
	 */
	public static function get_unmigrated_attachment_ids($limit = 50) {
		global $wpdb;

		$limit = absint($limit);
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_off ON (p.ID = pm_off.post_id AND pm_off.meta_key = '_filechi_offloaded')
				 LEFT JOIN {$wpdb->postmeta} pm_give ON (p.ID = pm_give.post_id AND pm_give.meta_key = '_filechi_given_up')
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND pm_off.meta_value IS NULL
				   AND pm_give.meta_value IS NULL
				 ORDER BY p.ID DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Returns total count of attachments that have reached the maximum retry limit and given up.
	 *
	 * @return int
	 */
	public static function get_given_up_attachment_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_filechi_given_up' AND meta_value = '1'"
		);
	}

	/**
	 * Calculates overall media offload statistics for admin dashboard.
	 *
	 * @return array
	 */
	public static function get_migration_stats() {
		global $wpdb;
		$files_table = self::get_files_table();

		// Total attachments in WordPress
		$total_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// Offloaded attachments count
		$offloaded_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_filechi_offloaded' AND meta_value = '1'"
		);

		// Total bytes transferred and failed count (dual-read during migration)
		if (self::is_files_migration_completed()) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$total_bytes = (double) $wpdb->get_var("SELECT SUM(size) FROM {$files_table} WHERE status = 'transferred'");

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$failed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$files_table} WHERE status = 'failed'");
		} else {
			// During migration window, read authoritative logs table for stats so incomplete table does not report partial stats
			$logs_table = self::get_logs_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$total_bytes = (double) $wpdb->get_var("SELECT SUM(file_size) FROM {$logs_table} WHERE status IN ('transferred', 'reconciled')");

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$failed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table} WHERE status = 'failed'");
		}

		// Given-up attachments count
		$given_up_count = self::get_given_up_attachment_count();

		return array(
			'total'       => $total_attachments,
			'offloaded'   => $offloaded_attachments,
			'remaining'   => max(0, $total_attachments - $offloaded_attachments - $given_up_count),
			'failed'      => $failed_count,
			'given_up'    => $given_up_count,
			'total_bytes' => $total_bytes,
			'collisions'  => self::count_remote_key_collisions(),
		);
	}

	/**
	 * Resets failed transfers to pending for retry.
	 *
	 * @return int Number of updated rows.
	 */
	public static function reset_failed_logs() {
		global $wpdb;
		$table = self::get_logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		return (int) $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status = 'failed'");
	}

	/**
	 * Resets failure counters, error details, and given-up flags on all attachments.
	 *
	 * @return int Number of affected postmeta rows.
	 */
	public static function reset_failed_attachments() {
		global $wpdb;
		$keys = array('_filechi_given_up', '_filechi_failure_count', '_filechi_last_error', '_filechi_retry_scheduled', '_filechi_unretryable');
		$placeholders = implode(',', array_fill(0, count($keys), '%s'));

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe placeholders interpolation.
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $keys));
	}

	/**
	 * Encrypts sensitive fields in a settings array.
	 *
	 * @param mixed $settings
	 * @return array|WP_Error
	 */
	public static function encrypt_settings($settings) {
		if (!is_array($settings)) {
			return array();
		}

		foreach (self::$sensitive_keys as $key) {
			if (isset($settings[$key]) && $settings[$key] !== '' && $settings[$key] !== '********') {
				$encrypted = FileChi_Crypto::encrypt($settings[$key]);
				if (is_wp_error($encrypted)) {
					return $encrypted;
				}
				$settings[$key] = $encrypted;
			}
		}

		return $settings;
	}

	/**
	 * Decrypts sensitive fields in a settings array and propagates decryption failures explicitly.
	 *
	 * @param mixed $settings
	 * @return array Decrypted settings, with _decryption_failed = true if any secret could not be decrypted.
	 */
	public static function decrypt_settings($settings) {
		if (!is_array($settings)) {
			return array();
		}

		$has_failure = false;
		foreach (self::$sensitive_keys as $key) {
			if (isset($settings[$key]) && $settings[$key] !== '') {
				$decrypted = FileChi_Crypto::decrypt($settings[$key]);
				if ($decrypted === false) {
					$has_failure = true;
					$settings[$key . '_decryption_failed'] = true;
					$settings[$key] = '';
				} else {
					$settings[$key] = $decrypted;
				}
			}
		}

		if ($has_failure) {
			$settings['_decryption_failed'] = true;
		}

		return $settings;
	}

	/**
	 * Redacts sensitive fields for safe admin output (replaces with asterisks).
	 * If decryption fails for any stored secret, marks _decryption_failed = true so the admin UI can warn the user.
	 *
	 * @param mixed $settings
	 * @return array
	 */
	public static function redact_settings($settings) {
		if (!is_array($settings)) {
			return array();
		}

		$has_failure = false;
		foreach (self::$sensitive_keys as $key) {
			if (isset($settings[$key]) && $settings[$key] !== '') {
				$decrypted = FileChi_Crypto::decrypt($settings[$key]);
				if ($decrypted === false) {
					$has_failure = true;
					$settings[$key . '_decryption_failed'] = true;
					$settings[$key] = '';
				} else {
					$settings[$key] = '********';
				}
			}
		}

		if ($has_failure) {
			$settings['_decryption_failed'] = true;
		}

		return $settings;
	}

	/**
	 * Deletes file index rows for a specific attachment and provider.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $provider_id   Provider ID.
	 * @return int Number of deleted rows.
	 */
	public static function delete_attachment_files_for_provider($attachment_id, $provider_id) {
		global $wpdb;
		$table         = self::get_files_table();
		$attachment_id = absint($attachment_id);
		$provider_id   = absint($provider_id);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE attachment_id = %d AND provider_id = %d", $attachment_id, $provider_id));
	}

	/**
	 * Retrieves un-restored attachment IDs for a specified provider.
	 *
	 * Finds attachments where _filechi_provider_id = provider_id and _filechi_offloaded = '1'.
	 *
	 * @param int  $provider_id      Provider ID.
	 * @param int  $limit            Maximum IDs to return.
	 * @param bool $include_given_up Whether to include items that have given up.
	 * @return array<int> List of attachment post IDs.
	 */
	public static function get_unrestored_attachment_ids($provider_id, $limit = 10, $include_given_up = false) {
		global $wpdb;
		$provider_id = absint($provider_id);
		$limit       = max(1, absint($limit));

		if ($include_given_up) {
			return array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
						 INNER JOIN {$wpdb->postmeta} pm_off ON (p.ID = pm_off.post_id AND pm_off.meta_key = '_filechi_offloaded' AND pm_off.meta_value = '1')
						 WHERE p.post_type = 'attachment'
						   AND p.post_status = 'inherit'
						 ORDER BY p.ID ASC
						 LIMIT %d",
						$provider_id,
						$limit
					)
				)
			);
		}

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
					 INNER JOIN {$wpdb->postmeta} pm_off ON (p.ID = pm_off.post_id AND pm_off.meta_key = '_filechi_offloaded' AND pm_off.meta_value = '1')
					 LEFT JOIN {$wpdb->postmeta} pm_give ON (p.ID = pm_give.post_id AND pm_give.meta_key = '_filechi_restore_given_up')
					 WHERE p.post_type = 'attachment'
					   AND p.post_status = 'inherit'
					   AND (pm_give.meta_value IS NULL OR pm_give.meta_value != '1')
					 ORDER BY p.ID ASC
					 LIMIT %d",
					$provider_id,
					$limit
				)
			)
		);
	}

	/**
	 * Returns total count of attachments currently offloaded to a provider.
	 *
	 * @param int  $provider_id      Provider ID.
	 * @param bool $include_given_up Whether to include items that gave up on restore.
	 * @return int Total attachment count.
	 */
	public static function get_unrestored_attachment_count($provider_id, $include_given_up = true) {
		global $wpdb;
		$provider_id = absint($provider_id);

		if ($include_given_up) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
					 INNER JOIN {$wpdb->postmeta} pm_off ON (p.ID = pm_off.post_id AND pm_off.meta_key = '_filechi_offloaded' AND pm_off.meta_value = '1')
					 WHERE p.post_type = 'attachment'
					   AND p.post_status = 'inherit'",
					$provider_id
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_prov ON (p.ID = pm_prov.post_id AND pm_prov.meta_key = '_filechi_provider_id' AND pm_prov.meta_value = %d)
				 INNER JOIN {$wpdb->postmeta} pm_off ON (p.ID = pm_off.post_id AND pm_off.meta_key = '_filechi_offloaded' AND pm_off.meta_value = '1')
				 LEFT JOIN {$wpdb->postmeta} pm_give ON (p.ID = pm_give.post_id AND pm_give.meta_key = '_filechi_restore_given_up')
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND (pm_give.meta_value IS NULL OR pm_give.meta_value != '1')",
				$provider_id
			)
		);
	}

	/**
	 * Returns total count and bytes of primary files stored for a provider.
	 *
	 * @param int $provider_id Provider ID.
	 * @return array<string, int|float> ['count' => int, 'bytes' => float]
	 */
	public static function get_provider_files_stats($provider_id) {
		global $wpdb;
		$table       = self::get_files_table();
		$provider_id = absint($provider_id);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as total_count, SUM(size) as total_bytes FROM {$table} WHERE provider_id = %d AND role = 'primary' AND status = 'transferred'",
				$provider_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'count' => isset($row['total_count']) ? (int) $row['total_count'] : 0,
			'bytes' => isset($row['total_bytes']) ? (double) $row['total_bytes'] : 0.0,
		);
	}

	/**
	 * Resets failure and given_up flags on attachments for a provider to allow retrying restore.
	 *
	 * @param int $provider_id Provider ID.
	 * @return int Number of reset attachments.
	 */
	public static function reset_failed_restore_attachments($provider_id) {
		global $wpdb;
		$provider_id = absint($provider_id);
		$count       = 0;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_filechi_provider_id' AND meta_value = %d",
				$provider_id
			)
		);

		if (!empty($ids)) {
			foreach ($ids as $post_id) {
				delete_post_meta((int) $post_id, '_filechi_restore_failure_count');
				delete_post_meta((int) $post_id, '_filechi_restore_given_up');
				delete_post_meta((int) $post_id, '_filechi_restore_last_error');
				delete_post_meta((int) $post_id, '_filechi_restore_retry_scheduled');
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Retrieves transferred file rows matching given path_hashes using the path_hash index.
	 *
	 * @param string[] $hashes Array of SHA-1 hashes of candidate local_paths.
	 * @param int      $exclude_attachment_id Optional attachment ID to exclude.
	 * @return array<string, int> Map of path_hash => attachment_id for taken paths.
	 */
	public static function get_taken_path_hashes($hashes, $exclude_attachment_id = 0) {
		global $wpdb;
		$table = self::get_files_table();

		if (empty($hashes)) {
			return array();
		}

		$hashes = array_unique(array_filter(array_map('sanitize_text_field', $hashes)));
		if (empty($hashes)) {
			return array();
		}

		$placeholders = implode(', ', array_fill(0, count($hashes), '%s'));
		$params       = array_values($hashes);

		$query = "SELECT path_hash, attachment_id FROM {$table} WHERE path_hash IN ({$placeholders}) AND status = 'transferred'";
		if ($exclude_attachment_id > 0) {
			$query   .= ' AND attachment_id != %d';
			$params[] = (int) $exclude_attachment_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

		$taken = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (!empty($row['path_hash'])) {
					$taken[$row['path_hash']] = (int) $row['attachment_id'];
				}
			}
		}

		return $taken;
	}

	/**
	 * Retrieves attached file records from postmeta matching given relative paths.
	 *
	 * @param string[] $paths Array of relative upload paths.
	 * @param int      $exclude_attachment_id Optional attachment ID to exclude.
	 * @return array<string, int> Map of relative_path => post_id for taken paths.
	 */
	public static function get_taken_attached_files($paths, $exclude_attachment_id = 0) {
		global $wpdb;

		if (empty($paths)) {
			return array();
		}

		$paths = array_unique(array_filter(array_map('sanitize_text_field', $paths)));
		if (empty($paths)) {
			return array();
		}

		$placeholders = implode(', ', array_fill(0, count($paths), '%s'));
		$params       = array_values($paths);

		$query = "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders})";
		if ($exclude_attachment_id > 0) {
			$query   .= ' AND post_id != %d';
			$params[] = (int) $exclude_attachment_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

		$taken = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (!empty($row['meta_value'])) {
					$taken[$row['meta_value']] = (int) $row['post_id'];
				}
			}
		}

		return $taken;
	}

	/**
	 * Checks if a remote key is already transferred for another attachment on a given provider (Item F13.4).
	 *
	 * @param int    $provider_id Provider ID.
	 * @param string $remote_key Remote storage key.
	 * @param int    $exclude_attachment_id Attachment ID to exclude.
	 * @return bool True if taken by another attachment, false otherwise.
	 */
	public static function is_remote_key_taken($provider_id, $remote_key, $exclude_attachment_id = 0) {
		global $wpdb;
		$table       = self::get_files_table();
		$provider_id = absint($provider_id);
		$remote_key  = sanitize_text_field($remote_key);

		if ($provider_id <= 0 || empty($remote_key)) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$existing_aid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE provider_id = %d AND remote_key = %s AND status = 'transferred' AND attachment_id != %d LIMIT 1",
				$provider_id,
				$remote_key,
				(int) $exclude_attachment_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return !empty($existing_aid);
	}

	/**
	 * Checks if any of the given candidate remote keys are already transferred for another attachment.
	 *
	 * @param int      $provider_id Provider ID.
	 * @param string[] $remote_keys Candidate remote storage keys.
	 * @param int      $exclude_attachment_id Attachment ID to exclude.
	 * @return string[] List of colliding remote keys.
	 */
	public static function find_colliding_keys($provider_id, $remote_keys, $exclude_attachment_id = 0) {
		global $wpdb;
		$table       = self::get_files_table();
		$provider_id = absint($provider_id);

		if ($provider_id <= 0 || empty($remote_keys)) {
			return array();
		}

		$remote_keys = array_unique(array_filter(array_map('sanitize_text_field', $remote_keys)));
		if (empty($remote_keys)) {
			return array();
		}

		$placeholders = implode(', ', array_fill(0, count($remote_keys), '%s'));
		$params       = array_merge(array($provider_id), array_values($remote_keys), array((int) $exclude_attachment_id));

		$query = "SELECT DISTINCT remote_key FROM {$table} WHERE provider_id = %d AND remote_key IN ({$placeholders}) AND status = 'transferred' AND attachment_id != %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col($wpdb->prepare($query, ...$params));

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Counts distinct colliding remote keys across attachments on the same provider (Item F13.5).
	 *
	 * @return int Number of collisions.
	 */
	public static function count_remote_key_collisions() {
		global $wpdb;
		$table = self::get_files_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT provider_id, remote_key
				FROM {$table}
				WHERE status = 'transferred'
				GROUP BY provider_id, remote_key
				HAVING COUNT(DISTINCT attachment_id) > 1
			) as collisions"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count ? (int) $count : 0;
	}

	/**
	 * Audits existing remote key collisions across different attachments (Item F13.5).
	 *
	 * Identifies rows sharing provider_id + remote_key with status = 'transferred'.
	 * The lowest attachment ID is considered the owner.
	 * For other colliding attachments:
	 *  - If local file exists, sets prefix dup-<id>/, marks and queues re-offload.
	 *  - Otherwise, logs that the file cannot be recovered.
	 * Logs one event per colliding pair.
	 *
	 * @return array<int, array<string, mixed>> List of collision audit records.
	 */
	public static function find_remote_key_collisions() {
		global $wpdb;
		$table = self::get_files_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$groups = $wpdb->get_results(
			"SELECT provider_id, remote_key, COUNT(DISTINCT attachment_id) as att_count
			FROM {$table}
			WHERE status = 'transferred'
			GROUP BY provider_id, remote_key
			HAVING COUNT(DISTINCT attachment_id) > 1",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if (empty($groups) || !is_array($groups)) {
			return array();
		}

		$upload_dir = wp_upload_dir();
		$basedir    = !empty($upload_dir['basedir']) ? wp_normalize_path((string) $upload_dir['basedir']) : '';
		$audited    = array();

		foreach ($groups as $group) {
			$provider_id = (int) $group['provider_id'];
			$remote_key  = (string) $group['remote_key'];

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT attachment_id, local_path, size
					FROM {$table}
					WHERE provider_id = %d AND remote_key = %s AND status = 'transferred'
					ORDER BY attachment_id ASC",
					$provider_id,
					$remote_key
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if (empty($rows) || count($rows) < 2) {
				continue;
			}

			// Lowest attachment ID is the owner
			$owner_id = (int) $rows[0]['attachment_id'];

			// For the others:
			for ($i = 1, $count = count($rows); $i < $count; $i++) {
				$colliding_id = (int) $rows[$i]['attachment_id'];
				$local_path   = (string) $rows[$i]['local_path'];
				$clean_local  = ltrim(str_replace('\\', '/', $local_path), '/');
				$local_file   = $basedir !== '' ? $basedir . '/' . $clean_local : '';
				$local_exists = ($local_file !== '' && file_exists($local_file));

				if ($local_exists) {
					$prefix = 'dup-' . $colliding_id . '/';
					update_post_meta($colliding_id, '_filechi_remote_prefix', $prefix);
					delete_post_meta($colliding_id, '_filechi_offloaded');
					delete_post_meta($colliding_id, '_filechi_given_up');

					// Delete file index for this attachment/provider so re-offload repopulates clean with prefix
					self::delete_attachment_files_for_provider($colliding_id, $provider_id);

					if (function_exists('as_schedule_single_action')) {
						as_schedule_single_action(time() + 1, 'filechi_retry_attachment_offload', array('attachment_id' => $colliding_id), 'filechi');
					}

					self::log_transfer(
						$colliding_id,
						$provider_id,
						$remote_key,
						0,
						'queued',
						sprintf(__('Collision detected with owner attachment %1$d for key "%2$s". Re-offload queued under prefix "%3$s".', 'filechi'), $owner_id, $remote_key, $prefix)
					);

					$audited[] = array(
						'provider_id'  => $provider_id,
						'remote_key'   => $remote_key,
						'owner_id'     => $owner_id,
						'colliding_id' => $colliding_id,
						'local_exists' => true,
						'action'       => 'reoffload_queued',
					);
				} else {
					self::log_transfer(
						$colliding_id,
						$provider_id,
						$remote_key,
						0,
						'failed',
						sprintf(__('Collision detected with owner attachment %1$d for key "%2$s". Local file missing; remote file cannot be recovered.', 'filechi'), $owner_id, $remote_key)
					);

					$audited[] = array(
						'provider_id'  => $provider_id,
						'remote_key'   => $remote_key,
						'owner_id'     => $owner_id,
						'colliding_id' => $colliding_id,
						'local_exists' => false,
						'action'       => 'unrecoverable',
					);
				}
			}
		}

		return $audited;
	}
}
