<?php

namespace Snappy\Hosting;

use RuntimeException;

class runtime {
    public static function ensureEndpoint(options $o): array {
        if ($o->endpoint !== '' || $o->noRun) {
            return [$o->endpoint, null, []];
        }
        $cmd = 'ngrok http --log=stdout --log-format=json ' . escapeshellarg($o->port);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('failed to start ngrok (is it installed and in PATH?)');
        }
        $deadline = time() + $o->timeout;
        $url = '';
        stream_set_blocking($pipes[1], false);
        $buffer = '';
        while (time() < $deadline && $url === '') {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }
            $lines = preg_split('/\r?\n/', $buffer);
            $buffer = array_pop($lines);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }
                if (isset($data['url']) && str_starts_with($data['url'], 'https://')) {
                    $url = $data['url'];
                    break;
                }
            }
            if ($url !== '') {
                break;
            }
            usleep(150000);
        }
        if ($url === '') {
            proc_terminate($proc);
            throw new RuntimeException('could not obtain ngrok tunnel url within timeout');
        }
        return [rtrim($url, '/'), $proc, $pipes];
    }

    public static function encodeRemote(options $o, string $endpoint): string {
        if (!preg_match('~^https://~', $endpoint)) {
            throw new RuntimeException('endpoint must be https:// ... got: ' . $endpoint);
        }
        $payload = ['t' => 's3', 'e' => $endpoint, 'b' => $o->bucket, 'r' => $o->region];
        if ($o->key !== '' && $o->secret !== '') {
            $payload['k'] = $o->key;
            $payload['s'] = $o->secret;
        }
        if ($o->prefix !== '') {
            $payload['p'] = $o->prefix;
        }
        return remote_codec::encode($payload);
    }

    public static function streamLogs($proc, array $pipes): void {
        if (!$proc) {
            return;
        }
        echo "\nStreaming ngrok logs (Ctrl+C to stop) ...\n";
        stream_set_blocking($pipes[1], true);
        while (!feof($pipes[1])) {
            $l = fgets($pipes[1]);
            if ($l === false) {
                break;
            }
            $data = json_decode($l, true);
            if (is_array($data)) {
                $msg = $data['msg'] ?? ($data['url'] ?? '');
                if ($msg !== '') {
                    echo "[ngrok] $msg\n";
                }
            }
        }
        proc_close($proc);
    }
}

