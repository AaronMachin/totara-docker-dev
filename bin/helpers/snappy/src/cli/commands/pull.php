<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Throwable;

class pull implements command {
    public function name(): string {
        return 'pull';
    }

    public function description(): string {
        return 'Retrieve a snapshot from a remote into local storage (get <uid|prefix> [--remote=name] [--force])';
    }

    public function run(array $args, context $ctx): int {
        $token = $args[0] ?? '';
        if ($token === '' || str_starts_with($token, '--')) {
            fwrite(STDERR, "usage: snappy get <uid|prefix> [--remote=name] [--force]\n");
            return 1;
        }
        $remoteOpt = null;
        $force = false;
        for ($i = 1; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--remote=')) {
                $remoteOpt = substr($arg, 9);
            } elseif ($arg === '--force') {
                $force = true;
            } else {
                fwrite(STDERR, "unknown option: $arg\n");
                return 1;
            }
        }
        // If remote specified, pull directly
        if ($remoteOpt !== null) {
            if ($remoteOpt === 'local') {
                fwrite(STDERR, "--remote cannot be 'local' (already local store)\n");
                return 2;
            }
            if (!$ctx->registry->has($remoteOpt)) {
                fwrite(STDERR, "unknown remote: $remoteOpt\n");
                return 2;
            }
            try {
                $uid = $ctx->manager->pull($token, $remoteOpt, $force);
            } catch (Throwable $e) {
                fwrite(STDERR, 'pull failed: ' . $e->getMessage() . "\n");
                return 3;
            }
            echo "retrieved snapshot $uid from $remoteOpt into local\n";
            return 0;
        }
        // No remote specified: attempt unique resolution across non-local remotes.
        $matches = [];
        foreach ($ctx->registry->names() as $r) {
            if ($r === 'local') {
                continue;
            }
            $resolved = $ctx->manager->resolve_uid($token, $r);
            if ($resolved !== '') {
                $matches[$r] = $resolved;
            }
        }
        if (!$matches) {
            fwrite(STDERR, "no matching snapshot for prefix '$token' on any remote; specify --remote if needed\n");
            return 4;
        }
        // If snapshot appears on more than one remote (even if same uid), require explicit remote.
        if (count($matches) > 1) {
            $list = [];
            foreach ($matches as $r => $u) {
                $list[] = $r . '(' . $u . ')';
            }
            fwrite(STDERR, "ambiguous prefix '$token' found in multiple remotes: " . implode(', ', $list) . "\nSpecify --remote=<name>.\n");
            return 5;
        }
        // Single remote match
        $remote = array_key_first($matches);
        $uidFull = $matches[$remote];
        try {
            $uid = $ctx->manager->pull($uidFull, $remote, $force);
        } catch (Throwable $e) {
            fwrite(STDERR, 'pull failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo "retrieved snapshot $uid from $remote into local\n";
        return 0;
    }
}
