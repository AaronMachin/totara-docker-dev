<?php
namespace Snappy\Cli;

use Snappy\Util\snapshot_uid;
use Throwable;

class publish implements command {
    public function name(): string { return 'publish'; }
    public function description(): string { return 'Publish a snapshot (by uid or unique prefix)'; }

    public function run(array $args, context $ctx): int {
        $token = $args[0] ?? '';
        if ($token === '') {
            fwrite(STDERR, "uid or prefix required\n");
            return 1;
        }
        $resolved = snapshot_uid::find_by_prefix($ctx->manager->root_dir(), $token);
        if ($resolved === '') {
            fwrite(STDERR, "no or ambiguous match for '$token'\n");
            return 1;
        }
        try {
            $count = $ctx->manager->publish($resolved);
        } catch (Throwable $e) {
            fwrite(STDERR, 'publish failed: ' . $e->getMessage() . "\n");
            return 2;
        }
        echo "published $resolved ($count objects)\n";
        return 0;
    }
}

