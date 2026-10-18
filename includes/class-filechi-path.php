<?php
/**
 * FileChi Path Validator
 *
 * Provides strict validation for remote storage keys and attachment paths.
 * Rejects path traversal, control characters, backslashes, absolute paths,
 * drive letters, empty segments, and overly long paths.
 *
 * @package FileChi
 */

defined('ABSPATH') || exit;

/**
 * Exception thrown when a path violates security or format constraints.
 */
class FileChi_Path_Exception extends InvalidArgumentException {}

/**
 * Strict path validator class.
 */
class FileChi_Path {

	/**
	 * Maximum allowed path length in bytes.
	 */
	const MAX_LENGTH = 1024;

	/**
	 * Validates a relative storage key or attachment path.
	 *
	 * Rejects:
	 * - Non-string input
	 * - Paths exceeding MAX_LENGTH (1024 bytes)
	 * - Empty paths or empty path segments (//, leading /, trailing /)
	 * - Path traversal (.. segments, all-dot segments like . or ...)
	 * - Windows drive letters (e.g. C:, D:)
	 * - Backslashes (\)
	 * - NUL bytes (\0)
	 * - ASCII control characters (\x00-\x1F, \x7F)
	 * - Double/multi URL-encoded traversal or control characters
	 *
	 * Accepts:
	 * - Normal filenames with spaces, @, +, parentheses, brackets, Persian and UTF-8 characters.
	 *
	 * @param mixed $key Path or key to validate.
	 * @return string The validated, untampered relative key.
	 * @throws FileChi_Path_Exception When path fails validation.
	 */
	public static function validate($key) {
		if (!is_string($key)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: path must be a string.', 'filechi'));
		}

		// Length check (reject empty or > 1024 bytes)
		$len = strlen($key);
		if ($len === 0) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: path cannot be empty.', 'filechi'));
		}
		if ($len > self::MAX_LENGTH) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: path length exceeds maximum allowed limit (1024 characters).', 'filechi'));
		}

		// Check raw string first
		self::assert_valid_format($key);

		// Iteratively decode to defend against double and multi-encoding
		$decoded    = $key;
		$iterations = 0;
		while ($iterations < 5) {
			$next = rawurldecode($decoded);
			if ($next === $decoded) {
				break;
			}
			$decoded = $next;
			$iterations++;

			if (strlen($decoded) > self::MAX_LENGTH) {
				throw new FileChi_Path_Exception(esc_html__('Invalid path: decoded path length exceeds maximum allowed limit.', 'filechi'));
			}

			self::assert_valid_format($decoded);
		}

		return $key;
	}

	/**
	 * Internal assertion checking all format and traversal constraints on a string.
	 *
	 * @param string $path Path string to check.
	 * @throws FileChi_Path_Exception When format constraints are violated.
	 */
	private static function assert_valid_format($path) {
		// NUL byte check
		if (strpos($path, "\0") !== false) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: NUL byte detected.', 'filechi'));
		}

		// Control characters: ASCII 0x00-0x1F and 0x7F (DEL)
		if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: control characters detected.', 'filechi'));
		}

		// Backslashes
		if (strpos($path, '\\') !== false) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: backslashes are not permitted.', 'filechi'));
		}

		// Windows drive letters (e.g., C:, D:)
		if (preg_match('/^[a-zA-Z]:/', $path)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: Windows drive letters are not permitted.', 'filechi'));
		}

		// Absolute paths (starting with slash)
		if (strpos($path, '/') === 0) {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: absolute paths starting with "/" are not permitted.', 'filechi'));
		}

		// Trailing slash (cannot end in /)
		if (substr($path, -1) === '/') {
			throw new FileChi_Path_Exception(esc_html__('Invalid path: path cannot end with a trailing slash.', 'filechi'));
		}

		// Empty segments and traversal segments
		$segments = explode('/', $path);
		foreach ($segments as $segment) {
			if ($segment === '') {
				throw new FileChi_Path_Exception(esc_html__('Invalid path: empty segment detected (consecutive or stray slashes).', 'filechi'));
			}

			// Reject '.' or '..' or any segment consisting solely of dots (e.g. '...', '....')
			if ($segment === '.' || $segment === '..' || preg_match('/^\.+$/', $segment)) {
				throw new FileChi_Path_Exception(esc_html__('Invalid path: directory traversal segment detected.', 'filechi'));
			}
		}
	}

	/**
	 * Checks whether a path is valid without throwing.
	 *
	 * @param mixed $key Path or key to test.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid($key) {
		try {
			self::validate($key);
			return true;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}

	/**
	 * Normalizes and validates an administrator-configured root or protected path.
	 *
	 * Accepts:
	 * - One leading slash (absolute path, e.g. /domains/x/uploads) or none (relative to login dir, e.g. uploads)
	 * - A trailing slash (which is removed, e.g. /a/b/ becomes /a/b)
	 * - The single root '/' (returns '/')
	 * - Empty or null input (returns '')
	 * - Spaces and Persian / UTF-8 characters
	 *
	 * Rejects:
	 * - Non-string input (when not null)
	 * - Strings longer than 1024 bytes (raw and decoded)
	 * - Consecutive slashes anywhere ('//', e.g. 'a//b', '//a', 'a//')
	 * - Backslashes ('\')
	 * - Windows drive letters ('C:', 'D:')
	 * - NUL bytes and ASCII control characters (\x00-\x1F, \x7F)
	 * - Traversal segments ('..' or segments consisting solely of dots)
	 * - Double/multi-encoded traversal or invalid characters
	 *
	 * @param mixed $root The root path to normalize.
	 * @return string The normalized root path.
	 * @throws FileChi_Path_Exception When the root path fails validation.
	 */
	public static function normalize_root($root) {
		if ($root === null || $root === '') {
			return '';
		}

		if (!is_string($root)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: root path must be a string.', 'filechi'));
		}

		$len = strlen($root);
		if ($len > self::MAX_LENGTH) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: root path length exceeds maximum allowed limit (1024 characters).', 'filechi'));
		}

		// Single root '/' is explicitly allowed
		if ($root === '/') {
			return '/';
		}

		// Check raw string format
		self::assert_valid_root_format($root);

		// Iteratively decode to defend against double and multi-encoding
		$decoded    = $root;
		$iterations = 0;
		while ($iterations < 5) {
			$next = rawurldecode($decoded);
			if ($next === $decoded) {
				break;
			}
			$decoded = $next;
			$iterations++;

			if (strlen($decoded) > self::MAX_LENGTH) {
				throw new FileChi_Path_Exception(esc_html__('Invalid root path: decoded root path length exceeds maximum allowed limit.', 'filechi'));
			}

			self::assert_valid_root_format($decoded);
		}

		// Remove trailing slash if present (except single root which was already returned)
		if (substr($root, -1) === '/') {
			$root = substr($root, 0, -1);
		}

		return $root;
	}

	/**
	 * Internal assertion checking all format and traversal constraints on a root path string.
	 *
	 * @param string $path Path string to check.
	 * @throws FileChi_Path_Exception When format constraints are violated.
	 */
	private static function assert_valid_root_format($path) {
		// NUL byte check
		if (strpos($path, "\0") !== false) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: NUL byte detected.', 'filechi'));
		}

		// Control characters: ASCII 0x00-0x1F and 0x7F (DEL)
		if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: control characters detected.', 'filechi'));
		}

		// Backslashes
		if (strpos($path, '\\') !== false) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: backslashes are not permitted.', 'filechi'));
		}

		// Windows drive letters (e.g., C:, D:)
		if (preg_match('/^[a-zA-Z]:/', $path)) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: Windows drive letters are not permitted.', 'filechi'));
		}

		// Consecutive slashes anywhere ('//', e.g. empty segment in middle or leading/trailing double slash)
		if (strpos($path, '//') !== false) {
			throw new FileChi_Path_Exception(esc_html__('Invalid root path: empty segment detected (consecutive slashes).', 'filechi'));
		}

		// Single root '/' is valid
		if ($path === '/') {
			return;
		}

		// Check segments for traversal
		$stripped = $path;
		if (strpos($stripped, '/') === 0) {
			$stripped = substr($stripped, 1);
		}
		if (substr($stripped, -1) === '/') {
			$stripped = substr($stripped, 0, -1);
		}

		if ($stripped !== '') {
			$segments = explode('/', $stripped);
			foreach ($segments as $segment) {
				if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^\.+$/', $segment)) {
					throw new FileChi_Path_Exception(esc_html__('Invalid root path: directory traversal segment detected.', 'filechi'));
				}
			}
		}
	}

	/**
	 * Checks whether a root path is valid without throwing.
	 *
	 * @param mixed $root Root path to test.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_root($root) {
		try {
			self::normalize_root($root);
			return true;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}

	/**
	 * Recorded hashes of path errors already logged in the current request.
	 *
	 * @var array<string, bool>
	 */
	private static $logged_errors = array();

	/**
	 * Logs a path validation failure once per key per request.
	 *
	 * @param mixed  $key The invalid path or key.
	 * @param string $message The exception error message.
	 */
	public static function log_error($key, $message) {
		$hash = md5((string) $key);
		if (!isset(self::$logged_errors[$hash])) {
			self::$logged_errors[$hash] = true;
			error_log(sprintf('FileChi: Invalid remote storage path encountered: "%s". Error: %s', (string) $key, $message));
		}
	}

	/**
	 * Clears logged errors record (useful for unit tests).
	 */
	public static function reset_logged_errors() {
		self::$logged_errors = array();
	}
}
