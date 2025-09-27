<?php

namespace Snappy\Hosting;

class options {
    public string $bucket = 'snappy';
    public string $region = '';
    public string $key = '';
    public string $secret = '';
    public string $prefix = '';
    public string $port = '8000';
    public string $endpoint = '';
    public bool $noRun = false;
    public bool $detach = false;
    public bool $anon = false;
    public int $timeout = 15;
    public string $provider = 'ngrok';

    public static function fromEnv(): self {
        $o = new self();
        $o->bucket = getenv('MINIO_BUCKET') ?: getenv('SNAPPY_S3_BUCKET') ?: 'snappy';
        $o->region = getenv('MINIO_REGION') ?: getenv('SNAPPY_S3_REGION') ?: 'us-east-1';
        $o->key = getenv('MINIO_ROOT_USER') ?: getenv('SNAPPY_S3_KEY') ?: getenv('AWS_ACCESS_KEY_ID') ?: '';
        $o->secret = getenv('MINIO_ROOT_PASSWORD') ?: getenv('SNAPPY_S3_SECRET') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '';
        return $o;
    }

    /**
     * Parse arguments. If $needSnapshot true, first positional is treated as snapshot token.
     * Returns [HostOptions, ?string snapshotToken, bool help, ?string error]
     */
    public static function parse(array $args, bool $needSnapshot): array {
        $o = self::fromEnv();
        $snapshot = null;
        $help = false;
        $error = null;
        foreach ($args as $arg) {
            if ($arg === '') {
                continue;
            }
            if ($arg[0] !== '-') {
                if ($needSnapshot && $snapshot === null) {
                    $snapshot = $arg;
                    continue;
                }
                // unexpected positional
                if ($needSnapshot && $snapshot !== null) {
                    $error = 'unexpected extra positional argument: ' . $arg;
                    break;
                }
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $help = true;
                continue;
            }
            if (preg_match('~^--bucket=(.+)~', $arg, $m)) {
                $o->bucket = $m[1];
                continue;
            }
            if (preg_match('~^--region=(.+)~', $arg, $m)) {
                $o->region = $m[1];
                continue;
            }
            if (preg_match('~^--key=(.+)~', $arg, $m)) {
                $o->key = $m[1];
                continue;
            }
            if (preg_match('~^--secret=(.+)~', $arg, $m)) {
                $o->secret = $m[1];
                continue;
            }
            if (preg_match('~^--prefix=(.+)~', $arg, $m)) {
                $o->prefix = trim($m[1], '/');
                continue;
            }
            if (preg_match('~^--port=(\d+)~', $arg, $m)) {
                $o->port = $m[1];
                continue;
            }
            if (preg_match('~^--endpoint=(.+)~', $arg, $m)) {
                $o->endpoint = rtrim($m[1], '/');
                continue;
            }
            if (preg_match('~^--timeout=(\d+)~', $arg, $m)) {
                $o->timeout = max(1, (int) $m[1]);
                continue;
            }
            if ($arg === '--no-run') {
                $o->noRun = true;
                continue;
            }
            if ($arg === '--detach') {
                $o->detach = true;
                continue;
            }
            if ($arg === '--anon') {
                $o->anon = true;
                continue;
            }
            if (preg_match('~^--provider=(.+)~', $arg, $m)) {
                $o->provider = strtolower($m[1]);
                continue;
            }
            $error = 'unknown option: ' . $arg;
            break;
        }
        return [$o, $snapshot, $help, $error];
    }

    public function normalize(): ?string {
        if ($this->anon) {
            $this->key = '';
            $this->secret = '';
        }
        if ($this->endpoint === '' && $this->noRun) {
            return '--no-run requires --endpoint=<url>';
        }
        if ($this->detach && $this->endpoint === '') {
            return '--detach currently requires --endpoint (cannot discover tunnel URL before backgrounding)';
        }
        return null;
    }
}
