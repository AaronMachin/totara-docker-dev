<?php
/**
 * Snappy hierarchical CLI entrypoint (trim in progress: share/remote commands unregistered; underlying remote code still present until fully removed in T13A follow-up edits).
 */

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    require_once __DIR__ . '/snappy_autoload.php';
} else {
    require_once __DIR__ . '/snappy_autoload.php';
}

use Snappy\Cli\context;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Snapshot\index_manager;
use Snappy\Snapshot\remote_index_manager;
use Snappy\Cli\command_router;
use Snappy\Cli\output_formatter;
use Snappy\Config\config_manager;
use Snappy\Util\color;

// Core config & paths
$home = getenv('HOME') ?: '~';
$configDir = __DIR__;
$configFile = ($f = getenv('SNAPPY_CONFIG_FILE')) ? $f : ($configDir . '/config.json');
$provisionalBase = getenv('SNAPPY_PROVISIONAL_BASE') ?: ($home . '/.snappy');
$config = new config_manager($configFile, $provisionalBase);
$snapshotBase = ($b = getenv('SNAPPY_SNAPSHOT_BASE')) ? $b : $config->get('options.snapshot_root', $provisionalBase);

$remoteRegistry = new remote_registry($config, $snapshotBase);
$manager = new snapshot_manager($remoteRegistry);
$cache = new remote_snapshot_cache($snapshotBase); $manager->set_cache($cache);
$index = new index_manager($snapshotBase); $manager->set_index($index);
$remoteIndex = new remote_index_manager($remoteRegistry); $manager->set_remote_index($remoteIndex);

$router = new command_router();

// Snapshot commands (retain minimal surface)
$router->register('snapshot','create', new Snappy\Cli\Commands\snapshot_create());
$router->register('snapshot','list',   new Snappy\Cli\Commands\snapshot_list());
$router->register('snapshot','show',   new Snappy\Cli\Commands\snapshot_show());
$router->register('snapshot','tag',    new Snappy\Cli\Commands\snapshot_tag());
$router->register('snapshot','metrics',new Snappy\Cli\Commands\snapshot_metrics());

// Prune / Verify / GC / Doctor / Config
$router->register('prune','run',   new Snappy\Cli\Commands\prune_run());
$router->register('verify','run',  new Snappy\Cli\Commands\verify_run());
$router->register('gc','objects',  new Snappy\Cli\Commands\gc_objects());
$router->register('doctor','run',  new Snappy\Cli\Commands\doctor_run());
$router->register('config','get',  new Snappy\Cli\Commands\config_get());
$router->register('config','set',  new Snappy\Cli\Commands\config_set());

array_shift($argv); // remove script name

// Global flags
$jsonMode=false;$quiet=false;$noColor=false;$filtered=[];
foreach ($argv as $a) {
    if ($a==='--json'){ $jsonMode=true; continue; }
    if ($a==='--quiet'){ $quiet=true; continue; }
    if ($a==='--no-color'){ $noColor=true; continue; }
    $filtered[]=$a;
}
$argv=$filtered; if ($noColor) { color::disable(); }
$out = new output_formatter($jsonMode,$quiet);
$ctx = new context($config, $remoteRegistry, $manager, $cache, $index, $remoteIndex, $out);

try {
    $exit = $router->route($argv, $ctx);
    $out->flush(method_exists($router,'last_command')? $router->last_command():null, $exit===0?'ok':'error');
} catch (\Snappy\Support\Exception\SnappyException $e) {
    $code = \Snappy\Support\Exception\ExitCodes::codeFor($e);
    if ($jsonMode) { $out->error($e->getMessage(), $code); $out->flush(method_exists($router,'last_command')? $router->last_command():null, 'error'); exit($code);}
    fwrite(STDERR,'ERROR('.$code.'): '.$e->getMessage()."\n"); exit($code);
} catch (\Throwable $e) {
    $code = \Snappy\Support\Exception\ExitCodes::UNKNOWN;
    if ($jsonMode) { $out->error($e->getMessage(), $code); $out->flush(method_exists($router,'last_command')? $router->last_command():null, 'error'); exit($code);}
    fwrite(STDERR,'ERROR('.$code.'): '.$e->getMessage()."\n"); exit($code);
}
exit($exit);
