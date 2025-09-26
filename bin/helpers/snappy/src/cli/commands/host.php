<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Hosting\options;
use Snappy\Hosting\runtime;
use Throwable;
use RuntimeException;

class host implements command {
    public function name(): string {
        return 'host';
    }

    public function description(): string {
        return 'Expose local bucket via ngrok and enable sharing snapshots';
    }

    public function run(array $args, context $ctx): int {
        [$opts, $help, $error] = options::parse($args, false);
        if ($help) {
            $this->usage();
            return 0;
        }
        if ($error) {
            fwrite(STDERR, $error . "\n");
            return 1;
        }
        if ($err = $opts->normalize()) {
            fwrite(STDERR, $err . "\n");
            return 1;
        }
        try {
            [$endpoint, $proc, $pipes] = runtime::ensureEndpoint($opts);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 2;
        }
        try {
            $encoded = runtime::encodeRemote($opts, $endpoint);
        } catch (Throwable $e) {
            if (isset($proc) && $proc) {
                @proc_terminate($proc);
            }
            fwrite(STDERR, 'encode failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo "Public endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";
        echo "Share command: tsnap pull --encoded=$encoded <SNAPSHOT_UID>\n";
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
        echo "tsnap host [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--no-run] [--detach]\n";
        echo "Generates an encoded remote token and (by default) launches an ngrok tunnel to local MinIO/S3-compatible service.\n";
    }
}
