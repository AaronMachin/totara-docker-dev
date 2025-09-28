<?php

namespace Snappy\Config;

use Snappy\Support\Exception\ConfigException;

class config_manager {
    private string $file;
    private string $schemaFile;
    private array $raw = [];
    private array $schema = [];
    private ?array $resolved = null; // cache of raw + defaults + expansions
    private bool $dirty = false;

    public function __construct(string $configFile, string $baseDir) {
        $this->file = $configFile;
        $this->schemaFile = dirname($configFile) . '/config.schema.json';
        $this->loadSchema();
        $this->loadRaw();
    }

    // ---------------- Public API ----------------

    /** Return fully resolved configuration (raw + defaults). */
    public function all(): array {
        if ($this->resolved === null) { $this->buildResolved(); }
        return $this->resolved;
    }

    /** Get a value (dot path) or default if missing (schema default applied automatically). */
    public function get(string $path, $fallback = null) {
        $config = $this->all();
        return $this->readPath($config, $path, $fallback);
    }

    /** Check if a raw (user-specified) value exists (ignores defaults). */
    public function has(string $path): bool {
        return $this->readPath($this->raw, $path, '__MISSING__') !== '__MISSING__';
    }

    /** Set a value (dot path). */
    public function set(string $path, $value, bool $persist = false): self {
        $this->writePath($this->raw, $path, $value);
        $this->dirty = true;
        $this->resolved = null;
        if ($persist) { $this->save(); }
        return $this;
    }

    /** Remove a value if present (raw only). */
    public function remove(string $path, bool $persist = false): self {
        $segments = $path === '' ? [] : explode('.', $path);
        if (!$segments) return $this;
        $ref =& $this->raw;
        foreach ($segments as $i => $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) return $this;
            if ($i === count($segments)-1) { unset($ref[$seg]); }
            else { $ref =& $ref[$seg]; }
        }
        $this->dirty = true;
        $this->resolved = null;
        if ($persist) { $this->save(); }
        return $this;
    }

    /** Persist raw config to disk (adds updated timestamp). */
    public function save(): void {
        if (!$this->dirty) return;
        $payload = $this->raw;
        $payload['updated'] = date('c');
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if (@file_put_contents($this->file, $json) === false) {
            throw new ConfigException('Failed to write config file: '.$this->file);
        }
        $this->dirty = false;
    }

    /** Validate required fields present either in raw or via defaults. */
    public function validate(): array {
        $errors = [];
        $warnings = [];
        foreach ($this->schema['fields'] as $path => $spec) {
            $required = (bool)($spec['required'] ?? false);
            if ($required) {
                $val = $this->get($path, null); // resolved
                if ($val === null || $val === '') {
                    $errors[] = "Missing required field: $path";
                }
            }
        }
        return ['errors'=>$errors,'warnings'=>$warnings];
    }

    // ---------------- Internal: loading & resolving ----------------

    private function loadSchema(): void {
        if (!is_file($this->schemaFile)) { $this->schema = ['fields'=>[]]; return; }
        $raw = @file_get_contents($this->schemaFile);
        $data = @json_decode($raw, true);
        if (!is_array($data)) { $this->schema = ['fields'=>[]]; return; }
        $fields = $data['fields'] ?? [];
        if (!is_array($fields)) { $fields = []; }
        $this->schema = ['fields'=>$fields];
    }

    private function loadRaw(): void {
        if (!is_file($this->file)) {
            // Minimal skeleton; let defaults fill gaps.
            $this->raw = [
                'version' => 1,
                'remotes' => [],
                'options' => [],
                'updated' => date('c'),
            ];
            $this->dirty = true; // will save on first explicit persist
            return;
        }
        $raw = @file_get_contents($this->file);
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            throw new ConfigException('Invalid config JSON in '.$this->file);
        }
        $this->raw = $data;
    }

    private function buildResolved(): void {
        $resolved = $this->raw;
        foreach ($this->schema['fields'] as $path => $spec) {
            $defaultExists = array_key_exists('default', $spec);
            $current = $this->readPath($resolved, $path, null);
            if (($current === null || $current === '' ) && $defaultExists) {
                $this->writePath($resolved, $path, $spec['default']);
            }
        }
        // Placeholder expansion pass (strings only)
        array_walk_recursive($resolved, function (&$val) {
            if (is_string($val)) { $val = $this->expand($val); }
        });
        $this->resolved = $resolved;
    }

    // ---------------- Utility helpers ----------------

    private function readPath(array $root, string $path, $fallback) {
        if ($path === '') return $root;
        $segments = explode('.', $path);
        $cur = $root;
        foreach ($segments as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return $fallback;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    private function writePath(array &$root, string $path, $value): void {
        $segments = $path === '' ? [] : explode('.', $path);
        if (!$segments) return;
        $ref =& $root;
        foreach ($segments as $i => $seg) {
            if ($i === count($segments)-1) {
                $ref[$seg] = $value;
            } else {
                if (!isset($ref[$seg]) || !is_array($ref[$seg])) { $ref[$seg] = []; }
                $ref =& $ref[$seg];
            }
        }
    }

    private function expand(string $value): string {
        $home = getenv('HOME') ?: '~';
        return str_replace('<home>', $home, $value);
    }
}
