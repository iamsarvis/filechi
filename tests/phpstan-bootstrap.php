<?php
/**
 * PHPStan Bootstrap File
 *
 * Defines constants needed during static analysis.
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('FILECHI_VERSION')) {
	define('FILECHI_VERSION', '1.0.0');
}
if (!defined('FILECHI_FILE')) {
	define('FILECHI_FILE', dirname(__DIR__) . '/filechi.php');
}
if (!defined('FILECHI_DIR')) {
	define('FILECHI_DIR', dirname(__DIR__) . '/');
}
if (!defined('FILECHI_URL')) {
	define('FILECHI_URL', 'https://example.com/wp-content/plugins/filechi/');
}
if (!defined('FILECHI_MIN_PHP')) {
	define('FILECHI_MIN_PHP', '7.4');
}
if (!defined('FILECHI_MIN_WP')) {
	define('FILECHI_MIN_WP', '6.0');
}
