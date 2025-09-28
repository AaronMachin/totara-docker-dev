<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class index extends base_command {
    // Renamed logically to 'snapshot' command (keeping class name for minimal change) providing nested subcommands
    public function name(): string { return 'snapshot'; }
    public function description(): string { return 'Snapshot operations (index maintenance)'; }
    public function usage(): string { return "Usage:\n  tsnap snapshot index rebuild\nRebuilds local snapshots/index.json from snapshot directories."; }
    public function examples(): array { return ['tsnap snapshot index rebuild']; }

    public function run(array $args, context $ctx): int {
        $group = $args[0] ?? '';
        if ($group === '' || in_array($group, ['-h','--help'])) { $this->display_help(); return 1; }
        if ($group !== 'index') { fwrite(STDERR, "unknown snapshot subcommand group: $group\n"); return 2; }
        array_shift($args); // remove 'index'
        $sub = $args[0] ?? '';
        if ($sub !== 'rebuild') { fwrite(STDERR, "unknown snapshot index action (expected 'rebuild')\n"); return 2; }
        try { $ctx->index->rebuild(); echo "rebuilt local snapshot index\n"; return 0; }
        catch (\Throwable $e) { fwrite(STDERR,'rebuild failed: '.$e->getMessage()."\n"); return 1; }
    }
}
