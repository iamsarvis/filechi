<?php
/**
 * FileChi Prefixed Vendor Autoloader
 * Namespace: FileChi\Vendor\
 */

spl_autoload_register(function ($class) {
    $prefix_phpseclib = 'FileChi\\Vendor\\phpseclib3\\';
    $base_dir_phpseclib = __DIR__ . '/phpseclib/';

    $len_phpseclib = strlen($prefix_phpseclib);
    if (strncmp($prefix_phpseclib, $class, $len_phpseclib) === 0) {
        $relative_class = substr($class, $len_phpseclib);
        $file = $base_dir_phpseclib . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    $prefix_paragonie = 'FileChi\\Vendor\\ParagonIE\\ConstantTime\\';
    $base_dir_paragonie = __DIR__ . '/paragonie/constant_time_encoding/';

    $len_paragonie = strlen($prefix_paragonie);
    if (strncmp($prefix_paragonie, $class, $len_paragonie) === 0) {
        $relative_class = substr($class, $len_paragonie);
        $file = $base_dir_paragonie . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

if (file_exists(__DIR__ . '/phpseclib/bootstrap.php')) {
    require_once __DIR__ . '/phpseclib/bootstrap.php';
}