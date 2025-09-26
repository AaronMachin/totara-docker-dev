<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Hosting\options;
use Snappy\Hosting\manager;
use RuntimeException;

class share implements command {
    public function name(): string { return 'share'; }
    public function description(): string { return 'Share a local snapshot by ensuring a background host is running and printing a pull command: share <uid|prefix>'; }

    public function run(array $args, context $ctx): int {
        // Parse options (expects first positional snapshot token)
        [$opts, $snapshotToken, $help, $error] = options::parse($args, true);
        if ($help) { $this->usage(); return 0; }
        if ($error) { fwrite(STDERR, $error."\n"); return 1; }
        if ($snapshotToken === null) { fwrite(STDERR, "snapshot uid or unique prefix required\n"); return 1; }
        if ($err = $opts->normalize()) { fwrite(STDERR, $err."\n"); return 1; }

        // Resolve snapshot locally
        $resolved = $ctx->manager->resolve_uid($snapshotToken, 'local');
        if ($resolved === '') { fwrite(STDERR, "no or ambiguous match for '$snapshotToken' (local snapshots)\n"); return 1; }

        // Ensure background host
        $manager = new manager($ctx->registry->local_base_path());
        $newlyStarted = false; $state = null;
        if ($manager->isRunning()) {
            $state = $manager->state();
            if (!$state) { // stale state file
                $state = $manager->start($opts, true); $newlyStarted = true; }
        } else {
            try { $state = $manager->start($opts); $newlyStarted = true; }
            catch (RuntimeException $e) { fwrite(STDERR, 'failed to start host: '.$e->getMessage()."\n"); return 2; }
        }

        // Build encoded remote (always fresh from state)
        try { $encoded = $manager->buildEncodedRemote($state); }
        catch (RuntimeException $e) { fwrite(STDERR, 'encode failed: '.$e->getMessage()."\n"); return 3; }

        $endpoint = $state['endpoint'] ?? '';
        echo "Snapshot UID: $resolved\n";
        echo ($newlyStarted?"Host started in background\n":"Host already running\n");
        echo "Endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";
        $k = $state['options']['key'] ?? ''; $s = $state['options']['secret'] ?? '';
        if ($k !== '' && $s !== '') { echo "(Warning: encoded string contains credentials; treat as secret)\n"; }
        else { echo "(Anonymous mode: no credentials embedded)\n"; }
        echo "Use 'tsnap host logs' to follow tunnel output, 'tsnap host stop' to terminate.\n";
        echo "\n";
        echo "Share command (copy/paste):\n";
        echo "tsnap pull --encoded=$encoded $resolved\n";
        return 0;
    }

    private function usage(): void {
        echo "tsnap share <uid|prefix> [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--timeout=SEC]\n";
        echo "Ensures background host is running (starting if needed) and prints a pull command for the snapshot.\n";
    }
}
