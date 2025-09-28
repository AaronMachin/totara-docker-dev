<?php
spl_autoload_register(function ($class) {
    if (strpos($class, 'Snappy\\') !== 0) { return; }
    $relative = substr($class, strlen('Snappy\\'));
    $baseDir = __DIR__ . '/src/';
    $lowerPath = $baseDir . str_replace('\\', '/', strtolower($relative)) . '.php';
    if (is_file($lowerPath)) { require $lowerPath; return; }
    // Try snake_case for final segment (e.g. ProcessFailedException => process_failed_exception.php)
    $segments = explode('\\', $relative);
    $last = array_pop($segments);
    $snakeLast = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $last));
    $snakePath = $baseDir . implode('/', array_map('strtolower', $segments)) . '/' . $snakeLast . '.php';
    if (is_file($snakePath)) { require $snakePath; return; }
    $psrPath = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($psrPath)) { require $psrPath; return; }
});
