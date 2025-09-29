<?php

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
use Snappy\Snapshot\index_manager;
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
$index = new index_manager($snapshotBase); $manager->set_index($index);

$router = new command_router();

// Snapshot commands
$router->register('snapshot','create', new Snappy\Cli\Commands\snapshot_create());
$router->register('snapshot','list',   new Snappy\Cli\Commands\snapshot_list());
$router->register('snapshot','show',   new Snappy\Cli\Commands\snapshot_show());
$router->register('snapshot','metrics',new Snappy\Cli\Commands\snapshot_metrics());
$router->register('snapshot','export', new Snappy\Cli\Commands\snapshot_export());
$router->register('snapshot','import', new Snappy\Cli\Commands\snapshot_import());
$router->register('snapshot','delete', new Snappy\Cli\Commands\snapshot_delete());
$router->register('snapshot','apply',  new Snappy\Cli\Commands\snapshot_apply());
// Configurable root aliases: default to create/list/show/apply
$aliasMap = ['create'=>'snapshot.create','list'=>'snapshot.list','show'=>'snapshot.show','apply'=>'snapshot.apply'];
$configuredAliasMap = $config->get('aliases', null);

if(is_array($configuredAliasMap)) {
    foreach ($configuredAliasMap as $alias => $canonical) {
        if (is_string($alias) && is_string($canonical)) {
            $aliasMap[$alias] = $canonical; // override or add
        }
    }
}


foreach ($aliasMap as $alias=>$canonical) {
    if (!is_string($alias) || !is_string($canonical)) { continue; }
    if (!str_contains($canonical,'.')) { continue; }
    [$p,$s] = explode('.', $canonical, 2);
    if (!isset($p,$s) || $p!== 'snapshot') { continue; } // restrict to snapshot commands only
    try { $router->register_alias($alias,$p,$s); } catch (\Throwable $e) { /* ignore invalid */ }
}

// Add share commands
$router->register('share','create', new Snappy\Cli\Commands\share_share_create());
$router->register('share','import', new Snappy\Cli\Commands\share_share_import());

// Maintenance (gc) + Config + Remote
$router->register('gc','objects',  new Snappy\Cli\Commands\gc_objects());
$router->register('gc','temp',     new Snappy\Cli\Commands\gc_temp());
$router->register('config','get',  new Snappy\Cli\Commands\config_get());
$router->register('config','set',  new Snappy\Cli\Commands\config_set());
$router->register('remote','add',  new Snappy\Cli\Commands\remote_add());
$router->register('remote','list', new Snappy\Cli\Commands\remote_list());
$router->register('remote','remove', new Snappy\Cli\Commands\remote_remove());
$router->register('remote','pull', new Snappy\Cli\Commands\remote_pull());

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
$ctx = new context($config, $remoteRegistry, $manager, $index, $out);

try {
    $exit = $router->route($argv, $ctx);
    if ($router->last_command() === null && count($argv) >= 2) {
        $GLOBALS['__snappy_force_command'] = $argv[0].'.'.$argv[1];
    }
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
