<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class config_set extends base_command {
    public function name(): string { return 'config.set'; }
    public function description(): string { return 'Set (and optionally persist) a configuration value'; }
    public function usage(): string { return 'Usage: tsnap config set <path> <value> [--persist]\nSets a configuration value (dot path). By default only in-memory until another write triggers save or you pass --persist.'; }

    public function run(array $args, context $ctx): int {
        $persist = false; $pos = [];
        foreach ($args as $a) { if ($a==='--persist') { $persist = true; } else { $pos[] = $a; } }
        if (count($pos) < 2) { $ctx->out->error('path and value required', 1); return 1; }
        [$path,$rawVal] = [$pos[0], $pos[1]];
        $val = $this->coerce($rawVal);
        $ctx->config->set($path, $val, $persist);
        $ctx->out->info('updated '.($persist ? '(persisted) ' : '(in-memory) ').$path);
        $ctx->out->json(['path'=>$path,'value'=>$val,'persisted'=>$persist]);
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
