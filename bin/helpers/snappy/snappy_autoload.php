<?php
spl_autoload_register(function ($class) {
    if (strpos($class, 'Snappy\\') !== 0) { return; }
    $relative = substr($class, strlen('Snappy\\'));
    $lowerPath = __DIR__ . '/src/' . str_replace('\\', '/', strtolower($relative)) . '.php';
    if (is_file($lowerPath)) { require $lowerPath; return; }
    $psrPath = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($psrPath)) { require $psrPath; return; }
});
