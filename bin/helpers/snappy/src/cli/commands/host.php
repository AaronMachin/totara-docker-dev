<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Util\hosting;
use RuntimeException;
use Throwable;

class host implements command {
    public function name(): string { return 'host'; }
    public function description(): string { return 'Expose local bucket via ngrok and enable sharing snapshots'; }

    public function run(array $args, context $ctx): int {
        $positional = [];
        [$opts,$errors] = hosting::parse_options($args, $positional);
        if (in_array('__HELP__', $errors, true)) { $this->usage(); return 0; }
        foreach ($errors as $e) { if ($e !== '__HELP__') { fwrite(STDERR, $e."\n"); return 1; } }
        if ($err = hosting::validate_options($opts)) { fwrite(STDERR, $err."\n"); return 1; }

        // Obtain endpoint (maybe launching ngrok)
        try {
            [$endpoint, $proc, $pipes] = hosting::ensure_endpoint($opts);
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            return 2;
        }
        try {
            $encoded = hosting::encode_remote($opts, $endpoint);
        } catch (Throwable $e) {
            if (isset($proc) && $proc) { @proc_terminate($proc); }
            fwrite(STDERR, 'encode failed: '.$e->getMessage()."\n");
            return 3;
        }

        echo "Public endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";
        echo "Share command: tsnap pull --encoded=$encoded <SNAPSHOT_UID>\n";

        if ($opts['no-run']) { return 0; }
        hosting::stream_logs($proc, $pipes);
        return 0;
    }

    private function usage(): void {
        echo "tsnap host [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--no-run] [--detach]\n";
        echo "Generates an encoded remote token and (by default) launches an ngrok tunnel to local MinIO/S3-compatible service.\n";
    }
}
