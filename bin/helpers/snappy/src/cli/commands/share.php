<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Hosting\ngrok_provider;
use Snappy\Hosting\options;
use Snappy\Hosting\manager;
use RuntimeException;

class share extends base_command {
    public function name(): string {
        return 'share';
    }

    public function description(): string {
        return 'Share a local snapshot by ensuring a background host is running and printing a pull command';
    }

    public function usage(): string {
        return "Usage: tsnap share <uid|prefix> [--bucket=NAME --region=REG --port=8000 --key=K --secret=S] [--prefix=P] [--endpoint=URL] [--timeout=SEC]\nPackages snapshot, uploads, presigns and prints pull command (use with tsnap pull --share=TOKEN).";
    }

    public function examples(): array {
        return [
            'tsnap share 7fa12c3',
            'tsnap share --endpoint=https://example.ngrok-free.app 7fa12c3',
            'tsnap share --bucket=mybucket --region=us-east-1 --port=9000 7fa12c3'
        ];
    }

    private function waitForBucket(string $base, int $timeoutSeconds = 20): bool {
        $deadline = time() + $timeoutSeconds;
        $healthUrls = [
            rtrim($base,'/').'/minio/health/ready',
            rtrim($base,'/').'/minio/health/live',
            rtrim($base,'/').'/'
        ];
        while (time() < $deadline) {
            foreach ($healthUrls as $url) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FAILONERROR, false);
                $ok = curl_exec($ch) !== false; // body not needed
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($ok && $status >= 200 && $status < 500) { return true; }
            }
            usleep(300000); // 300ms
        }
        return false;
    }

    private function pruneOldShares(\Snappy\Storage\s3_storage $storage, string $bucket, int $expirySeconds, int $graceSeconds): void {
        $prefix = 'shares/';
        try { $objects = $storage->list_objects($prefix, 1000); } catch (\Throwable $e) { return; }
        $now = time();
        foreach ($objects as $o) {
            $k = $o['key'];
            // Determine age from object key naming convention (shares/<uid>.tar.gz) using mtime via LastModified
            $lm = strtotime($o['last_modified'] ?? '') ?: 0;
            if ($lm && ($lm + $expirySeconds + $graceSeconds) < $now) {
                try { $storage->delete_object($k); } catch (\Throwable $e) { /* ignore */ }
            }
        }
    }

    public function run(array $args, context $ctx): int {
        [$opts, $snapshotToken, $help, $error] = options::parse($args, true);
        if ($help) { $this->display_help(); return 0; }
        if ($error) { fwrite(STDERR, $error."\n"); return 1; }
        if ($snapshotToken === null) { fwrite(STDERR, "snapshot uid or unique prefix required\n"); return 1; }
        if ($err = $opts->normalize()) { fwrite(STDERR, $err."\n"); return 1; }
        $resolved = $ctx->manager->resolve_uid($snapshotToken, 'local');
        if ($resolved === '') { fwrite(STDERR, "no or ambiguous match for '$snapshotToken' (local snapshots)\n"); return 1; }
        // Derive bucket & creds from configured default remote if user used default bucket value
        $cfgAll = $ctx->config->all();
        $defaultRemote = $cfgAll['options']['default_remote'] ?? null;
        if ($defaultRemote && isset($cfgAll['remotes'][$defaultRemote])) {
            $r = $cfgAll['remotes'][$defaultRemote];
            if (($r['type'] ?? '') === 's3') {
                $rcfg = $r['config'] ?? [];
                $configuredBucket = $rcfg['bucket'] ?? '';
                // If user didn't override (still initial 'snappy') and configured bucket differs
                if ($configuredBucket !== '' && $opts->bucket === 'snappy') { $opts->bucket = $configuredBucket; }
                if ($opts->key === '') { $opts->key = $rcfg['key'] ?? $opts->key; }
                if ($opts->secret === '') { $opts->secret = $rcfg['secret'] ?? $opts->secret; }
                if ($opts->region === '' && !empty($rcfg['region'])) { $opts->region = $rcfg['region']; }
            }
        }
        // ---- Credential fallback (must happen before upload/presign) ----
        if ($opts->key === '') {
            $opts->key = getenv('MINIO_ROOT_USER')
                ?: getenv('BUCKET_USER')
                ?: $ctx->config->get('options.s3.key', '')
                ?: 'admin';
        }
        if ($opts->secret === '') {
            $opts->secret = getenv('MINIO_ROOT_PASSWORD')
                ?: getenv('BUCKET_SECRET')
                ?: $ctx->config->get('options.s3.secret', '')
                ?: 'admin12345';
        }
        if ($opts->region === '') {
            $opts->region = $ctx->config->get('options.s3.region', 'us-east-1');
        }
        if ($opts->key === '' || $opts->secret === '') {
            fwrite(STDERR, "missing S3 credentials (provide --key/--secret or set MINIO_ROOT_USER / MINIO_ROOT_PASSWORD)\n");
            return 1;
        }
        // ---------------------------------------------------------------
        // Ensure host (tunnel) running to obtain external endpoint
        $manager = new manager($ctx->registry->local_base_path(), new ngrok_provider());
        try { $state = $manager->ensureRunning($opts); }
        catch (RuntimeException $e) { fwrite(STDERR, 'failed to start host: '.$e->getMessage()."\n"); return 2; }
        $externalEndpoint = $state['endpoint'] ?? '';
        if ($externalEndpoint === '') { fwrite(STDERR, "host did not provide endpoint\n"); return 2; }
        // Package snapshot directory into tar.gz
        $snapDir = $ctx->manager->local_path($resolved);
        if (!is_dir($snapDir)) { fwrite(STDERR, "snapshot directory missing: $resolved\n"); return 3; }
        $parent = dirname($snapDir);
        $tarPath = sys_get_temp_dir() . '/snappy_share_' . $resolved . '.tar.gz';
        if (is_file($tarPath)) { @unlink($tarPath); }
        $cmd = 'tar -C ' . escapeshellarg($parent) . ' -czf ' . escapeshellarg($tarPath) . ' ' . escapeshellarg(basename($snapDir)) . ' 2>&1';
        $out = []; $rc = 0; exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($tarPath)) { fwrite(STDERR, "tar packaging failed rc=$rc output=".implode('\n',$out)."\n"); return 4; }
        $size = filesize($tarPath);
        $hash = hash_file('sha256', $tarPath);
        // Upload to S3 (local internal endpoint)
        $internalEndpoint = 'http://127.0.0.1:' . $opts->port; // assumes MinIO mapped
        if (!$this->waitForBucket($internalEndpoint)) {
            fwrite(STDERR, "bucket service not reachable at $internalEndpoint (start it with 'tup bucket' and retry)\n");
            return 5;
        }
        try {
            $storage = new \Snappy\Storage\s3_storage([
                'endpoint' => $internalEndpoint,
                'bucket' => $opts->bucket,
                'region' => $opts->region ?: 'us-east-1',
                'key' => $opts->key,
                'secret' => $opts->secret,
                'path_style' => true,
            ]);
            try { $storage->ensure_bucket(); } catch (\Throwable $e) { /* ignore */ }
            $objectKey = 'shares/' . $resolved . '.tar.gz';
            $storage->put_object($objectKey, $tarPath);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'upload failed: ' . $e->getMessage() . "\n"); return 5;
        }
        // Build presigned URL using external endpoint
        $expirySeconds = (int)$ctx->config->get('options.presign_default_expiry_seconds', 1800);
        $expiresAt = time() + $expirySeconds; // still show to user, but not embedded
        $presigned = \Snappy\Util\presign::s3_get_with_fallback(
            $externalEndpoint,
            $opts->bucket,
            $objectKey,
            $opts->region ?: 'us-east-1',
            $opts->key,
            $opts->secret,
            $expirySeconds,
            (bool)$ctx->config->get('options.debug', false) || getenv('SNAPPY_PRESIGN_DEBUG') !== false
        );
        $grace = (int)$ctx->config->get('options.presign_cleanup_grace_seconds', 600);
        // Try prune (best effort, do not block share)
        try { $this->pruneOldShares($storage, $opts->bucket, $expirySeconds, $grace); } catch (\Throwable $e) { /* ignore */ }
        // Minimal payload with expiry and hash
        $payload = [ 't' => 'ps', 'u' => $presigned, 'x' => $expiresAt, 'h' => substr($hash,0,16) ];
        $encoded = \Snappy\Hosting\remote_codec::encode($payload);
        echo "Snapshot UID: $resolved\n";
        echo "Packaged size: $size bytes\n";
        echo "Endpoint (tunnel): $externalEndpoint\n";
        echo "Expires in: $expirySeconds seconds (at " . date('c', $expiresAt) . ")\n";
        echo "Share token: $encoded\n\n";
        echo "Pull command (copy/paste):\n";
        echo "tsnap pull --share=$encoded\n";
        echo "\nDirect download (curl):\n";
        echo "curl -L --output {$resolved}.tar.gz '$presigned'\n";
        @unlink($tarPath);
        return 0;
    }
}
