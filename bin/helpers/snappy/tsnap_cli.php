<?php
/**
 * snappy snapshot service
 * Usage executed via bin/snappy wrapper.
 */

require_once __DIR__ . '/snappy_autoload.php';

use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Cli\context;
use Snappy\Cli\command;

// Build core context (multi-remote). Local remote always present.
$snapshotBase = getenv('SNAPPY_SNAPSHOT_ROOT') ?: (getenv('HOME') . '/.snappy');
$configDir = __DIR__; // always use project snappy dir for config.json
$remoteRegistry = new remote_registry($snapshotBase, $configDir);
$manager = new snapshot_manager($remoteRegistry);
$ctx = new context($remoteRegistry, $manager);

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
$exit = $commands[$cmd]->run($argv, $ctx);
exit($exit);
