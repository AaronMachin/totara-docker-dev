<?php
spl_autoload_register(function ($class) {
    if (strpos($class, 'Snappy\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('Snappy\\'));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', strtolower($relative)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

