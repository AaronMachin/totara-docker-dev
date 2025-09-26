<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Hosting\manager;
use Snappy\Hosting\options;
use Throwable;
use RuntimeException;

class host implements command {
    public function name(): string {
        return 'host';
    }

    public function description(): string {
        return 'Manage background ngrok host: host [start|status|logs|stop] [options]';
    }

    public function run(array $args, context $ctx): int {
        $sub = 'start';
        $recognized = ['start','status','logs','stop'];
        if (isset($args[0]) && in_array($args[0], $recognized, true)) { $sub = array_shift($args); }
        $manager = new manager($ctx->registry->local_base_path());

        if ($sub === 'status') {
            $state = $manager->state();
            if (!$state || !$manager->isRunning()) { echo "host: not running\n"; return 0; }
            echo "host: running (pid {$state['pid']}) endpoint {$state['endpoint']} started {$state['started']}\n";
            echo "encoded: " . $manager->buildEncodedRemote($state) . "\n";
            return 0;
        }
        if ($sub === 'stop') {
            if (!$manager->isRunning()) { echo "host: not running\n"; return 0; }
            $manager->stop();
            echo "host: stopped\n";
            return 0;
        }
        if ($sub === 'logs') {
            if (!$manager->isRunning()) { echo "host: not running (start first)\n"; return 1; }
            $manager->streamLogs();
            return 0; // streamLogs blocks until Ctrl+C
        }
        // start (default)
        [$opts, $snapshot, $help, $error] = options::parse($args, false);
        if ($help) { $this->usage(); return 0; }
        if ($error) { fwrite(STDERR, $error."\n"); return 1; }
        if ($err = $opts->normalize()) { fwrite(STDERR, $err."\n"); return 1; }
        try {
            $state = $manager->ensureRunning($opts);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'start failed: '.$e->getMessage()."\n");
            return 2;
        }
        $already = $manager->isRunning() && ($state['started'] ?? '') !== '' && (time() - strtotime($state['started'])) > 2; // heuristic
        echo ($already?"host: already running\n":"host: started\n");
        echo "endpoint: {$state['endpoint']}\n";
        try { $encoded = $manager->buildEncodedRemote($state); echo "encoded: $encoded\n"; }
        catch (Throwable $e) { fwrite(STDERR, 'encode failed: '.$e->getMessage()."\n"); }
        echo "Pull example: tsnap pull --encoded=$encoded <SNAPSHOT_UID>\n";
        echo "Use 'tsnap host logs' to follow ngrok output, 'tsnap host stop' to terminate.\n";
        return 0;
    }

    private function usage(): void {
        echo "tsnap host [start|status|logs|stop] [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL]* [--timeout=SEC]\n";
        echo "Starts or manages a background ngrok process. * --endpoint skips ngrok and just records provided https endpoint.\n";
    }
}
