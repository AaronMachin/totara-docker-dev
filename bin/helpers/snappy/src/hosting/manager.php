<?php
namespace Snappy\Hosting;

use RuntimeException;

class manager {
    private string $root; // snapshot root
    private string $stateFile;
    private ?provider $provider; // active provider (e.g. ngrok) or null for static endpoint

    public function __construct(string $snapshotRoot, ?provider $provider = null) {
        $this->root = rtrim($snapshotRoot, '/');
        if (!is_dir($this->root)) { @mkdir($this->root, 0777, true); }
        $this->stateFile = $this->root . '/host_state.json';
        $this->provider = $provider ?: new ngrok_provider();
    }

    public function state(): ?array {
        if (!is_file($this->stateFile)) { return null; }
        $data = @json_decode(@file_get_contents($this->stateFile), true);
        if (!is_array($data)) return null;
        return $data;
    }

    public function isRunning(): bool {
        $s = $this->state();
        if (!$s) return false;
        // Static endpoint (no provider process)
        if (($s['provider'] ?? '') === 'static') {
            return true; // treat as always available
        }
        $provState = $s['provider_state'] ?? $s; // backward compatibility
        if ($this->provider && method_exists($this->provider, 'isRunning')) {
            return $this->provider->isRunning($provState);
        }
        return false;
    }

    public function ensureRunning(options $opts): array {
        if ($this->isRunning()) {
            $s = $this->state();
            if ($s) return $s;
        }
        return $this->start($opts);
    }

    public function start(options $opts, bool $forceRestart = false): array {
        if ($this->isRunning() && !$forceRestart) {
            $s = $this->state();
            if ($s) return $s;
        }
        if ($this->isRunning() && $forceRestart) {
            $this->stop();
        }
        @unlink($this->stateFile);

        // Case: user supplied explicit endpoint (no tunnel process required)
        if ($opts->endpoint !== '') {
            $state = [
                'provider' => 'static',
                'provider_state' => [
                    'endpoint' => rtrim($opts->endpoint, '/'),
                    'started' => date('c'),
                ],
                'endpoint' => rtrim($opts->endpoint, '/'), // legacy convenience
                'started' => date('c'),
                'options' => $this->serializeOptions($opts),
                'version' => 2,
            ];
            @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
            return $state;
        }

        if (!$this->provider) {
            throw new RuntimeException('no provider available and no endpoint specified');
        }

        $provState = $this->provider->start($opts, $this->root);
        $endpoint = $provState['endpoint'] ?? '';
        if ($endpoint === '') {
            throw new RuntimeException('provider returned empty endpoint');
        }
        $state = [
            'provider' => $this->provider->name(),
            'provider_state' => $provState,
            // flatten for backward compatibility
            'pid' => $provState['pid'] ?? null,
            'endpoint' => $endpoint,
            'started' => $provState['started'] ?? date('c'),
            'options' => $this->serializeOptions($opts),
            'version' => 2,
        ];
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
        return $state;
    }

    public function stop(): void {
        $s = $this->state();
        if ($s) {
            if (($s['provider'] ?? '') !== 'static') {
                $provState = $s['provider_state'] ?? $s;
                if ($this->provider) { $this->provider->stop($provState); }
            }
        }
        @unlink($this->stateFile);
    }

    public function endpoint(): ?string {
        $s = $this->state();
        if (!$s) return null;
        if (!$this->isRunning()) return null;
        return $s['endpoint'] ?? ($s['provider_state']['endpoint'] ?? null);
    }

    public function logFile(): ?string {
        $s = $this->state();
        if (!$s) return null;
        $provState = $s['provider_state'] ?? $s;
        return $provState['log_file'] ?? null;
    }

    public function streamLogs(): void {
        $s = $this->state();
        if (!$s) { echo "No state file (start host first)\n"; return; }
        if (($s['provider'] ?? '') === 'static') { echo "Static endpoint provider: no logs available\n"; return; }
        $provState = $s['provider_state'] ?? $s;
        if ($this->provider) { $this->provider->streamLogs($provState); return; }
        echo "No provider available to stream logs\n";
    }

    public function buildEncodedRemote(?array $state = null): string {
        $s = $state ?: $this->state();
        if (!$s) throw new RuntimeException('host not running');
        if (!$this->isRunning()) throw new RuntimeException('host process not active');
        $endpoint = $s['endpoint'] ?? ($s['provider_state']['endpoint'] ?? '');
        if ($endpoint === '') throw new RuntimeException('state missing endpoint');
        $opts = $s['options'] ?? [];
        $payload = [
            't' => 's3',
            'e' => $endpoint,
            'b' => $opts['bucket'] ?? 'snappy',
            'r' => $opts['region'] ?? 'us-east-1',
            'k' => $opts['key'] ?? 'admin',
            's' => $opts['secret'] ?? 'secret',
        ];
        $p = $opts['prefix'] ?? '';
        if ($p !== '') { $payload['p'] = $p; }
        return remote_codec::encode($payload);
    }

    private function serializeOptions(options $opts): array {
        return [
            'bucket' => $opts->bucket,
            'region' => $opts->region,
            'key' => $opts->key,
            'secret' => $opts->secret,
            'prefix' => $opts->prefix,
            'port' => $opts->port,
            'anon' => $opts->anon,
        ];
    }
}
