<?php
namespace Snappy\Snapshot;

use Snappy\Storage\storage;
use Snappy\Storage\local_storage;
use Snappy\Storage\s3_storage;
use RuntimeException;

/**
 * Registry of snapshot remotes (local + user defined).
 * Persisted as JSON under config dir.
 */
class remote_registry {
    private string $base_path;       // base working directory for local snapshots
    private string $config_dir;      // directory to store config
    private string $config_file;     // remotes.json path
    private array $remotes = [];
    private array $storage_cache = [];

    public function __construct(string $base_path) {
        $trim = rtrim($base_path, '/');
        if (basename($trim) === 'snaps') { // backward compat convenience
            $trim = dirname($trim);
        }
        $this->base_path = $trim;
        $this->config_dir = $this->base_path . '/.snappy';
        if (!is_dir($this->config_dir)) {
            @mkdir($this->config_dir, 0777, true);
        }
        $this->config_file = $this->config_dir . '/remotes.json';
        $this->load();
        $this->ensure_local();
    }

    private function load(): void {
        if (is_file($this->config_file)) {
            $data = @json_decode(@file_get_contents($this->config_file), true);
            if (is_array($data) && isset($data['remotes']) && is_array($data['remotes'])) {
                $this->remotes = $data['remotes'];
            }
        }
    }

    private function persist(): void {
        $data = [ 'remotes' => $this->remotes, 'updated' => date('c') ];
        @file_put_contents($this->config_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function ensure_local(): void {
        if (!isset($this->remotes['local'])) {
            $this->remotes['local'] = [
                'type' => 'local',
                'path' => $this->base_path,
                'created' => date('c'),
            ];
            $this->persist();
        }
    }

    public function list(): array { return $this->remotes; }

    public function add(string $name, string $type, array $config): void {
        // Validation enhancements
        if ($name === 'local') { throw new RuntimeException('Cannot redefine reserved remote "local"'); }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) { throw new RuntimeException('Invalid remote name (allowed: a-zA-Z0-9._-)'); }
        if (isset($this->remotes[$name])) { throw new RuntimeException('Remote already exists: ' . $name); }
        $allowed = ['s3'];
        if (!in_array($type, $allowed, true)) { throw new RuntimeException('Unsupported remote type: ' . $type); }
        if ($type === 's3') {
            $required = ['endpoint','bucket','region','key','secret'];
            $missing = [];
            foreach ($required as $k) { if (($config[$k] ?? '') === '') { $missing[] = $k; } }
            if ($missing) { throw new RuntimeException('Missing s3 config keys: ' . implode(', ', $missing)); }
        }
        $this->remotes[$name] = [ 'type' => $type, 'config' => $config, 'created' => date('c') ];
        $this->persist();
        unset($this->storage_cache[$name]);
    }

    public function remove(string $name): void {
        if ($name === 'local') { throw new RuntimeException('Cannot remove local remote'); }
        if (!isset($this->remotes[$name])) { throw new RuntimeException('Unknown remote ' . $name); }
        unset($this->remotes[$name], $this->storage_cache[$name]);
        $this->persist();
    }

    public function has(string $name): bool { return isset($this->remotes[$name]); }

    public function storage(string $name): storage {
        if (!isset($this->remotes[$name])) {
            throw new RuntimeException('Unknown remote ' . $name);
        }
        if (isset($this->storage_cache[$name])) { return $this->storage_cache[$name]; }
        $meta = $this->remotes[$name];
        $type = $meta['type'];
        if ($type === 'local') {
            $st = new local_storage($meta['path']);
        } elseif ($type === 's3') {
            $cfg = $meta['config'] ?? [];
            $st = new s3_storage($cfg);
        } else {
            throw new RuntimeException('Unsupported remote type ' . $type);
        }
        $this->storage_cache[$name] = $st;
        return $st;
    }

    public function local_base_path(): string {
        return $this->remotes['local']['path'];
    }

    public function names(): array { return array_keys($this->remotes); }
    public function first_non_local(): ?string {
        foreach ($this->remotes as $name => $meta) {
            if ($name !== 'local') { return $name; }
        }
        return null;
    }
}
