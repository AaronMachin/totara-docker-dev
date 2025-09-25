<?php
namespace Snappy\Cli;

class fetch implements command {
    public function name(): string { return 'fetch'; }
    public function description(): string { return 'Force list of a remote (compat stub for old cache refresh)'; }

    public function run(array $args, context $ctx): int {
        $remote = 'local';
        $limit = 100;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--remote=')) { $remote = substr($arg,9); }
            elseif (str_starts_with($arg, '--limit=')) { $limit = (int)substr($arg,8); }
            else if ($arg !== $args[0]) { /* ignore unknown for now */ }
        }
        if (!$ctx->registry->has($remote)) { fwrite(STDERR, "unknown remote: $remote\n"); return 2; }
        try {
            $rows = $ctx->manager->list($remote, false, $limit);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'fetch failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        $count = count($rows);
        echo "fetched $count snapshots from $remote (limit=$limit)\n";
        return 0;
    }
}
