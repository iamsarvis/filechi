<?php
/**
 * FileChi Vendor Scoping Script
 *
 * Scopes vendor dependencies (phpseclib 3.x and paragonie/constant_time_encoding)
 * under the FileChi\Vendor\ namespace inside includes/vendor/.
 * Leaves woocommerce/action-scheduler unscoped per its version-safe design.
 *
 * Usage: php tools/scope-vendor.php
 */

$root_dir = dirname(__DIR__);
$vendor_dir = $root_dir . '/vendor';
$target_dir = $root_dir . '/includes/vendor';

echo "==> FileChi Vendor Scoping Tool\n";

if (!is_dir($vendor_dir)) {
    fwrite(STDERR, "Error: Composer vendor directory not found. Please run 'composer install' first.\n");
    exit(1);
}

// 1. Prepare target directories
$phpseclib_target = $target_dir . '/phpseclib';
$paragonie_target = $target_dir . '/paragonie/constant_time_encoding';
$action_scheduler_target = $target_dir . '/woocommerce/action-scheduler';

@mkdir($phpseclib_target, 0755, true);
@mkdir($paragonie_target, 0755, true);
@mkdir($action_scheduler_target, 0755, true);

// Helper function to recursively copy files
function filechi_copy_recursive($src, $dst, $extensions = array('php', 'txt', 'md', 'LICENSE')) {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $src_file = $src . '/' . $file;
        $dst_file = $dst . '/' . $file;
        if (is_dir($src_file)) {
            filechi_copy_recursive($src_file, $dst_file, $extensions);
        } else {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (empty($extensions) || in_array($ext, $extensions, true) || in_array($file, array('LICENSE', 'license.txt', 'LICENSE.txt'), true)) {
                copy($src_file, $dst_file);
            }
        }
    }
    closedir($dir);
}

// 2. Copy phpseclib
$phpseclib_src = $vendor_dir . '/phpseclib/phpseclib/phpseclib';
if (is_dir($phpseclib_src)) {
    echo "==> Copying phpseclib from {$phpseclib_src}...\n";
    filechi_copy_recursive($phpseclib_src, $phpseclib_target);
    // Copy license
    if (file_exists($vendor_dir . '/phpseclib/phpseclib/LICENSE')) {
        copy($vendor_dir . '/phpseclib/phpseclib/LICENSE', $phpseclib_target . '/LICENSE');
    }
} else {
    echo "==> Using existing phpseclib in {$phpseclib_target}...\n";
}

// 3. Copy paragonie/constant_time_encoding
$paragonie_src = $vendor_dir . '/paragonie/constant_time_encoding/src';
if (is_dir($paragonie_src)) {
    echo "==> Copying paragonie/constant_time_encoding from {$paragonie_src}...\n";
    filechi_copy_recursive($paragonie_src, $paragonie_target);
    // Copy license
    if (file_exists($vendor_dir . '/paragonie/constant_time_encoding/LICENSE.txt')) {
        copy($vendor_dir . '/paragonie/constant_time_encoding/LICENSE.txt', $paragonie_target . '/LICENSE.txt');
    }
} else {
    echo "==> Using existing constant_time_encoding in {$paragonie_target}...\n";
}

// 4. Ensure woocommerce/action-scheduler is copied unscoped
$as_src = $vendor_dir . '/woocommerce/action-scheduler';
if (is_dir($as_src)) {
    echo "==> Copying Action Scheduler (unscoped) from {$as_src}...\n";
    filechi_copy_recursive($as_src, $action_scheduler_target, array());
}

// 5. Apply namespace prefixes across all copied files in includes/vendor/phpseclib and includes/vendor/paragonie
echo "==> Applying namespace prefixes...\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($phpseclib_target, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $item) {
    if ($item->isFile() && $item->getExtension() === 'php') {
        $content = file_get_contents($item->getPathname());

        // Replace phpseclib3 namespace and usages
        $content = preg_replace('/(?<!FileChi\\\\Vendor\\\\)phpseclib3\\\\/', 'FileChi\\Vendor\\phpseclib3\\', $content);
        $content = preg_replace('/(?<!FileChi\\\\Vendor\\\\)ParagonIE\\\\ConstantTime\\\\/', 'FileChi\\Vendor\\ParagonIE\\ConstantTime\\', $content);

        file_put_contents($item->getPathname(), $content);
    }
}

$iterator_paragonie = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($paragonie_target, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator_paragonie as $item) {
    if ($item->isFile() && $item->getExtension() === 'php') {
        $content = file_get_contents($item->getPathname());

        // Replace ParagonIE\ConstantTime namespace
        $content = preg_replace('/(?<!FileChi\\\\Vendor\\\\)ParagonIE\\\\ConstantTime\\\\/', 'FileChi\\Vendor\\ParagonIE\\ConstantTime\\', $content);
        $content = preg_replace('/namespace\s+(?!FileChi\\\\Vendor\\\\)ParagonIE\\\\ConstantTime;/', 'namespace FileChi\\Vendor\\ParagonIE\\ConstantTime;', $content);

        file_put_contents($item->getPathname(), $content);
    }
}

// 6. Write autoloader
$autoloader_code = <<<'PHP'
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
PHP;

file_put_contents($target_dir . '/autoload.php', $autoloader_code);

echo "==> Vendor scoping completed successfully!\n";
