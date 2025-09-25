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
    private string $config_file;     // config.json path (remotes + future options)
    private array $remotes = [];
    private array $options = [];
    private array $storage_cache = [];
    private int $config_version = 1;

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
        $this->config_file = $this->config_dir . '/config.json';
        $this->load();
        $this->ensure_local();
    }

    private function load(): void {
        $this->remotes = [];
        $this->options = [];
        if (is_file($this->config_file)) {
            $data = @json_decode(@file_get_contents($this->config_file), true);
            if (is_array($data)) {
                $this->config_version = (int)($data['version'] ?? 1);
                if (isset($data['remotes']) && is_array($data['remotes'])) {
                    $this->remotes = $data['remotes'];
                }
                if (isset($data['options']) && is_array($data['options'])) {
                    $this->options = $data['options'];
                }
            }
        }
    }

    private function persist(): void {
        $data = [
            'version' => $this->config_version,
            'remotes' => $this->remotes,
            'options' => $this->options,
            'updated' => date('c'),
        ];
        @file_put_contents($this->config_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function reload(): void { $this->load(); }

    private function ensure_local(): void {
        if (!isset($this->remotes['local'])) {
            $this->remotes['local'] = [
                'type' => 'local',
                'path' => $this->base_path,
                'created' => date('c'),
            ];
            if (!isset($this->options['default_remote'])) {
                $this->options['default_remote'] = 'local';
            }
            $this->persist();
        }
    }

    public function options(): array { return $this->options; }
    public function get_option(string $key, $default = null) { return $this->options[$key] ?? $default; }
    public function set_option(string $key, $value): void { $this->options[$key] = $value; $this->persist(); }

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
