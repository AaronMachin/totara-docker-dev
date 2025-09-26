<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Hosting\remote_codec;
use Throwable;
use RuntimeException;

class pull extends base_command {
    public function name(): string {
        return 'pull';
    }

    public function description(): string {
        return 'Retrieve a snapshot from a remote into local storage';
    }

    public function usage(): string {
        return 'Usage: tsnap pull <uid|prefix> [--remote=name]|[--encoded=STR] [--force] [--keep-remote]\nRetrieve snapshot from a remote into local storage. --encoded supplies a temporary remote descriptor.';
    }

    public function examples(): array {
        return ['tsnap pull abc123 --remote=origin', 'tsnap pull --encoded=ENCODED_STR f00ba4', 'tsnap pull 1a2b3c', 'tsnap pull abc --remote=enc_deadbeef'];
    }

    public function run(array $args, context $ctx): int {
        [$opts, $positionals, $errors] = $this->parsePullArgs($args);
        if ($errors) {
            foreach ($errors as $e) {
                fwrite(STDERR, $e . "\n");
            }
            return 1;
        }
        $token = $positionals[0] ?? '';
        if ($token === '') {
            fwrite(STDERR, "snapshot uid or unique prefix required\n");
            return 1;
        }
        $remoteOpt = $opts['remote'];
        $encodedStr = $opts['encoded'];
        $force = $opts['force'];
        $keepRemote = $opts['keep'];

        // encoded remote handling (may register temp remote)
        if ($encodedStr !== null) {
            $res = $this->registerEncodedRemote($encodedStr, $remoteOpt, $ctx);
            if ($res['error']) {
                fwrite(STDERR, $res['error'] . "\n");
                return $res['code'];
            }
            $remoteOpt = $res['remote'];
            $temp = $res['temp'];
        } else {
            $temp = null;
        }

        if ($remoteOpt !== null) {
            return $this->pullFromSpecificRemote($token, $remoteOpt, $force, $keepRemote, $temp, $ctx);
        }
        return $this->pullByScanningRemotes($token, $force, $ctx);
    }

    private function parsePullArgs(array $argv): array {
        $def = [
            'force' => ['flags' => ['--force'], 'type' => 'bool', 'default' => false],
            'keep' => ['flags' => ['--keep-remote'], 'type' => 'bool', 'default' => false],
            'remote' => ['prefix' => '--remote=', 'type' => 'string', 'default' => null],
            'encoded' => ['prefix' => '--encoded=', 'type' => 'string', 'default' => null],
        ];
        $parsed = $this->parseArgs($argv, $def);
        return [$parsed['options'], $parsed['positionals'], $parsed['errors']];
    }

    private function registerEncodedRemote(string $encoded, ?string $remoteOpt, context $ctx): array {
        if ($remoteOpt !== null) {
            return ['error' => '--remote and --encoded are mutually exclusive', 'code' => 1];
        }
        try {
            $decoded = remote_codec::decode($encoded);
        } catch (RuntimeException $e) {
            return ['error' => 'decode failed: ' . $e->getMessage(), 'code' => 2];
        }
        foreach (['t', 'e', 'b', 'r', 's', 'k'] as $r) {
            if (!isset($decoded[$r]) || $decoded[$r] === '') {
                return ['error' => "encoded remote missing field: $r", 'code' => 2];
            }
        }
        if (($decoded['t'] ?? '') !== 's3') {
            return ['error' => 'unsupported encoded remote type: ' . ($decoded['t'] ?? '') . '', 'code' => 2];
        }
        $endpoint = $decoded['e'];
        if (!preg_match('~^https://~', $endpoint)) {
            return ['error' => 'refusing non-https endpoint in encoded remote', 'code' => 2];
        }
        $remoteConfig =
            ['endpoint' => $endpoint, 'bucket' => $decoded['b'], 'region' => $decoded['r'], 'key' => $decoded['k'] ?? '', 'secret' => $decoded['s'] ?? '', 'path_style' => true];
        $fingerprint = sha1(json_encode($remoteConfig, JSON_UNESCAPED_SLASHES));
        $base = 'enc_' . substr($fingerprint, 0, 8);
        $name = $base;
        $n = 1;
        while ($ctx->registry->has($name)) {
            try {
                $ctx->registry->storage($name);
                break;
            } catch (Throwable $e) {
                $name = $base . '_' . ($n++);
            }
        }
        if (!$ctx->registry->has($name)) {
            try {
                $ctx->registry->add($name, 's3', $remoteConfig);
            } catch (Throwable $e) {
                return ['error' => 'failed to register ephemeral remote: ' . $e->getMessage(), 'code' => 2];
            }
            $temp = ['name' => $name, 'created' => true];
        } else {
            $temp = ['name' => $name, 'created' => false];
        }
        return ['remote' => $name, 'temp' => $temp, 'error' => null, 'code' => 0];
    }

    private function pullFromSpecificRemote(string $token, string $remote, bool $force, bool $keepRemote, ?array $temp, context $ctx): int {
        if ($remote === 'local') {
            fwrite(STDERR, "--remote cannot be 'local' (already local store)\n");
            return 2;
        }
        if (!$ctx->registry->has($remote)) {
            fwrite(STDERR, "unknown remote: $remote\n");
            return 2;
        }
        try {
            $uid = $ctx->manager->pull($token, $remote, $force);
        } catch (Throwable $e) {
            $this->cleanupTemp($temp, !$keepRemote, $ctx);
            fwrite(STDERR, 'pull failed: ' . $e->getMessage() . "\n");
            return 3;
        }
        echo "retrieved snapshot $uid from $remote into local\n";
        $this->cleanupTemp($temp, !$keepRemote, $ctx);
        return 0;
    }

    private function pullByScanningRemotes(string $token, bool $force, context $ctx): int {
        $matches = [];
        foreach ($ctx->registry->names() as $r) {
            if ($r === 'local') {
                continue;
            }
            $resolved = $ctx->manager->resolve_uid($token, $r);
            if ($resolved !== '') {
                $matches[$r] = $resolved;
            }
        }
        if (!$matches) {
            fwrite(STDERR, "no matching snapshot for prefix '$token' on any remote; specify --remote if needed\n");
            return 4;
        }
        if (count($matches) > 1) {
            $list = [];
            foreach ($matches as $r => $u) {
                $list[] = $r . '(' . $u . ')';
            }
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

    private function cleanupTemp(?array $temp, bool $shouldRemove, context $ctx): void {
        if (!$temp || !$shouldRemove || empty($temp['created'])) {
            return;
        }
        try {
            $ctx->registry->remove($temp['name']);
        } catch (Throwable $e) { /* ignore */
        }
    }
}
