<?php
/**
 * snappy snapshot service
 * Usage executed via bin/snappy wrapper.
 */

require_once __DIR__ . '/snappy_autoload.php';

use Snappy\Storage\s3_storage;
use Snappy\Snapshot\remote_cache;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Cli\context;
use Snappy\Cli\command;

// Build core context
try {
    $storage = new s3_storage();
} catch (Throwable $e) {
    fwrite(STDERR, 'config error: ' . $e->getMessage() . "\n");
    exit(2);
}
$root = getenv('SNAPPY_SNAPSHOT_ROOT') ?: (getenv('HOME') . '/.snappy/snaps');
$cache = new remote_cache($root);
$manager = new snapshot_manager($storage, $cache, $root);
$ctx = new context($storage, $cache, $manager, $root);

// Dynamic command discovery
$registry = [];
$cli_dir = __DIR__ . '/src/cli';
if (is_dir($cli_dir)) {
    foreach (glob($cli_dir . '/*.php') as $file) {
        $base = basename($file, '.php');
        if (in_array($base, ['command','context'])) {
            continue;
        }
        $class = 'Snappy\\Cli\\' . $base;
        if (class_exists($class)) {
            $instance = new $class();
            if ($instance instanceof command) {
                $registry[$instance->name()] = $instance;
            }
        }
    }
}

// Provide help with complete registry
if (isset($registry['help'])) {
    $help = $registry['help'];
    if (method_exists($help, 'set_commands')) {
        $help->set_commands($registry);
    }
}

array_shift($argv); // script name
$cmd = $argv[0] ?? 'help';
if (!isset($registry[$cmd])) {
    fwrite(STDERR, "unknown command: $cmd\n\n");
    if (isset($registry['help'])) {
        $registry['help']->run([], $ctx);
    } else {
        fwrite(STDERR, 'Error running help command' . "\n");
    }
    exit(1);
}
array_shift($argv); // remove command token
$exit = $registry[$cmd]->run($argv, $ctx);
exit($exit);
