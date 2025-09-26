<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Throwable;

class push implements command {
    public function name(): string {
        return 'push';
    }

    public function description(): string {
        return 'Push a local snapshot to a remote: push <uid|prefix> [remote]';
    }

    public function run(array $args, context $ctx): int {
        $token = $args[0] ?? '';
        if ($token === '') {
            fwrite(STDERR, "uid or unique prefix required\n");
            return 1;
        }
        $target = $args[1] ?? '';
        if ($target === '') {
            $target = $ctx->registry->default();
            if (!$target) {
                fwrite(STDERR, "no target remote specified and no non-local remotes configured\n");
                return 2;
            }
        }
        if ($target === 'local') {
            fwrite(STDERR, "target remote cannot be 'local'\n");
            return 2;
        }
        if (!$ctx->registry->has($target)) {
            fwrite(STDERR, "remote does not exist: $target\n");
            return 2;
        }
        $resolved = $ctx->manager->resolve_uid($token, 'local');
        if ($resolved === '') {
            fwrite(STDERR, "no or ambiguous match for '$token'\n");
            return 1;
        }
        try {
            $count = $ctx->manager->push($resolved, $target, 'local');
        } catch (Throwable $e) {
            fwrite(STDERR, 'push failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo "pushed $resolved to $target ($count objects)\n";
        return 0;
    }
}

