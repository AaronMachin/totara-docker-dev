<?php
namespace Snappy\Snapshot;

use Snappy\Util\snapshot_uid;
use RuntimeException;

class snapshot_manager {
    private remote_registry $registry;

    public function __construct(remote_registry $registry) {
        $this->registry = $registry;
    }

    public function registry(): remote_registry { return $this->registry; }

    public function create(string $type, string $message, string $remote = 'local'): string {
        if ($remote !== 'local') {
            throw new RuntimeException('Snapshots can only be created in local remote then pushed');
        }
        $uid = snapshot_uid::generate();
        $meta = [
            'uid' => $uid,
            'created' => date('c'),
            'type' => $type,
            'message' => $message,
            'files' => [],
            'file_checksums' => [],
        ];
        if ($type === 'sql') {
            $this->create_sql_backup($uid, $meta);
        } else {
            throw new RuntimeException('unknown snapshot type: ' . $type);
        }
        $this->write_meta('local', $uid, $meta);
        return $uid;
    }

    private function local_snapshot_dir(string $uid): string {
        $base = $this->registry->local_base_path();
        $dir = $base . '/snaps/' . $uid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private function create_sql_backup(string $uid, array &$meta): void {
        $dir = $this->local_snapshot_dir($uid);
        $tdb = 'tdb';
        $cmd = escapeshellcmd($tdb) . ' backup ' . escapeshellarg($uid) . ' > /dev/null 2>&1';
        system($cmd);
        $default_path = getenv('SNAPPY_TDB_BACKUP_PATH');
        if (!$default_path) { $default_path = getenv('HOME') . '/tdb_backups'; }
        $candidate = '';
        if (is_dir($default_path)) {
            $matches = glob($default_path . '/' . $uid . '.*');
            if ($matches) { $candidate = $matches[0]; }
        }
        if (!$candidate || !is_file($candidate)) {
            throw new RuntimeException('could not locate database backup for uid ' . $uid . ' in ' . $default_path . ' (backup may have failed)');
        }
        $backup_file = $dir . '/backup.sql';
        copy($candidate, $backup_file);
        $meta['files'][] = 'backup.sql';
        $meta['file_checksums']['backup.sql'] = hash_file('sha256', $backup_file);
    }

    public function list(string $remote, bool $full = false, int $limit = 100): array {
        $storage = $this->registry->storage($remote);
        $objects = $storage->list_objects('snaps/', $limit * 10); // overscan to filter meta
        $snapshots = [];
        foreach ($objects as $o) {
            $key = $o['key'];
            if (preg_match('#^snaps/([^/]+)/meta\.json$#', $key, $m)) {
                $uid = $m[1];
                $snapshots[$uid] = [ 'uid' => $uid, 'meta_key' => $key, 'last_modified' => $o['last_modified'] ];
            }
        }
        $rows = [];
        foreach ($snapshots as $uid => $info) {
            $meta = $this->read_meta($remote, $uid);
            if (!$meta) { continue; }
            $msg = (string)($meta['message'] ?? '');
            if (!$full) {
                $msg = preg_split('/\r?\n/', $msg, 2)[0] ?? '';
            } else {
                $msg = preg_replace('/\r?\n+/', ' | ', $msg);
            }
            $rows[] = [
                'uid' => $uid,
                'created' => $meta['created'] ?? $info['last_modified'],
                'type' => $meta['type'] ?? '',
                'message' => $msg,
            ];
        }
        usort($rows, function($a,$b){ return strcmp($b['created'],$a['created']); });
        if (count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
        return $rows;
    }

    public function resolve_uid(string $partial, string $remote = 'local'): string {
        $partial = trim($partial);
        if ($partial === '') { return ''; }
        $uids = array_map(fn($r) => $r['uid'], $this->list($remote, false, 1000));
        if (in_array($partial, $uids, true)) { return $partial; }
        $matches = [];
        foreach ($uids as $u) { if (str_starts_with($u, $partial)) { $matches[] = $u; } }
        return count($matches) === 1 ? $matches[0] : '';
    }

    public function push(string $uid, string $target_remote, string $source_remote = 'local'): int {
        if ($source_remote === $target_remote) { throw new RuntimeException('source and target remotes identical'); }
        $meta = $this->read_meta($source_remote, $uid);
        if (!$meta) { throw new RuntimeException('unknown snapshot ' . $uid); }
        $this->verify($source_remote, $uid, $meta);
        $target = $this->registry->storage($target_remote);
        $count = 0;
        foreach ($meta['files'] as $file) {
            $path = $this->local_snapshot_dir($uid) . '/' . $file;
            if (!is_file($path)) { throw new RuntimeException('missing file ' . $file); }
            $target->put_object('snaps/' . $uid . '/' . $file, $path);
            $count++;
        }
        $tmp_meta = sys_get_temp_dir() . '/snappy_meta_' . $uid . '.json';
        file_put_contents($tmp_meta, json_encode($meta, JSON_PRETTY_PRINT));
        $target->put_object('snaps/' . $uid . '/meta.json', $tmp_meta);
        @unlink($tmp_meta);
        $count++;
        return $count;
    }

    public function pull(string $token, string $source_remote, bool $force = false): string {
        if ($source_remote === 'local') { throw new RuntimeException('source remote cannot be local'); }
        if (!$this->registry->has($source_remote)) { throw new RuntimeException('unknown remote ' . $source_remote); }
        $uid = $this->resolve_uid($token, $source_remote);
        if ($uid === '') { throw new RuntimeException('no or ambiguous match for ' . $token); }
        if (!$force && $this->read_meta('local', $uid)) { throw new RuntimeException('snapshot already exists locally: ' . $uid); }
        $storage = $this->registry->storage($source_remote);
        $prefix = 'snaps/' . $uid . '/';
        $objects = $storage->list_objects($prefix, 2000);
        if (!$objects) { throw new RuntimeException('remote snapshot objects missing for ' . $uid); }
        $file_keys = [];
        $meta_json = '';
        foreach ($objects as $o) {
            $key = $o['key'];
            if ($key === $prefix . 'meta.json') { $meta_json = $storage->read_object($key); continue; }
            if (preg_match('#^' . preg_quote($prefix, '#') . '(.+)$#', $key, $m)) {
                $rel = $m[1];
                if ($rel !== '' && !str_ends_with($rel, '/')) { $file_keys[] = $rel; }
            }
        }
        if ($meta_json === '') { $meta_json = $storage->read_object($prefix . 'meta.json'); }
        $meta = @json_decode($meta_json, true);
        if (!is_array($meta)) { throw new RuntimeException('invalid meta.json in remote snapshot'); }
        $local_dir = $this->local_snapshot_dir($uid);
        foreach ($file_keys as $rel) {
            $dest = $local_dir . '/' . $rel;
            $dir = dirname($dest);
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $storage->get_object($prefix . $rel, $dest);
        }
        foreach (($meta['file_checksums'] ?? []) as $file => $hash) {
            $full = $local_dir . '/' . $file;
            if (!is_file($full)) { throw new RuntimeException('downloaded snapshot missing file ' . $file); }
            $actual = hash_file('sha256', $full);
            if ($actual !== $hash) { throw new RuntimeException('checksum mismatch after pull for ' . $file); }
        }
        $this->write_meta('local', $uid, $meta);
        return $uid;
    }

    private function verify(string $remote, string $uid, array $meta): void {
        if ($remote !== 'local') { return; } // for now only verify local
        $dir = $this->local_snapshot_dir($uid);
        foreach (($meta['file_checksums'] ?? []) as $file => $hash) {
            $full = $dir . '/' . $file;
            if (!is_file($full)) { throw new RuntimeException('missing file ' . $file); }
            $actual = hash_file('sha256', $full);
            if ($actual !== $hash) { throw new RuntimeException('checksum mismatch for ' . $file); }
        }
    }

    public function read_meta(string $remote, string $uid): ?array {
        if ($remote === 'local') {
            $file = $this->local_snapshot_dir($uid) . '/meta.json';
            if (!is_file($file)) { return null; }
            $data = @json_decode(@file_get_contents($file), true);
            return is_array($data) ? $data : null;
        }
        $storage = $this->registry->storage($remote);
        try {
            $json = $storage->read_object('snaps/' . $uid . '/meta.json');
            $data = @json_decode($json, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function write_meta(string $remote, string $uid, array $meta): void {
        if ($remote !== 'local') { throw new RuntimeException('write_meta only allowed for local'); }
        $file = $this->local_snapshot_dir($uid) . '/meta.json';
        file_put_contents($file, json_encode($meta, JSON_PRETTY_PRINT));
    }
}
