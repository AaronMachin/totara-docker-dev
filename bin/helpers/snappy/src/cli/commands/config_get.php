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
            $all = $ctx->config->all();
            // Text mode pretty print; quiet suppresses.
            if (!$ctx->out->isJson() && !$ctx->out->isQuiet()) {
                $ctx->out->info(json_encode($all, JSON_PRETTY_PRINT));
            }
            $ctx->out->json(['path'=>null,'value'=>$all]);
            return 0;
        }
        $val = $ctx->config->get($path, '__MISSING__');
        if ($val === '__MISSING__') { $ctx->out->error("missing: $path", 2); return 2; }
        if (is_scalar($val) || $val === null) {
            if (!$ctx->out->isJson() && !$ctx->out->isQuiet()) { $ctx->out->info($val === null ? 'null' : (string)$val); }
            $ctx->out->json(['path'=>$path,'value'=>$val]);
            return 0;
        }
        if (!$ctx->out->isJson() && !$ctx->out->isQuiet()) { $ctx->out->info(json_encode($val, JSON_PRETTY_PRINT)); }
        $ctx->out->json(['path'=>$path,'value'=>$val]);
        return 0;
    }
}
