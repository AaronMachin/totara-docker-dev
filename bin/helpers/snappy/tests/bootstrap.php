<?php
// Test bootstrap: prefer Composer autoloader, fallback to legacy.
$composer = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer)) {
    require $composer;
}
require_once __DIR__ . '/../snappy_autoload.php';

// Ensure a temp base directory for tests (can be overridden per test)
$defaultTmp = sys_get_temp_dir() . '/snappy_test_root';
if (!is_dir($defaultTmp)) {
    @mkdir($defaultTmp, 0777, true);
}
putenv('SNAPPY_SNAPSHOT_ROOT=' . $defaultTmp);
putenv('SNAPPY_SUPPRESS_TEST_ERRORS=1');
