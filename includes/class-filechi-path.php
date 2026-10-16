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
}
