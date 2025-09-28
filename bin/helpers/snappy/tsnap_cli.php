<?php
/**
 * Snappy hierarchical CLI entrypoint.
 */

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    require_once __DIR__ . '/snappy_autoload.php'; // legacy casing support
} else {
    require_once __DIR__ . '/snappy_autoload.php';
}

use Snappy\Cli\context;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Config\config_manager;
use Snappy\Snapshot\index_manager;
use Snappy\Snapshot\remote_index_manager;
use Snappy\Cli\command_router;

// Build core context (same as previous version)
$home = getenv('HOME') ?: '~';
$configDir = __DIR__;
$overrideConfig = getenv('SNAPPY_CONFIG_FILE');
$configFile = $overrideConfig !== false && $overrideConfig !== '' ? $overrideConfig : ($configDir . '/config.json');
$provisionalBase = getenv('SNAPPY_PROVISIONAL_BASE');
if ($provisionalBase === false || $provisionalBase === '') { $provisionalBase = $home . '/.snappy'; }
$config = new config_manager($configFile, $provisionalBase);
$overrideSnapshotBase = getenv('SNAPPY_SNAPSHOT_BASE');
$snapshotBase = ($overrideSnapshotBase !== false && $overrideSnapshotBase !== '') ? $overrideSnapshotBase : $config->get('options.snapshot_root', $provisionalBase);
$remoteRegistry = new remote_registry($config, $snapshotBase);
$manager = new snapshot_manager($remoteRegistry);
$cache = new remote_snapshot_cache($snapshotBase); $manager->set_cache($cache);
$index = new index_manager($snapshotBase); $manager->set_index($index);
$remoteIndex = new remote_index_manager($remoteRegistry); $manager->set_remote_index($remoteIndex);
$ctx = new context($config, $remoteRegistry, $manager, $cache, $index, $remoteIndex);

// Instantiate router and register hierarchical commands
$router = new command_router();

// Snapshot commands
$router->register('snapshot','create', new Snappy\Cli\Commands\snapshot_create());
$router->register('snapshot','list',   new Snappy\Cli\Commands\snapshot_list());
$router->register('snapshot','show',   new Snappy\Cli\Commands\snapshot_show());

// Share commands
$router->register('share','create', new Snappy\Cli\Commands\share_create());
$router->register('share','list',   new Snappy\Cli\Commands\share_list());

// Prune
$router->register('prune','run', new Snappy\Cli\Commands\prune_run());

// Verify
$router->register('verify','run', new Snappy\Cli\Commands\verify_run());

// Config
$router->register('config','get', new Snappy\Cli\Commands\config_get());
$router->register('config','set', new Snappy\Cli\Commands\config_set());

// After registrations
if (getenv('SNAPPY_DEBUG_CLI')) { file_put_contents('/tmp/snappy_cli_debug.log', date('c')." manifest=".json_encode($router->manifest())."\n", FILE_APPEND); }

// Shift script name
array_shift($argv);

if (getenv('SNAPPY_DEBUG_CLI')) { fwrite(STDERR, "[snappy-cli] boot\n"); }
try {
    $exit = $router->route($argv, $ctx);
    if (getenv('SNAPPY_DEBUG_CLI')) { fwrite(STDERR, "[snappy-cli] exit=$exit\n"); }
} catch (\Snappy\Support\Exception\SnappyException $e) {
    $code = \Snappy\Support\Exception\ExitCodes::codeFor($e);
    fwrite(STDERR, 'ERROR(' . $code . '): ' . $e->getMessage() . "\n");
    exit($code);
} catch (\Throwable $e) {
    $code = \Snappy\Support\Exception\ExitCodes::UNKNOWN;
    fwrite(STDERR, 'ERROR(' . $code . '): ' . $e->getMessage() . "\n");
    exit($code);
}
exit($exit);
