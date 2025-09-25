<?php
namespace Snappy\Snapshot;

use Snappy\Storage\storage;
use Snappy\Util\snapshot_uid;
use RuntimeException;

class snapshot_manager {
    private storage $storage;
    private remote_cache $cache;
    private string $root_dir;

    public function __construct(storage $storage, remote_cache $cache, string $root_dir) {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->root_dir = rtrim($root_dir, '/');
        if (!is_dir($this->root_dir)) {
            @mkdir($this->root_dir, 0777, true);
        }
    }

    public function root_dir(): string {
        return $this->root_dir;
    }

    public function snapshot_dir(string $uid): string {
        $shard = substr($uid, 0, 2);
        $dir = $this->root_dir . '/' . $shard . '/' . $uid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function create(string $type, string $message): string {
        $uid = snapshot_uid::generate();
        $dir = $this->snapshot_dir($uid);
        $meta = [
            'uid' => $uid,
            'created' => date('c'),
            'type' => $type,
            'message' => '',
            'files' => [],
            'file_checksums' => [],
        ];
        if ($type === 'sql') {
            $this->create_sql_backup($uid, $dir, $meta);
        } else {
            throw new RuntimeException('unknown snapshot type: ' . $type);
        }
        $meta['message'] = $message;
        $this->write_meta($dir, $meta);
        return $uid;
    }

    private function create_sql_backup(string $uid, string $dir, array &$meta): void {
        $tdb = 'tdb';
        $cmd = escapeshellcmd($tdb) . ' backup ' . escapeshellarg($uid) . ' > /dev/null 2>&1';
        system($cmd);
        $default_path = getenv('SNAPPY_TDB_BACKUP_PATH');
        if (!$default_path) {
            $default_path = getenv('HOME') . '/tdb_backups';
        }
        $candidate = '';
        if (is_dir($default_path)) {
            $matches = glob($default_path . '/' . $uid . '.*');
            if ($matches) {
                $candidate = $matches[0];
            }
        }
        if (!$candidate || !is_file($candidate)) {
            throw new RuntimeException('could not locate database backup for uid ' . $uid);
        }
        $backup_file = $dir . '/backup.sql';
        copy($candidate, $backup_file);
        $meta['files'][] = 'backup.sql';
        $meta['file_checksums']['backup.sql'] = hash_file('sha256', $backup_file);
    }

    public function publish(string $uid): int {
        $dir = $this->snapshot_dir($uid);
        $meta = $this->read_meta($dir);
        if (!$meta) {
            throw new RuntimeException('missing metadata for ' . $uid);
        }
        foreach ($meta['file_checksums'] as $file => $hash) {
            $full = $dir . '/' . $file;
            if (!is_file($full)) {
                throw new RuntimeException('missing file ' . $file);
            }
            $actual = hash_file('sha256', $full);
            if ($actual !== $hash) {
                throw new RuntimeException('checksum mismatch for ' . $file);
            }
        }
        $keys = $this->storage->upload($dir, $uid);
        $this->cache->invalidate();
        return count($keys);
    }

    public function list_local(bool $full): array {
        $list = snapshot_uid::list_local($this->root_dir);
        $rows = [];
        foreach ($list as $uid) {
            $meta = $this->read_meta($this->snapshot_dir($uid));
            if (!$meta) {
                continue;
            }
            $msg = (string)($meta['message'] ?? '');
            if (!$full) {
                $parts = preg_split('/\r?\n/', $msg, 2);
                $msg = $parts[0] ?? '';
            } else {
                $msg = preg_replace('/\r?\n+/', ' | ', $msg);
            }
            $rows[] = [
                'uid' => $uid,
                'created' => $meta['created'] ?? '',
                'type' => $meta['type'] ?? '',
                'message' => $msg,
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['created'], $a['created']);
        });
        return $rows;
    }

    public function fetch_remote(string $prefix, int $limit): array {
        $objects = $this->storage->list_objects($prefix, $limit);
        return $this->cache->save_list_cache($prefix, $objects, $limit);
    }

    public function list_remote(string $prefix, int $limit, bool $full): array {
        $cache = $this->cache->load_list_cache($prefix);
        $fetched = false;
        if ($cache === null) {
            $cache = $this->fetch_remote($prefix, $limit);
            $fetched = true;
        }
        $objects = $cache['objects'] ?? [];
        $local = array_flip(snapshot_uid::list_local($this->root_dir));
        $snapshots = [];
        $meta_keys = [];
        foreach ($objects as $o) {
            $key = $o['key'];
            if (preg_match('#^snaps/([^/]+)/meta\\.json$#', $key, $m)) {
                $uid = $m[1];
                $snapshots[$uid] = [
                    'uid' => $uid,
                    'meta_last_modified' => $o['last_modified'],
                    'has_local' => isset($local[$uid]),
                    'meta' => null,
                ];
                $meta_keys[$uid] = $key;
            }
        }
        foreach ($snapshots as $uid => &$info) {
            $cached_meta = $this->cache->load_meta($uid);
            if ($cached_meta && ($cached_meta['meta_last_modified'] ?? '') === $info['meta_last_modified']) {
                $info['meta'] = $cached_meta['meta'];
                continue;
            }
            try {
                $json = $this->storage->read_object($meta_keys[$uid]);
                $meta_array = @json_decode($json, true);
                if (is_array($meta_array)) {
                    $this->cache->save_meta($uid, $info['meta_last_modified'], $meta_array);
                    $info['meta'] = $meta_array;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        unset($info);
        $rows = [];
        foreach ($snapshots as $uid => $info) {
            $meta = $info['meta'] ?? [];
            $message = (string)($meta['message'] ?? '');
            if (!$full) {
                $parts = preg_split('/\r?\n/', $message, 2);
                $message = $parts[0] ?? '';
            } else {
                $message = preg_replace('/\r?\n+/', ' | ', $message);
            }
            $rows[] = [
                'uid' => $uid,
                'created' => $meta['created'] ?? $info['meta_last_modified'],
                'type' => $meta['type'] ?? '',
                'message' => $message,
                'local' => $info['has_local'] ? 'yes' : '',
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['created'], $a['created']);
        });
        return [
            'fetched' => $fetched,
            'retrieved_at' => $cache['retrieved_at'] ?? null,
            'rows' => $rows,
        ];
    }

    public function read_meta(string $dir): ?array {
        $file = $dir . '/meta.json';
        if (!is_file($file)) {
            return null;
        }
        $data = @json_decode(@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function write_meta(string $dir, array $meta): void {
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));
    }
}
