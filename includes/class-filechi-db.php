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

		return $result ? $wpdb->insert_id : false;
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
		return $res !== false;
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
				 LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_filechi_offloaded')
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND pm.meta_value IS NULL
				 ORDER BY p.ID DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Calculates overall media offload statistics for admin dashboard.
	 *
	 * @return array
	 */
	public static function get_migration_stats() {
		global $wpdb;
		$logs_table = self::get_logs_table();

		// Total attachments in WordPress
		$total_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// Offloaded attachments count
		$offloaded_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_filechi_offloaded' AND meta_value = '1'"
		);

		// Total bytes transferred
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$total_bytes = (double) $wpdb->get_var("SELECT SUM(file_size) FROM {$logs_table} WHERE status = 'transferred'");

		// Failed count
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table name interpolation.
		$failed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table} WHERE status = 'failed'");

		return array(
			'total'       => $total_attachments,
			'offloaded'   => $offloaded_attachments,
			'remaining'   => max(0, $total_attachments - $offloaded_attachments),
			'failed'      => $failed_count,
			'total_bytes' => $total_bytes,
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
}
