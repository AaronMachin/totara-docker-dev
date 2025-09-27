<?php
/**
 * snappy snapshot service
 * Usage executed via bin/snappy wrapper.
 */

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    // Include legacy autoloader too (temporary) because current directory/file casing is lowercase
    // and does not fully conform to PSR-4 expectations. This preserves functionality until T1.2.
    require_once __DIR__ . '/snappy_autoload.php';
} else {
    require_once __DIR__ . '/snappy_autoload.php';
}

use Snappy\Cli\context;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Cli\command;
use Snappy\Config\config_manager;

// Build core context (multi-remote). Local remote always present.
// Load config first (using a provisional base path); then derive snapshot root from config schema default/user value.
$home = getenv('HOME') ?: '~';
$configDir = __DIR__;
$configFile = $configDir . '/config.json';
$provisionalBase = $home . '/.snappy';
$config = new config_manager($configFile, $provisionalBase);
$snapshotBase = $config->get('options.snapshot_root', $provisionalBase);
$remoteRegistry = new remote_registry($config, $snapshotBase);
$manager = new snapshot_manager($remoteRegistry);
$cache = new remote_snapshot_cache($snapshotBase);
$manager->set_cache($cache);
$ctx = new context($config, $remoteRegistry, $manager, $cache);

// Dynamic command discovery
$commands = [];
$commands_dir = __DIR__ . '/src/cli/commands';
if (is_dir($commands_dir)) {
    foreach (glob($commands_dir . '/*.php') as $file) {
        $baseName = basename($file, '.php');
        $class = 'Snappy\\Cli\\Commands\\' . $baseName;
        if (class_exists($class)) {
            $instance = new $class();
            if ($instance instanceof command) {
                $commands[$instance->name()] = $instance;
            }
        }
    }
}

// Provide help with complete registry
if (isset($commands['help'])) {
    $help = $commands['help'];
    if (method_exists($help, 'set_commands')) {
        $help->set_commands($commands);
    }
}

array_shift($argv); // script name
$cmd = $argv[0] ?? 'help';
if (!isset($commands[$cmd])) {
    fwrite(STDERR, "unknown command: $cmd\n\n");
    if (isset($commands['help'])) {
        $commands['help']->run([], $ctx);
    } else {
        fwrite(STDERR, 'Error running help command' . "\n");
    }
    exit(1);
}
array_shift($argv); // remove command token

// Support tsnap <command> --help / -h
foreach ($argv as $v) {
    if ($v === '--help' || $v === '-h') {
        if (method_exists($commands[$cmd], 'display_help')) {
            $commands[$cmd]->display_help();
            echo "\n"; // final newline
        } else {
            echo $commands[$cmd]->usage() . "\n"; // fallback
        }
        exit(0);
    }
}

$exit = $commands[$cmd]->run($argv, $ctx);
exit($exit);
