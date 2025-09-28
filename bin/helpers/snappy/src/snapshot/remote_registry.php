<?php

namespace Snappy\Snapshot;

use Exception; // keep for generic default() exceptions for now
use Snappy\Support\Exception\RemoteException;
use Snappy\Support\Exception\ValidationException;
use Snappy\Storage\storage;
use Snappy\Storage\local_storage;
use Snappy\Storage\s3_storage;
use Snappy\Config\config_manager;

class remote_registry {
    private string $base_path;
    private array $storage_cache = [];
    private config_manager $cfg;

    public function __construct(config_manager $cfg, string $base_path) {
        $trim = rtrim($base_path, '/');
        if (basename($trim) === 'snaps') {
            $trim = dirname($trim);
        }
        $this->base_path = $trim;
        if (!is_dir($this->base_path)) {
            @mkdir($this->base_path, 0777, true);
        }
        $this->cfg = $cfg;
        $this->ensureLocal();
        $this->syncLocalPath();
    }

    private function ensureLocal(): void {
        if (!$this->cfg->has('remotes.local')) {
            $this->cfg->set('remotes.local', [
                'type' => 'local',
                'path' => $this->base_path,
                'created' => date('c'),
            ]);
            if (!$this->cfg->has('options.default_remote')) {
                $this->cfg->set('options.default_remote', 'local');
            }
            $this->cfg->save();
        }
    }

    private function syncLocalPath(): void {
        $cur = $this->cfg->get('remotes.local.path');
        if ($cur !== $this->base_path) {
            $this->cfg->set('remotes.local.path', $this->base_path)->save();
        }
    }

    public function options(): array {
        return $this->cfg->get('options', []);
    }

    public function get_option(string $key, $default = null) {
        return $this->cfg->get('options.' . $key, $default);
    }

    public function set_option(string $key, $value): void {
        $this->cfg->set('options.' . $key, $value, true);
    }

    public function list(): array {
        return $this->cfg->get('remotes', []);
    }

    public function has(string $name): bool {
        return $this->cfg->has('remotes.' . $name);
    }

    public function names(): array {
        return array_keys($this->cfg->get('remotes', []));
    }

    public function add(string $name, string $type, array $config): void {
        if ($name === 'local') { throw new ValidationException('Cannot redefine reserved remote "local"'); }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) { throw new ValidationException('Invalid remote name'); }
        if ($this->has($name)) { throw new ValidationException('Remote already exists: ' . $name); }
        if (!in_array($type, ['s3'], true)) { throw new ValidationException('Unsupported remote type: ' . $type); }
        if ($type === 's3') {
            $defaults = $this->cfg->get('options.s3', []);
            $autoKeys = ['endpoint', 'bucket', 'region', 'key', 'secret', 'path_style', 'debug'];
            foreach ($autoKeys as $k) {
                if (!array_key_exists($k, $config) || $config[$k] === '' || $config[$k] === null) {
                    if (isset($defaults[$k]) && $defaults[$k] !== '') {
                        $config[$k] = $defaults[$k];
                    }
                }
            }
            $required = ['endpoint', 'bucket', 'key', 'secret'];
            $missing = [];
            foreach ($required as $r) {
                if (($config[$r] ?? '') === '') {
                    $missing[] = $r;
                }
            }
            if ($missing) { throw new ValidationException('Missing s3 config keys: ' . implode(', ', $missing)); }
            if (!isset($config['region']) || $config['region'] === '') {
                $config['region'] = $defaults['region'] ?? 'us-east-1';
            }
        }
        $entry = ['type' => $type, 'created' => date('c')];
        if ($type === 's3') {
            $entry['config'] = $config;
        }
        if ($type === 'local') {
            $entry['path'] = $config['path'] ?? $this->base_path;
        }
        $this->cfg->set('remotes.' . $name, $entry)->save();
        unset($this->storage_cache[$name]);
    }

    public function remove(string $name): void {
        if ($name === 'local') { throw new ValidationException('Cannot remove local remote'); }
        if (!$this->has($name)) { throw new RemoteException('Unknown remote ' . $name); }
        $this->cfg->remove('remotes.' . $name, true);
        unset($this->storage_cache[$name]);
    }

    public function storage(string $name): storage {
        if (!$this->has($name)) { throw new RemoteException('Unknown remote ' . $name); }
        if (isset($this->storage_cache[$name])) {
            return $this->storage_cache[$name];
        }
        $meta = $this->cfg->get('remotes.' . $name);
        $type = $meta['type'] ?? '';
        if ($type === 'local') { $st = new local_storage($meta['path']); }
        elseif ($type === 's3') { $st = new s3_storage($meta['config'] ?? []); }
        else { throw new ValidationException('Unsupported remote type ' . $type); }
        return $this->storage_cache[$name] = $st;
    }

    public function local_base_path(): string {
        return $this->cfg->get('remotes.local.path', $this->base_path);
    }

    /** @throws Exception */
    public function default(): ?string {
        $default = $this->get_option('default_remote');
        if ($default === null) {
            throw new Exception('No default remote configured');
        }
        if (!$this->has($default)) {
            throw new Exception("Configured default remote '$default' does not exist");
        }
        return $default;
    }

    public function config_manager(): config_manager {
        return $this->cfg;
    }
}
