<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class share_list extends base_command {
    public function name(): string { return 'share.list'; }
    public function description(): string { return 'List share artifacts (placeholder)'; }
    public function usage(): string { return 'Usage: tsnap share list\nLists available share artifacts (not yet implemented; placeholder outputs none).'; }
    public function examples(): array { return [ 'tsnap share list' ]; }

    public function run(array $args, context $ctx): int {
        $ctx->out->info('(share listing not implemented in current scope)');
        $ctx->out->json(['shares'=>[],'implemented'=>false]);
        return 0;
    }
}
