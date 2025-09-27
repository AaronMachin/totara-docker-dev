<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Hosting\ngrok_provider;
use Snappy\Hosting\options;
use Snappy\Hosting\manager;
use RuntimeException;

class share extends base_command {
    public function name(): string {
        return 'share';
    }

    public function description(): string {
        return 'Share a local snapshot by ensuring a background host is running and printing a pull command';
    }

    public function usage(): string {
        return "Usage: tsnap share <uid|prefix> [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--timeout=SEC]\nEnsures background host is running (starting if needed) then prints encoded remote and a pull command for the snapshot.";
    }

    public function examples(): array {
        return [
            'tsnap share 7fa12c3',
            'tsnap share --anon 7fa12c3',
            'tsnap share --endpoint=https://example.ngrok-free.app 7fa12c3',
            'tsnap share --bucket=mybucket --region=us-east-1 --port=9000 7fa12c3'
        ];
    }

    public function run(array $args, context $ctx): int {
        [$opts, $snapshotToken, $help, $error] = options::parse($args, true);
        if ($help) {
            $this->display_help();
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
        $resolved = $ctx->manager->resolve_uid($snapshotToken, 'local');
        if ($resolved === '') {
            fwrite(STDERR, "no or ambiguous match for '$snapshotToken' (local snapshots)\n");
            return 1;
        }
        $manager = new manager($ctx->registry->local_base_path(), new ngrok_provider());
        $newlyStarted = false;
        $state = null;
        if ($manager->isRunning()) {
            $state = $manager->state();
            if (!$state) {
                $state = $manager->start($opts, true);
                $newlyStarted = true;
            }
        } else {
            try {
                $state = $manager->start($opts);
                $newlyStarted = true;
            } catch (RuntimeException $e) {
                fwrite(STDERR, 'failed to start host: ' . $e->getMessage() . "\n");
                return 2;
            }
        }
        try {
            $encoded = $manager->buildEncodedRemote($state);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'encode failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        $endpoint = $state['endpoint'] ?? '';
        echo "Snapshot UID: $resolved\n";
        echo($newlyStarted ? "Host started in background\n" : "Host already running\n");
        echo "Endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";

        echo "Use 'tsnap host logs' to follow tunnel output, 'tsnap host stop' to terminate.\n";
        echo "\n";
        echo "Share command (copy/paste):\n";
        echo "tsnap pull --encoded=$encoded $resolved\n";
        return 0;
    }
}
