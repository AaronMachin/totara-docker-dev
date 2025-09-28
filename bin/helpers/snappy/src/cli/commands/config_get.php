<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class config_get extends base_command {
    public function name(): string { return 'config.get'; }
    public function description(): string { return 'Get configuration value(s)'; }
    public function usage(): string { return 'Usage: tsnap config get [path]
If path omitted prints full resolved config JSON. Path uses dot notation.'; }

    public function run(array $args, context $ctx): int {
        $path = $args[0] ?? '';
        if ($path === '') {
            echo json_encode($ctx->config->all(), JSON_PRETTY_PRINT)."\n";
            return 0;
        }
        $val = $ctx->config->get($path, '__MISSING__');
        if ($val === '__MISSING__') { fwrite(STDERR, "missing: $path\n"); return 2; }
        if (is_scalar($val) || $val === null) {
            if ($val === null) { echo "null\n"; }
            else { echo (string)$val."\n"; }
            return 0;
        }
        echo json_encode($val, JSON_PRETTY_PRINT)."\n"; return 0;
    }
}

