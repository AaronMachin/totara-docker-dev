<?php
namespace Snappy\Cli;

class cat implements command {
    public function name(): string { return 'cat'; }
    public function description(): string { return 'Output raw remote object contents'; }

    public function run(array $args, context $ctx): int {
        $key = $args[0] ?? '';
        if ($key === '') {
            fwrite(STDERR, "key required\n");
            return 1;
        }
        try {
            $data = $ctx->storage->read_object($key);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'read failed: ' . $e->getMessage() . "\n");
            return 4;
        }
        echo $data;
        return 0;
    }
}

