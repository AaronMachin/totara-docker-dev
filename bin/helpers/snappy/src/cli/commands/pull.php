<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Hosting\remote_codec;
use Throwable;
use RuntimeException;

class pull implements command {
    public function name(): string { return 'pull'; }
    public function description(): string { return 'Retrieve a snapshot from a remote into local storage (pull <uid|prefix> [--remote=name]|[--encoded=STR] [--force] [--keep-remote])'; }

    public function run(array $args, context $ctx): int {
        // Reworked argument parsing: options can be anywhere; first non-option token is snapshot id/prefix.
        $remoteOpt = null; $force = false; $encodedStr = null; $keepRemote = false; $nonOption = [];
        foreach ($args as $arg) {
            if ($arg === '--force') { $force = true; continue; }
            if ($arg === '--keep-remote') { $keepRemote = true; continue; }
            if (str_starts_with($arg, '--remote=')) { $remoteOpt = substr($arg, 9); continue; }
            if (str_starts_with($arg, '--encoded=')) { $encodedStr = substr($arg, 10); continue; }
            if (str_starts_with($arg, '--')) { fwrite(STDERR, "unknown option: $arg\n"); return 1; }
            // positional
            $nonOption[] = $arg;
        }
        if (count($nonOption) === 0) {
            fwrite(STDERR, "usage: tsnap pull <uid|prefix> [--remote=name]|[--encoded=STR] [--force] [--keep-remote]\nYou can place options before or after the uid.\n");
            return 1;
        }
        $token = $nonOption[0];

        $tempRemoteName = null;
        $tempCreated = false;
        if ($encodedStr !== null) {
            if ($remoteOpt !== null) {
                fwrite(STDERR, "--remote and --encoded are mutually exclusive\n");
                return 1;
            }
            try {
                $decoded = remote_codec::decode($encodedStr);
            } catch (RuntimeException $e) {
                fwrite(STDERR, 'decode failed: ' . $e->getMessage() . "\n");
                return 2;
            }
            // Validate required fields
            $required = ['t','e','b','r'];
            foreach ($required as $r) {
                if (!isset($decoded[$r]) || $decoded[$r] === '') {
                    fwrite(STDERR, "encoded remote missing field: $r\n");
                    return 2;
                }
            }
            if ($decoded['t'] !== 's3') {
                fwrite(STDERR, "unsupported encoded remote type: {$decoded['t']}\n");
                return 2;
            }
            $endpoint = $decoded['e'];
            if (!preg_match('~^https://~', $endpoint)) {
                fwrite(STDERR, "refusing non-https endpoint in encoded remote\n");
                return 2;
            }
            $remoteConfig = [
                'endpoint' => $endpoint,
                'bucket' => $decoded['b'],
                'region' => $decoded['r'],
                'key' => $decoded['k'] ?? '',
                'secret' => $decoded['s'] ?? '',
                'path_style' => true, // safer for ngrok/minio
            ];
            $fingerprint = sha1(json_encode($remoteConfig, JSON_UNESCAPED_SLASHES));
            $baseName = 'enc_' . substr($fingerprint, 0, 8);
            $name = $baseName;
            $n = 1;
            while ($ctx->registry->has($name)) {
                // Compare existing config
                try {
                    $storage = $ctx->registry->storage($name); // may not expose config directly
                    // If exists we assume duplicate; reuse name
                    break;
                } catch (Throwable $e) {
                    $name = $baseName . '_' . $n++;
                }
            }
            if (!$ctx->registry->has($name)) {
                try {
                    $ctx->registry->add($name, 's3', $remoteConfig);
                    $tempCreated = true;
                } catch (Throwable $e) {
                    fwrite(STDERR, 'failed to register ephemeral remote: ' . $e->getMessage() . "\n");
                    return 2;
                }
            }
            $remoteOpt = $name;
            $tempRemoteName = $name;
        }

        // If remote specified, pull directly
        if ($remoteOpt !== null) {
            if ($remoteOpt === 'local') {
                fwrite(STDERR, "--remote cannot be 'local' (already local store)\n");
                return 2;
            }
            if (!$ctx->registry->has($remoteOpt)) {
                fwrite(STDERR, "unknown remote: $remoteOpt\n");
                return 2;
            }
            try {
                $uid = $ctx->manager->pull($token, $remoteOpt, $force);
            } catch (Throwable $e) {
                if ($tempRemoteName && $tempCreated && !$keepRemote) {
                    // best-effort cleanup
                    try { $ctx->registry->remove($tempRemoteName); } catch (Throwable $ignored) {}
                }
                fwrite(STDERR, 'pull failed: ' . $e->getMessage() . "\n");
                return 3;
            }
            echo "retrieved snapshot $uid from $remoteOpt into local\n";
            if ($tempRemoteName && $tempCreated && !$keepRemote) {
                try { $ctx->registry->remove($tempRemoteName); } catch (Throwable $ignored) {}
            }
            return 0;
        }

        // No remote specified: existing behaviour scanning all remotes
        $matches = [];
        foreach ($ctx->registry->names() as $r) {
            if ($r === 'local') { continue; }
            $resolved = $ctx->manager->resolve_uid($token, $r);
            if ($resolved !== '') { $matches[$r] = $resolved; }
        }
        if (!$matches) {
            fwrite(STDERR, "no matching snapshot for prefix '$token' on any remote; specify --remote if needed\n");
            return 4;
        }
        if (count($matches) > 1) {
            $list = [];
            foreach ($matches as $r => $u) { $list[] = $r . '(' . $u . ')'; }
            fwrite(STDERR, "ambiguous prefix '$token' found in multiple remotes: " . implode(', ', $list) . "\nSpecify --remote=<name>.\n");
            return 5;
        }
        $remote = array_key_first($matches);
        $uidFull = $matches[$remote];
        try {
            $uid = $ctx->manager->pull($uidFull, $remote, $force);
        } catch (Throwable $e) {
            fwrite(STDERR, 'pull failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo "retrieved snapshot $uid from $remote into local\n";
        return 0;
    }
}
