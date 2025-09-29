<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class config_set extends base_command {
    public function name(): string { return 'config.set'; }
    public function description(): string { return 'Set (and persist) a configuration value'; }
    public function usage(): string { return 'Usage: tsnap config set <path> <value>\nSets and immediately persists a configuration value (dot path).'; }
    public function examples(): array { return [
        'tsnap config set options.snapshot_root /data/snaps',
        'tsnap config set aliases.metrics snapshot.metrics',
    ]; }

    public function run(array $args, context $ctx): int {
        if (count($args) < 2) { $ctx->out->error('path and value required', 1); return 1; }
        [$path,$rawVal] = [$args[0], $args[1]];
        $val = $this->coerce($rawVal);
        $ctx->config->set($path, $val, true); // always persist
        $ctx->out->info('updated '.$path);
        $ctx->out->json(['path'=>$path,'value'=>$val,'persisted'=>true]);
        $GLOBALS['__snappy_force_command'] = 'config.set';
        return 0;
    }

    private function coerce(string $v) {
        $l = strtolower($v);
        if ($l === 'null') return null;
        if ($l === 'true') return true;
        if ($l === 'false') return false;
        if (preg_match('/^-?\d+$/', $v)) { return (int)$v; }
        if (preg_match('/^-?\d+\.\d+$/', $v)) { return (float)$v; }
        return $v;
    }
}
