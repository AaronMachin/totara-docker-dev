<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Hosting\options;
use Snappy\Hosting\runtime;
use RuntimeException;
use Throwable;

class share implements command {
    public function name(): string {
        return 'share';
    }

    public function description(): string {
        return 'Share a local snapshot by exposing the local bucket via ngrok and printing a pull command: share <uid|prefix>';
    }

    public function run(array $args, context $ctx): int {
        [$opts, $snapshotToken, $help, $error] = options::parse($args, true);
        if ($help) {
            $this->usage();
            return 0;
        }
        if ($error) {
            fwrite(STDERR, $error . "\n");
            return 1;
        }
        if ($snapshotToken === null) {
            fwrite(STDERR, "snapshot uid or unique prefix required\n");
            return 1;
        }
        if ($err = $opts->normalize()) {
            fwrite(STDERR, $err . "\n");
            return 1;
        }

        // Resolve snapshot locally
        $resolved = $ctx->manager->resolve_uid($snapshotToken, 'local');
        if ($resolved === '') {
            fwrite(STDERR, "no or ambiguous match for '$snapshotToken' (local snapshots)\n");
            return 1;
        }

        // Acquire endpoint (possibly start ngrok)
        try {
            [$endpoint, $proc, $pipes] = runtime::ensureEndpoint($opts);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 2;
        }

        // Encode remote token
        try {
            $encoded = runtime::encodeRemote($opts, $endpoint);
        } catch (Throwable $e) {
            if (isset($proc) && $proc) {
                @proc_terminate($proc);
            }
            fwrite(STDERR, 'encode failed: ' . $e->getMessage() . "\n");
            return 3;
        }

        echo "Snapshot UID: $resolved\n";
        echo "Public endpoint: $endpoint\n";
        echo "\n";
        echo "Pull command (copy/paste): tsnap pull --encoded=$encoded $resolved\n";
        echo "\n";
        if ($opts->key !== '' && $opts->secret !== '') {
            echo "(Warning: encoded string contains credentials; treat as secret)\n";
        } else {
            echo "(Anonymous mode: no credentials embedded)\n";
        }
        if ($opts->noRun) {
            return 0;
        }
        runtime::streamLogs($proc, $pipes);
        return 0;
    }

    private function usage(): void {
        echo "tsnap share <uid|prefix> [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--no-run] [--detach] [--timeout=SEC]\n";
        echo "Starts/uses an https endpoint via ngrok exposing local bucket and prints a pull command for the snapshot.\n";
    }
}
