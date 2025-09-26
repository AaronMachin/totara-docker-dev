<?php
namespace Snappy\Hosting;

use RuntimeException;

class manager {
    private string $root; // snapshot root
    private string $stateFile;
    private string $logFile;

    public function __construct(string $snapshotRoot) {
        $this->root = rtrim($snapshotRoot, '/');
        if (!is_dir($this->root)) { @mkdir($this->root, 0777, true); }
        $this->stateFile = $this->root . '/host_state.json';
        $this->logFile = $this->root . '/host_ngrok.log';
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
        $pid = $s['pid'] ?? 0; if (!$pid) return false;
        // posix_kill check if available
        if (function_exists('posix_kill')) { return @posix_kill($pid, 0); }
        // Fallback: /proc
        return is_dir('/proc/' . $pid);
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
        // remove stale state
        @unlink($this->stateFile);
        if (is_file($this->logFile)) { @unlink($this->logFile); }

        // Build command using nohup so it persists; we will poll logFile for endpoint.
        $cmd = 'nohup ngrok http --log=stdout --log-format=json ' . escapeshellarg($opts->port) . ' > ' . escapeshellarg($this->logFile) . ' 2>&1 & echo $!';
        $pid = trim(shell_exec($cmd));
        if ($pid === '' || !ctype_digit($pid)) {
            throw new RuntimeException('failed to launch ngrok process');
        }
        $pid = (int)$pid;
        // Poll log for endpoint
        $deadline = time() + $opts->timeout;
        $endpoint = '';
        while (time() < $deadline && $endpoint === '') {
            if (is_file($this->logFile)) {
                $lines = @file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $data = json_decode($line, true);
                    if (!is_array($data)) continue;
                    if (isset($data['url']) && str_starts_with($data['url'], 'https://')) {
                        $endpoint = rtrim($data['url'], '/');
                        break;
                    }
                }
            }
            if ($endpoint !== '') break;
            usleep(200000);
        }
        if ($endpoint === '') {
            // Could not determine endpoint, but process maybe alive.
            $this->stop();
            throw new RuntimeException('ngrok started but endpoint not discovered within timeout');
        }
        $state = [
            'pid' => $pid,
            'endpoint' => $endpoint,
            'started' => date('c'),
            'options' => [
                'bucket' => $opts->bucket,
                'region' => $opts->region,
                'key' => $opts->key,
                'secret' => $opts->secret,
                'prefix' => $opts->prefix,
                'port' => $opts->port,
                'anon' => $opts->anon,
            ],
            'version' => 1,
        ];
        @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
        return $state;
    }

    public function stop(): void {
        $s = $this->state();
        if ($s) {
            $pid = $s['pid'] ?? 0;
            if ($pid) {
                if (function_exists('posix_kill')) { @posix_kill((int)$pid, 15); }
                else { @exec('kill ' . (int)$pid . ' >/dev/null 2>&1'); }
            }
        }
        @unlink($this->stateFile);
    }

    public function endpoint(): ?string {
        $s = $this->state();
        if (!$s) return null;
        if (!$this->isRunning()) return null;
        return $s['endpoint'] ?? null;
    }

    public function logFile(): string { return $this->logFile; }
    public function stateFile(): string { return $this->stateFile; }

    public function streamLogs(): void {
        $file = $this->logFile;
        if (!is_file($file)) { echo "No log file yet (start host first)\n"; return; }
        $fp = fopen($file, 'r');
        if (!$fp) { echo "Cannot open log file"; return; }
        echo "Streaming ngrok logs (Ctrl+C to stop) ...\n";

        $printLine = function(string $line) {
            $line = trim($line);
            if ($line === '') return;
            $data = json_decode($line, true);
            if (is_array($data)) {
                $msg = $data['msg'] ?? ($data['url'] ?? '');
                if ($msg !== '') { echo "[ngrok] $msg\n"; }
            } else {
                echo $line . "\n";
            }
            if (function_exists('ob_flush')) { @ob_flush(); }
            @flush();
        };

        // Print existing content first (tail -f style but including history)
        while (($line = fgets($fp)) !== false) { $printLine($line); }
        $pos = ftell($fp);

        while (true) {
            $line = fgets($fp);
            if ($line === false) {
                clearstatcache(false, $file);
                // Detect truncation/rotation
                $size = @filesize($file);
                if ($size !== false && $size < $pos) {
                    // Reopen from beginning
                    @fclose($fp);
                    $fp = @fopen($file, 'r');
                    if ($fp) { $pos = 0; }
                    usleep(200000); // wait a bit
                    continue;
                }
                usleep(200000); // sleep then retry
                continue;
            }
            $printLine($line);
            $pos = ftell($fp);
        }
    }

    public function buildEncodedRemote(?array $state = null): string {
        $s = $state ?: $this->state();
        if (!$s) throw new RuntimeException('host not running');
        if (!$this->isRunning()) throw new RuntimeException('host process not active');
        $endpoint = $s['endpoint'] ?? '';
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
}
