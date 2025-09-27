<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Hosting\manager;
use Snappy\Hosting\options;
use Snappy\Hosting\ngrok_provider;
use Throwable;
use RuntimeException;

class host extends base_command {
    public function name(): string {
        return 'host';
    }

    public function description(): string {
        return 'Manage background snapshot hosting tunnel (start/status/logs/stop)';
    }

    public function usage(): string {
        return "Usage: tsnap host [start|status|logs|stop] [--bucket=NAME --region=REG --port=8000 --key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--timeout=SEC] \nStarts or manages background host (tunnel or provided endpoint). 'start' is default.";
    }

    public function examples(): array {
        return ['tsnap host', 'tsnap host status', 'tsnap host logs', 'tsnap host stop', 'tsnap host --bucket=mybucket --region=us-east-1 --port=9000 --anon'];
    }

    public function run(array $args, context $ctx): int {
        $sub = 'start';
        $recognized = ['start', 'status', 'logs', 'stop'];
        if (isset($args[0]) && in_array($args[0], $recognized, true)) {
            $sub = array_shift($args);
        }
        // For status/logs/stop we don't need provider parsing unless starting.
        if ($sub !== 'start') {
            $manager = new manager($ctx->registry->local_base_path());
            return match ($sub) {
                'status' => $this->doStatus($manager),
                'stop' => $this->doStop($manager),
                'logs' => $this->doLogs($manager),
                default => 0,
            };
        }
        // start path needs options to pick provider
        [$opts, $snapshot, $help, $error] = options::parse($args, false);
        if ($error) {
            fwrite(STDERR, $error . "\n");
            return 1;
        }
        if ($err = $opts->normalize()) {
            fwrite(STDERR, $err . "\n");
            return 1;
        }
        $provider = new ngrok_provider();
        $manager = new manager($ctx->registry->local_base_path(), $provider);
        return $this->doStart($manager, $opts);
    }

    private function doStatus(manager $manager): int {
        $state = $manager->state();
        if (!$state || !$manager->isRunning()) {
            echo "host: not running\n";
            return 0;
        }
        echo "host: running (provider ngrok pid " . ($state['pid'] ?? '-') . ") endpoint {$state['endpoint']} started {$state['started']}\n";
        echo "encoded: " . $manager->buildEncodedRemote($state) . "\n";
        return 0;
    }

    private function doStop(manager $manager): int {
        if (!$manager->isRunning()) {
            echo "host: not running\n";
            return 0;
        }
        $manager->stop();
        echo "host: stopped\n";
        return 0;
    }

    private function doLogs(manager $manager): int {
        if (!$manager->isRunning()) {
            echo "host: not running (start first)\n";
            return 1;
        }
        $manager->streamLogs();
        return 0; // blocks until interrupted
    }

    private function doStart(manager $manager, options $opts): int {
        try {
            $state = $manager->ensureRunning($opts);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'start failed: ' . $e->getMessage() . "\n");
            return 2;
        }
        $already = $manager->isRunning() && ($state['started'] ?? '') !== '' && (time() - strtotime($state['started'])) > 2;
        echo($already ? "host: already running\n" : "host: started\n");
        echo "provider: " . ($state['provider'] ?? 'unknown') . "\n";
        echo "endpoint: {$state['endpoint']}\n";
        try {
            $encoded = $manager->buildEncodedRemote($state);
            echo "encoded: $encoded\n";
        } catch (Throwable $e) {
            fwrite(STDERR, 'encode failed: ' . $e->getMessage() . "\n");
            $encoded = '';
        }
        if ($encoded !== '') {
            echo "Pull example: tsnap pull --encoded=$encoded <SNAPSHOT_UID>\n";
        }
        echo "Use 'tsnap host logs' to follow tunnel output, 'tsnap host stop' to terminate.\n";
        return 0;
    }
}
