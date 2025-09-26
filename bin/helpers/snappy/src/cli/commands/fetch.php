<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Throwable;

class fetch implements command {
    public function name(): string {
        return 'fetch';
    }

    public function description(): string {
        return 'Fetch remote data';
    }

    public function run(array $args, context $ctx): int {
        $remote = 'local';
        $limit = 100;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--remote=')) {
                $remote = substr($arg, 9);
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = (int) substr($arg, 8);
            }
        }
        if (!$ctx->registry->has($remote)) {
            fwrite(STDERR, "unknown store: $remote\n");
            return 2;
        }
        try {
            $rows = $ctx->manager->list($remote, false, $limit);
        } catch (Throwable $e) {
            fwrite(STDERR, 'fetch failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo 'fetched ' . count($rows) . " snapshots from $remote (limit=$limit)\n";
        return 0;
    }
}

