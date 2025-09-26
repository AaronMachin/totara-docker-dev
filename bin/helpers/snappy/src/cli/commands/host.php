<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Util\remote_codec;
use RuntimeException;

class host implements command {
    public function name(): string { return 'host'; }
    public function description(): string { return 'Expose local MinIO via ngrok and output encoded remote token'; }

    public function run(array $args, context $ctx): int {
        $opts = [
            'bucket' => getenv('MINIO_BUCKET') ?: getenv('SNAPPY_S3_BUCKET') ?: 'snappy',
            'region' => getenv('MINIO_REGION') ?: getenv('SNAPPY_S3_REGION') ?: 'us-east-1',
            'key' => getenv('MINIO_ROOT_USER') ?: getenv('SNAPPY_S3_KEY') ?: getenv('AWS_ACCESS_KEY_ID') ?: '',
            'secret' => getenv('MINIO_ROOT_PASSWORD') ?: getenv('SNAPPY_S3_SECRET') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '',
            'prefix' => '',
            'port' => '8000',
            'endpoint' => '', // override (skip ngrok)
            'no-run' => false,
            'detach' => false,
            'anon' => false,
        ];
        foreach ($args as $a) {
            if (preg_match('~^--bucket=(.+)~', $a, $m)) { $opts['bucket'] = $m[1]; continue; }
            if (preg_match('~^--region=(.+)~', $a, $m)) { $opts['region'] = $m[1]; continue; }
            if (preg_match('~^--key=(.+)~', $a, $m)) { $opts['key'] = $m[1]; continue; }
            if (preg_match('~^--secret=(.+)~', $a, $m)) { $opts['secret'] = $m[1]; continue; }
            if (preg_match('~^--prefix=(.+)~', $a, $m)) { $opts['prefix'] = trim($m[1], '/'); continue; }
            if (preg_match('~^--port=(\d+)~', $a, $m)) { $opts['port'] = $m[1]; continue; }
            if (preg_match('~^--endpoint=(.+)~', $a, $m)) { $opts['endpoint'] = rtrim($m[1], '/'); continue; }
            if ($a === '--no-run') { $opts['no-run'] = true; continue; }
            if ($a === '--detach') { $opts['detach'] = true; continue; }
            if ($a === '--anon') { $opts['anon'] = true; continue; }
            if ($a === '--help' || $a === '-h') { $this->usage(); return 0; }
            if ($a !== '') { fwrite(STDERR, "unknown option: $a\n"); return 1; }
        }

        if ($opts['anon']) { $opts['key'] = ''; $opts['secret'] = ''; }

        if ($opts['endpoint'] === '' && $opts['no-run']) {
            fwrite(STDERR, "--no-run requires --endpoint=<url>\n");
            return 1;
        }
        if ($opts['detach'] && $opts['endpoint'] === '') {
            fwrite(STDERR, "--detach currently requires --endpoint (cannot discover ngrok URL before backgrounding)\n");
            return 1;
        }

        $endpoint = $opts['endpoint'];
        $tunnel_proc = null;
        $ngrok_cmd = null;
        if ($endpoint === '' && !$opts['no-run']) {
            // Launch ngrok and capture first https tunnel URL
            $ngrok_cmd = 'ngrok http --log=stdout --log-format=json ' . escapeshellarg($opts['port']);
            $descriptorSpec = [1 => ['pipe','w'], 2 => ['pipe','w']];
            $tunnel_proc = proc_open($ngrok_cmd, $descriptorSpec, $pipes);
            if (!is_resource($tunnel_proc)) {
                fwrite(STDERR, "failed to start ngrok (is it installed and in PATH?)\n");
                return 2;
            }
            $deadline = time() + 15;
            $url = '';
            stream_set_blocking($pipes[1], false);
            $buffer = '';
            while (time() < $deadline && $url === '') {
                $chunk = stream_get_contents($pipes[1]);
                if ($chunk !== false && $chunk !== '') { $buffer .= $chunk; }
                $lines = preg_split('/\r?\n/', $buffer);
                // Keep last partial line in buffer
                $buffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    // Each line should be JSON
                    $data = json_decode($line, true);
                    if (!is_array($data)) continue;
                    if (isset($data['url']) && str_starts_with($data['url'], 'https://')) {
                        // Accept first https url
                        $url = $data['url'];
                        break;
                    }
                }
                if ($url !== '') break;
                usleep(150000);
            }
            if ($url === '') {
                proc_terminate($tunnel_proc);
                fwrite(STDERR, "could not obtain ngrok tunnel url within timeout\n");
                return 3;
            }
            $endpoint = rtrim($url, '/');
        }

        if (!preg_match('~^https://~', $endpoint)) {
            fwrite(STDERR, "endpoint must be https:// ... got: $endpoint\n");
            if ($tunnel_proc) { proc_terminate($tunnel_proc); }
            return 4;
        }

        $payload = [
            't' => 's3',
            'e' => $endpoint,
            'b' => $opts['bucket'],
            'r' => $opts['region'],
        ];
        if ($opts['key'] !== '' && $opts['secret'] !== '') {
            $payload['k'] = $opts['key'];
            $payload['s'] = $opts['secret'];
        }
        if ($opts['prefix'] !== '') { $payload['p'] = $opts['prefix']; }
        try {
            $encoded = remote_codec::encode($payload);
        } catch (RuntimeException $e) {
            if ($tunnel_proc) { proc_terminate($tunnel_proc); }
            fwrite(STDERR, 'encode failed: ' . $e->getMessage() . "\n");
            return 5;
        }

        echo "Public endpoint: $endpoint\n";
        echo "Encoded remote: $encoded\n";
        echo "Share command: tsnap pull --encoded $encoded <SNAPSHOT_UID>\n";
        if ($opts['key'] !== '' && $opts['secret'] !== '') {
            echo "(Warning: encoded string contains credentials; treat as secret)\n";
        } else {
            echo "(Anonymous mode: no credentials embedded)\n";
        }
        if ($opts['no-run']) {
            return 0;
        }
        // If we launched ngrok, stream its stdout after header lines so user can Ctrl+C
        if ($tunnel_proc) {
            echo "\nStreaming ngrok logs (Ctrl+C to stop) ...\n";
            // Reattach live output
            stream_set_blocking($pipes[1], true);
            while (!feof($pipes[1])) {
                $l = fgets($pipes[1]);
                if ($l === false) break;
                $data = json_decode($l, true);
                if (is_array($data)) {
                    $msg = $data['msg'] ?? ($data['url'] ?? '');
                    if ($msg !== '') {
                        echo "[ngrok] $msg\n";
                    }
                }
            }
            proc_close($tunnel_proc);
        }
        return 0;
    }

    private function usage(): void {
        echo "tsnap host --bucket=NAME --region=REG --port=8000 [--key=K --secret=S | --anon] [--prefix=P] [--endpoint=URL] [--no-run] [--detach]*\n";
        echo "Generates an encoded remote token and (by default) launches an ngrok tunnel to local MinIO/S3-compatible service.\n";
        echo "Use the printed encoded string with: tsnap pull --encoded <TOKEN> <UID>\n";
        echo "* --detach requires --endpoint (no live tunnel discovery).\n";
    }
}

