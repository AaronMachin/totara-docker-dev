<?php
namespace Snappy\Snapshot;

/*
 * removed manual require_once; classes autoloaded via snappy_autoload.php
 */

class remote_cache {
    const META_DIR = 'remote_meta';

    private string $root_dir;

    public function __construct(string $root_dir) {
        $this->root_dir = rtrim($root_dir, '/');
    }

    private function cache_dir(): string {
        $dir = $this->root_dir . '/.snappy';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private function meta_dir(): string {
        $dir = $this->cache_dir() . '/' . self::META_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function list_cache_file(string $prefix): string {
        $safe = $prefix === '' ? 'root' : preg_replace('/[^A-Za-z0-9._-]/', '_', $prefix);
        return $this->cache_dir() . '/remote_' . $safe . '.json';
    }

    public function load_list_cache(string $prefix): ?array {
        $file = $this->list_cache_file($prefix);
        if (!is_file($file)) {
            return null;
        }
        $data = @json_decode(@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function save_list_cache(string $prefix, array $objects, int $limit): array {
        $file = $this->list_cache_file($prefix);
        $payload = [
            'retrieved_at' => date('c'),
            'prefix' => $prefix,
            'limit' => $limit,
            'objects' => $objects,
        ];
        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
        return $payload;
    }

    public function meta_cache_file(string $uid): string {
        return $this->meta_dir() . '/' . $uid . '.json';
    }

    public function load_meta(string $uid): ?array {
        $file = $this->meta_cache_file($uid);
        if (!is_file($file)) {
            return null;
        }
        $data = @json_decode(@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function save_meta(string $uid, string $last_modified, array $meta): array {
        $payload = [
            'uid' => $uid,
            'meta_last_modified' => $last_modified,
            'cached_at' => date('c'),
            'meta' => $meta,
        ];
        @file_put_contents($this->meta_cache_file($uid), json_encode($payload, JSON_PRETTY_PRINT));
        return $payload;
    }

    public function invalidate(): void {
        $root_cache = $this->list_cache_file('');
        if (is_file($root_cache)) {
            @unlink($root_cache);
        }
        $snaps_cache = $this->list_cache_file('snaps');
        if (is_file($snaps_cache)) {
            @unlink($snaps_cache);
        }
    }
}
