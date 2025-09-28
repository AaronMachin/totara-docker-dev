<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class remote_remove extends base_command {
    public function name(): string { return 'remote.remove'; }
    public function description(): string { return 'Remove a configured snapshot remote'; }
    public function usage(): string { return 'Usage: tsnap remote remove <name>'; }

    public function run(array $args, context $ctx): int {
        $name = $args[0] ?? '';
        if ($name === '') { $ctx->out->error('remote remove requires <name>', 1); return 1; }
        if ($name === 'local') { $ctx->out->error('cannot remove reserved remote local', 2); return 2; }
        try {
            $ctx->registry->remove($name);
        } catch (\Snappy\Support\Exception\SnappyException $e) {
            $code = \Snappy\Support\Exception\ExitCodes::codeFor($e);
            $ctx->out->error($e->getMessage(), $code);
            return $code;
        } catch (\Throwable $e) {
            $ctx->out->error('remove failed: '.$e->getMessage(), 1);
            return 1;
        }
        $ctx->out->info('removed remote '.$name);
        $ctx->out->json(['action'=>'remove','name'=>$name]);
        return 0;
    }
}

