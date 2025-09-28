<?php
namespace Snappy\Share;

/**
 * share_registry maintains the single-use share token metadata file (.snappy/shares.json).
 * Schema v1: { version:1, tokens: [ { token_hash, uid, created_utc, expires_utc, used_utc|null, meta:{ tags:[], message_first_line:"" } } ] }
 */
class share_registry {
    private string $basePath; // snapshot root (parent of /snaps)

    public function __construct(string $basePath) { $this->basePath = rtrim($basePath,'/'); }

    private function filePath(): string { return $this->basePath . '/.snappy/shares.json'; }

    private function ensureDir(): void {
        $dir = $this->basePath . '/.snappy';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    }

    public function load(): array {
        $file = $this->filePath();
        if (!is_file($file)) { return ['version'=>1,'tokens'=>[]]; }
        $json = @file_get_contents($file);
        $data = @json_decode((string)$json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['tokens'] ?? null)) {
            return ['version'=>1,'tokens'=>[]];
        }
        return $data;
    }

    public function list(): array { return $this->load()['tokens']; }

    public function hasHash(string $hash): bool {
        foreach ($this->list() as $t) { if (($t['token_hash'] ?? '') === $hash) { return true; } }
        return false;
    }

    public function add(array $record): void {
        $data = $this->load();
        $data['tokens'][] = $record;
        // newest first
        usort($data['tokens'], fn($a,$b)=>strcmp($b['created_utc']??'', $a['created_utc']??''));
        $this->write($data);
    }

    private function write(array $data): void {
        $this->ensureDir();
        $path = $this->filePath();
        $tmp = sys_get_temp_dir() . '/snappy_shares_' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) { return; }
        @file_put_contents($tmp, $json);
        @rename($tmp, $path);
    }
}

