<?php
namespace Snappy\Cli;

class fetch implements command {
    public function name(): string { return 'fetch'; }
    public function description(): string { return 'Refresh remote object list cache'; }

    public function run(array $args, context $ctx): int {
        $prefix = '';
        $limit = 100;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--prefix=')) {
                $prefix = substr($arg, 9);
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = (int)substr($arg, 8);
            } else {
                fwrite(STDERR, "unknown option: $arg\n");
                return 1;
            }
        }
        try {
            $cache = $ctx->manager->fetch_remote($prefix, $limit);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'fetch failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        $count = count($cache['objects'] ?? []);
        echo "fetched $count objects for prefix '" . ($prefix ?: '/') . "' (limit=$limit)\n";
        return 0;
    }
}

