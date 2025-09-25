<?php
namespace Snappy\Cli;

class cat implements command {
    public function name(): string { return 'cat'; }
    public function description(): string { return 'Output raw object contents from a remote (default local)'; }

    public function run(array $args, context $ctx): int {
        $key = $args[0] ?? '';
        if ($key === '') { fwrite(STDERR, "key required\n"); return 1; }
        $remote = 'local';
        foreach ($args as $a) { if (str_starts_with($a, '--remote=')) { $remote = substr($a, 9); } }
        if (!$ctx->registry->has($remote)) { fwrite(STDERR, "unknown remote: $remote\n"); return 2; }
        try {
            $storage = $ctx->registry->storage($remote);
            $data = $storage->read_object($key);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'read failed: ' . $e->getMessage() . "\n");
            return 4;
        }
        echo $data;
        return 0;
    }
}
