<?php
namespace Snappy\Hosting;

use Snappy\Support\Exception\RemoteException;

class ngrok_provider implements provider {
    public function name(): string { return 'ngrok'; }

    public function start(options $opts, string $root): array {
        $logFile = $root . '/host_ngrok.log';
        if (is_file($logFile)) { @unlink($logFile); }
        $cmd = 'nohup ngrok http --log=stdout --log-format=json ' . escapeshellarg($opts->port) . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $pid = trim(shell_exec($cmd));
        if ($pid === '' || !ctype_digit($pid)) {
            throw new RemoteException('failed to launch ngrok process');
        }
        $pid = (int)$pid;
        $deadline = time() + $opts->timeout;
        $endpoint = '';
        while (time() < $deadline && $endpoint === '') {
            if (is_file($logFile)) {
                $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
            $this->stop(['pid' => $pid]);
            throw new RemoteException('ngrok started but endpoint not discovered within timeout');
        }
        return [
            'pid' => $pid,
            'endpoint' => $endpoint,
            'started' => date('c'),
            'log_file' => $logFile,
        ];
    }

    public function isRunning(array $state): bool {
        $pid = $state['pid'] ?? 0; if (!$pid) return false;
        if (function_exists('posix_kill')) { return @posix_kill((int)$pid, 0); }
        return is_dir('/proc/' . (int)$pid);
    }

    public function stop(array $state): void {
        $pid = $state['pid'] ?? 0; if (!$pid) return;
        if (function_exists('posix_kill')) { @posix_kill((int)$pid, 15); }
        else { @exec('kill ' . (int)$pid . ' >/dev/null 2>&1'); }
    }

    public function streamLogs(array $state): void {
        $file = $state['log_file'] ?? '';
        if ($file === '' || !is_file($file)) { echo "No log file yet (start host first)\n"; return; }
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
            } else { echo $line . "\n"; }
            if (function_exists('ob_flush')) { @ob_flush(); }
            @flush();
        };
        while (($line = fgets($fp)) !== false) { $printLine($line); }
        $pos = ftell($fp);
        while (true) {
            $line = fgets($fp);
            if ($line === false) {
                clearstatcache(false, $file);
                $size = @filesize($file);
                if ($size !== false && $size < $pos) {
                    @fclose($fp);
                    $fp = @fopen($file, 'r');
                    if ($fp) { $pos = 0; }
                    usleep(200000);
                    continue;
                }
                usleep(200000);
                continue;
            }
            $printLine($line);
            $pos = ftell($fp);
        }
    }
}
