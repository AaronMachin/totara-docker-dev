<?php

namespace Snappy\Snapshot;

use Snappy\Util\snapshot_uid;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnapshotNotFoundException;
use Snappy\Support\Exception\RemoteException;
use Snappy\Support\Exception\ProcessFailedException;
use Throwable;
use Snappy\Support\Process\process_runner; // updated
use Snappy\Util\env;

class snapshot_manager {
    private remote_registry $registry;
    private ?remote_snapshot_cache $cache = null;
    private ?SnapshotLoader $loader = null;

    public function __construct(remote_registry $registry) {
        $this->registry = $registry;
    }

    public function set_cache(remote_snapshot_cache $cache): void { $this->cache = $cache; }
    public function cache(): ?remote_snapshot_cache { return $this->cache; }
    public function registry(): remote_registry {
        return $this->registry;
    }

    public function create(string $type, string $message, string $remote = 'local'): string {
        if ($remote !== 'local') { throw new ValidationException('Snapshots can only be created in local remote then pushed'); }
        $uid = snapshot_uid::generate();
        $meta = [
            'uid' => $uid,
            'created' => date('c'),
            'type' => $type,
            'message' => $message,
            'files' => [],
            'file_checksums' => [],
        ];
        if ($type === 'sql') { $this->create_sql_backup($uid, $meta); }
        else { throw new ValidationException('Unknown snapshot type: ' . $type); }
        // Build & write manifest-v2.json before legacy meta.json (dual write) per T2.2
        try {
            $manifest = $this->build_manifest_v2($uid, $type, $message, $meta);
            // integrity assertion (#files match)
            if (count($manifest['files']) !== count($meta['files'])) {
                throw new ValidationException('Manifest v2 file count mismatch');
            }
            $this->write_manifest_v2($uid, $manifest);
        } catch (Throwable $e) {
            // Fail fast prior to writing meta.json to avoid divergent states
            throw $e;
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
        $tdb = trim(env::get('SNAPPY_TDB_BIN', 'tdb')) ?: 'tdb';
        $command = [$tdb, 'backup', '--alias', $uid];
        $runner = new process_runner();
        $result = $runner->run($command);
        if ($result->exitCode !== 0) {
            $lines = preg_split('/\r?\n/', $result->stderr); $lines = $lines === false ? [] : $lines;
            $first = array_slice($lines, 0, 10);
            $truncatedMsg = implode("\n", $first);
            if (count($lines) > 10) { $truncatedMsg .= "\n... (stderr truncated)"; }
            $msg = 'Database backup process failed (exit code ' . $result->exitCode . ") for alias $uid";
            if ($truncatedMsg !== '') { $msg .= ":\n" . $truncatedMsg; }
            throw new ProcessFailedException($msg, $result);
        }
        // Use configured backup path with schema-applied default
        $config = $this->registry->config_manager()->all();
        $default_path = $config['options']['backup_path'] ?? '';
        $candidate = '';
        if ($default_path && is_dir($default_path)) {
            $matches = glob(rtrim($default_path, '/') . '/' . $uid . '.*');
            if ($matches) { $candidate = $matches[0]; }
        }
        if (!$candidate || !is_file($candidate)) {
            throw new ProcessFailedException('Could not locate database backup for uid ' . $uid . ' in ' . $default_path . ' (backup may have failed)');
        }
        $backup_file = $dir . '/backup.sql';
        copy($candidate, $backup_file);
        $meta['files'][] = 'backup.sql';
        $meta['file_checksums']['backup.sql'] = hash_file('sha256', $backup_file);
    }

    public function list(string $remote, bool $full = false, int $limit = 100, bool $bypassCache = false): array {
        if ($remote !== 'local' && $this->cache && !$bypassCache) {
            $c = $this->cache->load($remote);
            if ($c) {
                $rows = [];
                foreach ($c['snapshots'] as $uid => $row) {
                    $msg = (string) ($row['message'] ?? '');
                    if (!$full) { $msg = preg_split('/\r?\n/', $msg, 2)[0] ?? ''; } else { $msg = preg_replace('/\r?\n+/', ' | ', $msg); }
                    $rows[] = [
                        'uid' => $uid,
                        'created' => $row['created'] ?? '',
                        'type' => $row['type'] ?? '',
                        'message' => $msg,
                    ];
                }
                usort($rows, fn($a,$b) => strcmp($b['created'],$a['created']));
                if (count($rows)>$limit) { $rows = array_slice($rows,0,$limit); }
                return $rows;
            }
            // fall through to live listing if no cache
        }
        $storage = $this->registry->storage($remote);
        $objects = $storage->list_objects('snaps/', $limit * 10); // overscan to filter meta
        $snapshots = [];
        foreach ($objects as $o) {
            $key = $o['key'];
            if (preg_match('#^snaps/([^/]+)/meta\.json$#', $key, $m)) {
                $uid = $m[1];
                $snapshots[$uid] = ['uid' => $uid, 'meta_key' => $key, 'last_modified' => $o['last_modified']];
            }
        }
        $rows = [];
        foreach ($snapshots as $uid => $info) {
            $manifest = $this->read_manifest($remote, $uid); // normalized (may be partial remotely)
            if (!$manifest) { continue; }
            $msg = (string) ($manifest['message'] ?? '');
            if (!$full) { $msg = preg_split('/\r?\n/', $msg, 2)[0] ?? ''; } else { $msg = preg_replace('/\r?\n+/', ' | ', $msg); }
            $created = $manifest['created'] ?? ($manifest['created_utc'] ?? $info['last_modified']);
            $rows[] = [
                'uid' => $uid,
                'created' => $created,
                'type' => $manifest['type'] ?? '',
                'message' => $msg,
            ];
        }
        usort($rows, function ($a, $b) { return strcmp($b['created'], $a['created']); });
        if (count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
        return $rows;
    }

    public function list_multi(array $remotes, bool $full = false, int $limit = 100, bool $parallel = true): array {
        $aggregate = [];
        $tasks = [];
        $can_parallel = $parallel && function_exists('pcntl_fork') && function_exists('posix_getpid');
        if ($can_parallel) {
            // Spawn child per remote
            foreach ($remotes as $remote) {
                if (!is_string($remote) || $remote === '' || !$this->registry->has($remote)) {
                    continue;
                }
                $tasks[] = $remote;
            }
            $temp_dir = sys_get_temp_dir();
            $children = [];
            foreach ($tasks as $remote) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $can_parallel = false;
                    break;
                }
                if ($pid === 0) {
                    // Child
                    $result = $this->scan_remote_for_rows($remote, $full, $limit);
                    $file = $temp_dir . '/snappy_list_' . getmypid() . '.json';
                    @file_put_contents($file, json_encode($result));
                    exit(0);
                } else {
                    $children[$pid] = $remote;
                }
            }
            if ($can_parallel) {
                // Wait children
                foreach ($children as $pid => $_r) {
                    pcntl_waitpid($pid, $status);
                    $file = $temp_dir . '/snappy_list_' . $pid . '.json';
                    if (is_file($file)) {
                        $data = @json_decode(@file_get_contents($file), true);
                        @unlink($file);
                        if (is_array($data)) {
                            $this->merge_rows_into_aggregate($aggregate, $data);
                        }
                    }
                }
            }
        }
        if (!$can_parallel) {
            // Sequential fallback or initial strategy
            foreach ($remotes as $remote) {
                if (!is_string($remote) || $remote === '' || !$this->registry->has($remote)) {
                    continue;
                }
                $rows = $this->scan_remote_for_rows($remote, $full, $limit);
                $this->merge_rows_into_aggregate($aggregate, $rows);
            }
        }
        // Build rows
        $rows = [];
        foreach ($aggregate as $uid => $info) {
            $rows[] = [
                'uid' => $info['uid'],
                'created' => $info['created'],
                'type' => $info['type'],
                'message' => $info['message'],
                'locations' => implode(', ', array_values($info['locations'])),
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['created'], $a['created']);
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    private function scan_remote_for_rows(string $remote, bool $full, int $limit): array {
        $out = [];
        try { $storage = $this->registry->storage($remote); $objects = $storage->list_objects('snaps/', $limit * 20); } catch (Throwable $e) { return $out; }
        $metaKeys = [];
        foreach ($objects as $o) { $key = $o['key']; if (preg_match('#^snaps/([^/]+)/meta\.json$#', $key, $m)) { $metaKeys[$m[1]] = $key; } }
        foreach ($metaKeys as $uid => $_k) {
            $manifest = $this->read_manifest($remote, $uid);
            if (!$manifest) { continue; }
            $message = (string) ($manifest['message'] ?? '');
            if (!$full) { $message = preg_split('/\r?\n/', $message, 2)[0] ?? ''; } else { $message = preg_replace('/\r?\n+/', ' | ', $message); }
            $created = $manifest['created'] ?? ($manifest['created_utc'] ?? '');
            $out[] = [
                'uid' => $uid,
                'created' => $created,
                'type' => $manifest['type'] ?? '',
                'message' => $message,
                'remote' => $remote,
            ];
        }
        return $out;
    }

    private function merge_rows_into_aggregate(array &$aggregate, array $rows): void {
        foreach ($rows as $row) {
            $uid = $row['uid'];
            $remote = $row['remote'];
            if (!isset($aggregate[$uid])) {
                $aggregate[$uid] = [
                    'uid' => $uid,
                    'created' => $row['created'],
                    'type' => $row['type'],
                    'message' => $row['message'],
                    'locations' => [$remote],
                    '_preferred_source' => $remote === 'local' ? 'local' : $remote,
                ];
            } else {
                if (!in_array($remote, $aggregate[$uid]['locations'], true)) {
                    $aggregate[$uid]['locations'][] = $remote;
                }
                if ($aggregate[$uid]['_preferred_source'] !== 'local' && $remote === 'local') {
                    $aggregate[$uid]['created'] = $row['created'] ?: $aggregate[$uid]['created'];
                    $aggregate[$uid]['type'] = $row['type'] ?: $aggregate[$uid]['type'];
                    $aggregate[$uid]['message'] = $row['message'];
                    $aggregate[$uid]['_preferred_source'] = 'local';
                }
            }
        }
    }

    public function resolve_uid(string $partial, string $remote = 'local'): string {
        $partial = trim($partial);
        if ($partial === '') { return ''; }
        if ($remote !== 'local' && $this->cache) {
            $c = $this->cache->load($remote);
            if ($c) {
                $uids = array_keys($c['snapshots']);
                if (in_array($partial, $uids, true)) { return $partial; }
                $matches = [];
                foreach ($uids as $u) { if (str_starts_with($u, $partial)) { $matches[] = $u; } }
                return count($matches) === 1 ? $matches[0] : '';
            }
        }
        $uids = array_map(fn($r) => $r['uid'], $this->list($remote, false, 1000));
        if (in_array($partial, $uids, true)) {
            return $partial;
        }
        $matches = [];
        foreach ($uids as $u) {
            if (str_starts_with($u, $partial)) {
                $matches[] = $u;
            }
        }
        return count($matches) === 1 ? $matches[0] : '';
    }

    public function push(string $uid, string $target_remote, string $source_remote = 'local'): int {
        if ($source_remote === $target_remote) { throw new ValidationException('Source and target remotes identical'); }
        $meta = $this->read_meta($source_remote, $uid);
        if (!$meta) { throw new SnapshotNotFoundException('Unknown snapshot ' . $uid); }
        $this->verify($source_remote, $uid, $meta);
        $target = $this->registry->storage($target_remote);
        // Ensure bucket exists if S3 storage
        if (method_exists($target, 'ensure_bucket')) {
            try { $target->ensure_bucket(); } catch (Throwable $e) { /* ignore bucket create race */ }
        }
        $count = 0;
        foreach ($meta['files'] as $file) {
            $path = $this->local_snapshot_dir($uid) . '/' . $file;
            if (!is_file($path)) { throw new RemoteException('Missing file ' . $file); }
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
        if ($source_remote === 'local') { throw new ValidationException('Source remote cannot be local'); }
        if (!$this->registry->has($source_remote)) { throw new RemoteException('Unknown remote ' . $source_remote); }
        $uid = $this->resolve_uid($token, $source_remote);
        if ($uid === '') { throw new SnapshotNotFoundException('No or ambiguous match for ' . $token); }
        if (!$force && $this->read_meta('local', $uid)) { throw new ValidationException('Snapshot already exists locally: ' . $uid); }
        $storage = $this->registry->storage($source_remote);
        $prefix = 'snaps/' . $uid . '/';
        $objects = $storage->list_objects($prefix, 2000);
        if (!$objects) { throw new SnapshotNotFoundException('Remote snapshot objects missing for ' . $uid); }
        $file_keys = [];
        $meta_json = '';
        foreach ($objects as $o) {
            $key = $o['key'];
            if ($key === $prefix . 'meta.json') {
                $meta_json = $storage->read_object($key);
                continue;
            }
            if (preg_match('#^' . preg_quote($prefix, '#') . '(.+)$#', $key, $m)) {
                $rel = $m[1];
                if ($rel !== '' && !str_ends_with($rel, '/')) {
                    $file_keys[] = $rel;
                }
            }
        }
        if ($meta_json === '') {
            $meta_json = $storage->read_object($prefix . 'meta.json');
        }
        $meta = @json_decode($meta_json, true);
        if (!is_array($meta)) { throw new RemoteException('Invalid meta.json in remote snapshot'); }
        $local_dir = $this->local_snapshot_dir($uid);
        foreach ($file_keys as $rel) {
            $dest = $local_dir . '/' . $rel;
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $storage->get_object($prefix . $rel, $dest);
        }
        foreach (($meta['file_checksums'] ?? []) as $file => $hash) {
            $full = $local_dir . '/' . $file;
            if (!is_file($full)) { throw new RemoteException('Downloaded snapshot missing file ' . $file); }
            $actual = hash_file('sha256', $full);
            if ($actual !== $hash) { throw new ValidationException('Checksum mismatch after pull for ' . $file); }
        }
        $this->write_meta('local', $uid, $meta);
        return $uid;
    }

    private function verify(string $remote, string $uid, array $meta): void {
        if ($remote !== 'local') { return; }
        // for now only verify local
        $dir = $this->local_snapshot_dir($uid);
        foreach (($meta['file_checksums'] ?? []) as $file => $hash) {
            $full = $dir . '/' . $file;
            if (!is_file($full)) { throw new RemoteException('Missing file ' . $file); }
            $actual = hash_file('sha256', $full);
            if ($actual !== $hash) { throw new ValidationException('Checksum mismatch for ' . $file); }
        }
    }

    public function read_meta(string $remote, string $uid): ?array {
        if ($remote === 'local') {
            $file = $this->local_snapshot_dir($uid) . '/meta.json';
            if (!is_file($file)) {
                return null;
            }
            $data = @json_decode(@file_get_contents($file), true);
            return is_array($data) ? $data : null;
        }
        $storage = $this->registry->storage($remote);
        try {
            $json = $storage->read_object('snaps/' . $uid . '/meta.json');
            $data = @json_decode($json, true);
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function write_meta(string $remote, string $uid, array $meta): void {
        if ($remote !== 'local') { throw new ValidationException('write_meta only allowed for local'); }
        $file = $this->local_snapshot_dir($uid) . '/meta.json';
        file_put_contents($file, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function build_manifest_v2(string $uid, string $type, string $message, array $meta): array {
        $dir = $this->local_snapshot_dir($uid);
        $files = [];
        $total = 0;
        foreach ($meta['files'] as $name) {
            $path = $dir . '/' . $name;
            $size = is_file($path) ? filesize($path) : 0;
            $files[] = [
                'name' => $name,
                'size_bytes' => $size,
                'compressed' => false,
            ];
            $total += $size;
        }
        $checksums = [
            'algo' => 'sha256',
            'files' => [],
        ];
        foreach (($meta['file_checksums'] ?? []) as $file => $hash) {
            $checksums['files'][$file] = $hash;
        }
        $argv = $GLOBALS['argv'] ?? [];
        $command_line = '';
        if ($argv && is_array($argv)) {
            // best-effort reproduction; avoid quoting explosion
            $parts = [];
            foreach ($argv as $a) { $parts[] = strpos($a, ' ') !== false ? escapeshellarg($a) : $a; }
            $command_line = implode(' ', $parts);
        }
        $provenance = [
            'command_line' => $command_line,
            'host' => (string) (gethostname() ?: php_uname('n')),
            'user' => (string) (get_current_user() ?: ''),
            'php_version' => PHP_VERSION,
        ];
        return [
            'schema_version' => 2,
            'uid' => $uid,
            'created_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'snapshot_type' => $type,
            'message' => $message,
            // 'tags' => [], // optional; omitted for now
            'files' => $files,
            'checksums' => $checksums,
            'size_total_bytes' => $total,
            'compression' => [ 'enabled' => false ],
            'provenance' => $provenance,
        ];
    }

    private function write_manifest_v2(string $uid, array $manifest): void {
        $dir = $this->local_snapshot_dir($uid);
        $target = $dir . '/manifest-v2.json';
        $tmp = sys_get_temp_dir() . '/snappy_manifest_v2_' . $uid . '_' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT);
        if ($json === false) { throw new ValidationException('Failed to encode manifest-v2 JSON'); }
        file_put_contents($tmp, $json);
        // atomic rename
        @rename($tmp, $target);
        if (!is_file($target)) { throw new ValidationException('Failed to write manifest-v2.json'); }
    }

    public function local_path(string $uid): string { return $this->registry->local_base_path() . '/snaps/' . $uid; }

    /**
     * Read normalized manifest (prefers manifest-v2 locally, falls back to meta.json). Remote currently meta.json only.
     */
    public function read_manifest(string $remote, string $uid): ?array {
        if ($remote === 'local') {
            return $this->loader()->load_local($uid, $this->registry->local_base_path());
        }
        // Remote: read legacy meta.json then map minimal normalized fields needed for listing
        $storage = $this->registry->storage($remote);
        try { $json = $storage->read_object('snaps/' . $uid . '/meta.json'); } catch (\Throwable $e) { return null; }
        $data = @json_decode($json, true); if (!is_array($data)) { return null; }
        $created = $data['created'] ?? '';
        return [
            'uid' => $data['uid'] ?? $uid,
            'created_utc' => $created,
            'created' => $created,
            'type' => $data['type'] ?? '',
            'message' => $data['message'] ?? '',
            'raw_version' => 1,
        ];
    }
    private function loader(): SnapshotLoader { return $this->loader ??= new SnapshotLoader(); }
}
