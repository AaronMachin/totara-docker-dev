<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Util\hosting;
use Snappy\Util\remote_codec; // kept if future custom payload tweaks needed
use RuntimeException;
use Throwable;

class share implements command {
    public function name(): string { return 'share'; }
    public function description(): string { return 'Share a local snapshot by exposing the local bucket via ngrok and printing a pull command: share <uid|prefix>'; }

    public function run(array $args, context $ctx): int {
        $positional = [];
        [$opts, $errors] = hosting::parse_options($args, $positional);
        if (in_array('__HELP__', $errors, true)) { $this->usage(); return 0; }
        foreach ($errors as $e) { if ($e !== '__HELP__') { fwrite(STDERR, $e."\n"); return 1; } }
        $token = $positional[0] ?? null;
        if ($token === null) { fwrite(STDERR, "snapshot uid or unique prefix required\n"); return 1; }

        if ($err = hosting::validate_options($opts)) { fwrite(STDERR, $err."\n"); return 1; }

        // Resolve snapshot locally
        $resolved = $ctx->manager->resolve_uid($token, 'local');
        if ($resolved === '') { fwrite(STDERR, "no or ambiguous match for '$token' (local snapshots)\n"); return 1; }

        // Acquire/launch endpoint
        try { [$endpoint, $proc, $pipes] = hosting::ensure_endpoint($opts); }
        catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage()."\n"); return 2; }

        // Encode remote token
        try { $encoded = hosting::encode_remote($opts, $endpoint); }
        catch (Throwable $e) { if (isset($proc) && $proc) { @proc_terminate($proc); } fwrite(STDERR, 'encode failed: '.$e->getMessage()."\n"); return 3; }

        echo "Snapshot UID: $resolved\n";
        echo "Public endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";
        echo "Pull command (copy/paste): tsnap pull --encoded=$encoded $resolved\n";

        if ($opts['no-run']) { return 0; }
        hosting::stream_logs($proc, $pipes);
        return 0;
    }

    private function usage(): void {
        echo "tsnap share <uid|prefix> [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--no-run] [--detach]\n";
        echo "Starts (or uses provided) https endpoint via ngrok exposing local bucket and prints a pull command for the snapshot.\n";
    }
}
