<?php
namespace Snappy\Util;

use RuntimeException;

class hosting {
    /** Build default option set from environment. */
    public static function base_options(): array {
        return [
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
    }

    /** Parse common host/share options. Returns [options, errors]. */
    public static function parse_options(array $args, array &$positional = []): array {
        $opts = self::base_options();
        $errors = [];
        foreach ($args as $a) {
            if ($a === '' || $a[0] !== '-') { $positional[] = $a; continue; }
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
            if ($a === '--help' || $a === '-h') { $errors[] = '__HELP__'; continue; }
            $errors[] = "unknown option: $a";
        }
        return [$opts, $errors];
    }

    /** Validate logical constraints; may mutate opts. Returns error string or null. */
    public static function validate_options(array &$opts): ?string {
        if ($opts['anon']) { $opts['key'] = ''; $opts['secret'] = ''; }
        if ($opts['endpoint'] === '' && $opts['no-run']) { return '--no-run requires --endpoint=<url>'; }
        if ($opts['detach'] && $opts['endpoint'] === '') { return '--detach currently requires --endpoint (cannot discover ngrok URL before backgrounding)'; }
        return null;
    }

    /** Launch ngrok (if needed) and return [endpoint, proc|null, pipes]. */
    public static function ensure_endpoint(array $opts, int $timeout = 15): array {
        $endpoint = $opts['endpoint'];
        if ($endpoint !== '' || $opts['no-run']) { return [$endpoint, null, []]; }
        $cmd = 'ngrok http --log=stdout --log-format=json ' . escapeshellarg($opts['port']);
        $descriptorSpec = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('failed to start ngrok (is it installed and in PATH?)'); }
        $deadline = time() + $timeout; $url=''; stream_set_blocking($pipes[1], false); $buffer='';
        while (time() < $deadline && $url === '') {
            $chunk = stream_get_contents($pipes[1]); if ($chunk !== false && $chunk !== '') { $buffer .= $chunk; }
            $lines = preg_split('/\r?\n/', $buffer); $buffer = array_pop($lines);
            foreach ($lines as $line) {
                $line = trim($line); if ($line === '') { continue; }
                $data = json_decode($line, true); if (!is_array($data)) { continue; }
                if (isset($data['url']) && str_starts_with($data['url'], 'https://')) { $url = $data['url']; break; }
            }
            if ($url !== '') { break; }
            usleep(150000);
        }
        if ($url === '') { proc_terminate($proc); throw new RuntimeException('could not obtain ngrok tunnel url within timeout'); }
        return [rtrim($url,'/'), $proc, $pipes];
    }

    /** Build encoded remote string from endpoint + opts. */
    public static function encode_remote(array $opts, string $endpoint): string {
        if (!preg_match('~^https://~', $endpoint)) { throw new RuntimeException('endpoint must be https:// ... got: '.$endpoint); }
        $payload = ['t'=>'s3','e'=>$endpoint,'b'=>$opts['bucket'],'r'=>$opts['region']];
        if ($opts['key'] !== '' && $opts['secret'] !== '') { $payload['k']=$opts['key']; $payload['s']=$opts['secret']; }
        if ($opts['prefix'] !== '') { $payload['p']=$opts['prefix']; }
        return remote_codec::encode($payload);
    }

    /** Stream ngrok logs until EOF (Ctrl+C). */
    public static function stream_logs($proc, array $pipes): void {
        if (!$proc) return;
        echo "\nStreaming ngrok logs (Ctrl+C to stop) ...\n";
        stream_set_blocking($pipes[1], true);
        while (!feof($pipes[1])) {
            $l = fgets($pipes[1]); if ($l === false) break;
            $data = json_decode($l, true); if (is_array($data)) {
                $msg = $data['msg'] ?? ($data['url'] ?? ''); if ($msg !== '') { echo "[ngrok] $msg\n"; }
            }
        }
        proc_close($proc);
    }
}

