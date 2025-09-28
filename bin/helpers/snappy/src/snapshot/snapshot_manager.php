<?php

namespace Snappy\Snapshot;

use Snappy\Util\snapshot_uid;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnapshotNotFoundException;
use Snappy\Support\Exception\RemoteException;
use Snappy\Support\Exception\ProcessFailedException;
use Snappy\Support\Process\process_runner;
use Throwable;

class snapshot_manager {
    private remote_registry $registry;
    private ?remote_snapshot_cache $cache = null;
    private ?SnapshotLoader $loader = null;
    private ?DumpProviderResolver $dumpResolver = null; // new
    private ?index_manager $indexManager = null; // local index manager (optional)
    private ?remote_index_manager $remoteIndexManager = null; // remote summary index manager

    public function __construct(remote_registry $registry) {
        $this->registry = $registry;
    }

    public function set_cache(remote_snapshot_cache $cache): void { $this->cache = $cache; }
    public function cache(): ?remote_snapshot_cache { return $this->cache; }
    public function registry(): remote_registry {
        return $this->registry;
    }
    public function set_index(index_manager $index): void { $this->indexManager = $index; }
    public function set_remote_index(remote_index_manager $rim): void { $this->remoteIndexManager = $rim; }

    public function create(string $type, string $message, string $remote = 'local', bool $compress = false, bool $keepFailed = false): string {
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
        $started = microtime(true);
        $tempDir = $this->temp_snapshot_dir($uid);
        $finalDir = rtrim($this->registry->local_base_path(), '/') . '/snaps/' . $uid; // avoid creating early
        @mkdir(dirname($finalDir) . '/', 0777, true);
        try {
            if ($type === 'sql') { $this->create_sql_backup($uid, $meta, $tempDir); }
            else { throw new ValidationException('Unknown snapshot type: ' . $type); }
            $compressionInfo = null;
            if ($compress) {
                try { $compressionInfo = $this->apply_compression($uid, $meta, $tempDir); }
                catch (Throwable $e) { throw new ProcessFailedException('Compression failed: '.$e->getMessage()); }
            }
            // Promote temp -> final (atomic if same filesystem)
            if (!@rename($tempDir, $finalDir)) {
                throw new ProcessFailedException('Failed to promote snapshot directory');
            }
            // Build & write manifest-v2.json before legacy meta.json
            try {
                $manifest = $this->build_manifest_v2($uid, $type, $message, $meta, $compressionInfo);
                if (count($manifest['files']) !== count($meta['files'])) { throw new ValidationException('Manifest v2 file count mismatch'); }
                $this->write_manifest_v2($uid, $manifest);
            } catch (Throwable $e) { throw $e; }
            $this->write_meta('local', $uid, $meta);
            // Update local index (best effort)
            try { $this->indexManager?->addOrUpdate($uid); } catch (Throwable $e) { /* ignore index failures */ }
            return $uid;
        } catch (Throwable $e) {
            // Write failure log (best-effort)
            $this->write_dump_failure_log($tempDir, $uid, $e, $started, microtime(true));
            if (!$keepFailed) { $this->recursive_delete($tempDir); }
            throw $e; // propagate
        }
    }

    private function temp_snapshot_dir(string $uid): string {
        $base = rtrim($this->registry->local_base_path(), '/');
        $dir = $base . '/tmp/' . $uid;
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir;
    }

    private function local_snapshot_dir(string $uid): string {
        $base = $this->registry->local_base_path();
        $dir = $base . '/snaps/' . $uid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private function create_sql_backup(string $uid, array &$meta, ?string $workDir = null): void {
        $dir = $workDir ?: $this->local_snapshot_dir($uid);
        $context = ['type' => 'sql'];
        $provider = $this->dump_resolver()->resolve($context);
        $result = $provider->dump($uid, $dir, ['registry' => $this->registry]);
        $files = $result->files();
        if (!$files) { throw new ProcessFailedException('Dump provider produced no files'); }
        foreach ($files as $file) {
            $name = $file['name'];
            $src = $file['path'];
            if (!is_file($src)) { throw new ProcessFailedException('Dump provider missing file path ' . $src); }
            $dest = $dir . '/' . $name;
            if ($src !== $dest) { // normal case: provider wrote elsewhere -> copy into snapshot dir
                if (!@copy($src, $dest)) { throw new ProcessFailedException('Failed to copy dump file to snapshot directory'); }
            } else {
                // provider already wrote the file directly into the snapshot work directory (e.g. FakeDumpProvider);
                // treat as success without copy.
            }
            $meta['files'][] = $name;
            $meta['file_checksums'][$name] = hash_file('sha256', $dest);
        }
        // Optionally store metadata from provider (non-breaking addition)
        $dumpMeta = $result->metadata();
        if ($dumpMeta) { $meta['dump_metadata'] = $dumpMeta; }
    }

    private function apply_compression(string $uid, array &$meta, ?string $workDir = null): ?array {
        $dir = $workDir ?: $this->local_snapshot_dir($uid);
        $original = $dir . '/backup.sql';
        if (!is_file($original)) { return null; } // nothing to compress (unexpected but ignore)
        $originalSize = filesize($original) ?: 0;
        $gzPath = $original . '.gz';
        // Prefer streaming with zlib extension
        $success = false;
        if (function_exists('gzopen')) {
            $in = @fopen($original, 'rb');
            $out = @gzopen($gzPath, 'wb6');
            if ($in && $out) {
                while (!feof($in)) { $chunk = fread($in, 8192); if ($chunk === false) { break; } gzwrite($out, $chunk); }
                fclose($in); gzclose($out);
                $success = is_file($gzPath) && filesize($gzPath) >= 0;
            }
        }
        if (!$success) {
            // Fallback to external gzip if available
            $runner = new process_runner();
            $gzipBin = trim((string)@shell_exec('command -v gzip 2>/dev/null')) ?: 'gzip';
            $test = @shell_exec($gzipBin . ' --version 2>/dev/null');
            if ($test !== null && $test !== '') {
                $cmd = [$gzipBin, '-c', $original];
                $result = $runner->run($cmd);
                if ($result->exitCode === 0) { file_put_contents($gzPath, $result->stdout); $success = true; }
            }
        }
        if (!$success || !is_file($gzPath)) { throw new ValidationException('Compression requested but no gzip capability available'); }
        $compressedSize = filesize($gzPath) ?: 0;
        $ratio = ($originalSize > 0) ? ($compressedSize / $originalSize) : 0.0; // compressed/original per schema
        $checksum = hash_file('sha256', $gzPath);
        // Update meta: replace backup.sql entry with backup.sql.gz
        $newFiles = [];
        foreach ($meta['files'] as $f) { $newFiles[] = ($f === 'backup.sql') ? 'backup.sql.gz' : $f; }
        $meta['files'] = $newFiles;
        // Adjust checksums
        $newChecksums = [];
        foreach ($meta['file_checksums'] as $f => $h) { if ($f === 'backup.sql') { continue; } $newChecksums[$f] = $h; }
        $newChecksums['backup.sql.gz'] = $checksum;
        $meta['file_checksums'] = $newChecksums;
        // Remove original only after success
        @unlink($original);
        return [
            'algo' => 'gzip',
            'original_size_bytes' => $originalSize,
            'compressed_size_bytes' => $compressedSize,
            'ratio' => $ratio,
        ];
    }

    public function list(string $remote, bool $full = false, int $limit = 100, bool $bypassCache = false): array {
        // Local index fast-path (when remote local and not bypass flag and not requesting full message)
        if ($remote === 'local' && !$bypassCache && !$full && $this->indexManager) {
            $idx = $this->indexManager->load();
            if ($idx && isset($idx['snapshots']) && is_array($idx['snapshots'])) {
                $rows = [];
                foreach ($idx['snapshots'] as $row) {
                    $rows[] = [
                        'uid' => $row['uid'],
                        'created' => $row['created_utc'] ?? '',
                        'type' => $row['type'] ?? '',
                        'message' => $row['message_first'] ?? '',
                    ];
                }
                usort($rows, fn($a,$b)=>strcmp($b['created'],$a['created']));
                if (count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
                return $rows;
            }
        }
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
        // Remote index fast-path (when remote not local, not requesting full message, and not using cache)
        if ($remote !== 'local' && !$full && $this->remoteIndexManager) {
            $ridx = $this->remoteIndexManager->load_for_remote($remote);
            if ($ridx && isset($ridx['snapshots']) && is_array($ridx['snapshots'])) {
                $rows = [];
                foreach ($ridx['snapshots'] as $row) {
                    $rows[] = [
                        'uid' => $row['uid'],
                        'created' => $row['created_utc'] ?? '',
                        'type' => $row['type'] ?? '',
                        'message' => $row['message_first'] ?? '',
                    ];
                }
                usort($rows, fn($a,$b)=>strcmp($b['created'],$a['created']));
                if (count($rows) > $limit) { $rows = array_slice($rows, 0, $limit); }
                return $rows;
            }
        }
        $storage = $this->registry->storage($remote);
        $objects = $storage->list_objects('snaps/', $limit * 10); // overscan to filter meta
        $snapshots = [];
        foreach ($objects as $o) {
            $key = $o['key'];
            if (preg_match('#^snaps/([^/]+)/meta\\.json$#', $key, $m)) {
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

    public function verify_local(string $uid): void {
        $meta = $this->read_meta('local', $uid);
        if (!$meta) { throw new SnapshotNotFoundException('Unknown snapshot ' . $uid); }
        $this->verify('local', $uid, $meta);
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
        // Update remote index (best-effort)
        try { $this->remoteIndexManager?->addOrUpdate($target_remote, $uid); } catch (\Throwable $e) { /* ignore */ }
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

    private function build_manifest_v2(string $uid, string $type, string $message, array $meta, ?array $compression = null): array {
        $dir = $this->local_snapshot_dir($uid);
        $files = [];
        $total = 0;
        foreach ($meta['files'] as $name) {
            $path = $dir . '/' . $name;
            $size = is_file($path) ? filesize($path) : 0;
            $isCompressed = str_ends_with($name, '.gz');
            $fileEntry = [
                'name' => $name,
                'size_bytes' => $size,
                'compressed' => $isCompressed,
            ];
            if ($isCompressed && $compression) { $fileEntry['compression_algo'] = $compression['algo']; }
            $files[] = $fileEntry;
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
        $compressionBlock = ['enabled' => false];
        if ($compression) {
            $compressionBlock = [
                'enabled' => true,
                'algo' => $compression['algo'],
                'original_size_bytes' => $compression['original_size_bytes'],
                'compressed_size_bytes' => $compression['compressed_size_bytes'],
                'ratio' => $compression['ratio'],
            ];
        }
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
            'compression' => $compressionBlock,
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
    private function dump_resolver(): DumpProviderResolver { return $this->dumpResolver ??= new DumpProviderResolver(); }

    private function write_dump_failure_log(string $tempDir, string $uid, \Throwable $e, float $started, float $finished): void {
        if (!is_dir($tempDir)) { return; }
        $logsDir = $tempDir . '/logs';
        if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
        $file = $logsDir . '/dump.log';
        $lines = [];
        $lines[] = 'SNAPPY DUMP FAILURE';
        $lines[] = 'uid: ' . $uid;
        $lines[] = 'started_at: ' . date('c', (int)$started);
        $lines[] = 'finished_at: ' . date('c', (int)$finished);
        $cmdStr = '';
        $exitCode = '';
        $stdout = '';
        $stderr = '';
        $stdoutTr = false; $stderrTr = false;
        if ($e instanceof \Snappy\Support\Exception\ProcessFailedException) {
            $res = $e->result();
            $cmd = $e->command();
            if ($cmd) {
                $parts = [];
                foreach ($cmd as $c) { $parts[] = preg_match('/\s/', $c) ? escapeshellarg($c) : $c; }
                $cmdStr = implode(' ', $parts);
            }
            if ($res) {
                $exitCode = (string)$res->exitCode;
                $stdout = $res->stdout;
                $stderr = $res->stderr;
                $stdoutTr = $res->stdoutTruncated; $stderrTr = $res->stderrTruncated;
            }
        }
        $lines[] = 'command: ' . ($cmdStr ?: '(unknown)');
        $lines[] = 'exit_code: ' . ($exitCode === '' ? '(unknown)' : $exitCode);
        $lines[] = 'exception: ' . get_class($e) . ': ' . $e->getMessage();
        $lines[] = 'stdout_truncated: ' . ($stdoutTr ? 'yes' : 'no');
        $lines[] = 'stderr_truncated: ' . ($stderrTr ? 'yes' : 'no');
        $lines[] = '--- stdout ---';
        $lines[] = $stdout;
        $lines[] = '--- stderr ---';
        $lines[] = $stderr;
        $data = implode("\n", $lines) . "\n";
        @file_put_contents($file, $data);
    }

    private function recursive_delete(string $dir): void {
        if (!is_dir($dir)) { return; }
        $items = @scandir($dir);
        if (!$items) { return; }
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') { continue; }
            $path = $dir . '/' . $it;
            if (is_dir($path)) { $this->recursive_delete($path); }
            else { @unlink($path); }
        }
        @rmdir($dir);
    }
    public function add_tag(string $uid, string $tag): bool {
        $tag = trim($tag);
        if ($tag === '') { throw new ValidationException('tag required'); }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $tag)) { throw new ValidationException('invalid tag format'); }
        $path = $this->local_snapshot_dir($uid) . '/manifest-v2.json';
        if (!is_file($path)) { throw new SnapshotNotFoundException('Unknown snapshot ' . $uid); }
        $raw = @json_decode(@file_get_contents($path), true);
        if (!is_array($raw) || (int)($raw['schema_version'] ?? 0) !== 2) { throw new ValidationException('manifest-v2 invalid for tag update'); }
        $existing = [];
        if (isset($raw['tags']) && is_array($raw['tags'])) { foreach ($raw['tags'] as $t) { if (is_string($t)) { $existing[$t] = true; } } }
        $added = !isset($existing[$tag]);
        $existing[$tag] = true;
        $raw['tags'] = array_values(array_keys($existing));
        $this->write_manifest_v2($uid, $raw);
        try { $this->indexManager?->addOrUpdate($uid); } catch (\Throwable $e) { /* ignore */ }
        return $added;
    }
    public function remove_tag(string $uid, string $tag): bool {
        $tag = trim($tag);
        if ($tag === '') { throw new ValidationException('tag required'); }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $tag)) { throw new ValidationException('invalid tag format'); }
        $path = $this->local_snapshot_dir($uid) . '/manifest-v2.json';
        if (!is_file($path)) { throw new SnapshotNotFoundException('Unknown snapshot ' . $uid); }
        $raw = @json_decode(@file_get_contents($path), true);
        if (!is_array($raw) || (int)($raw['schema_version'] ?? 0) !== 2) { throw new ValidationException('manifest-v2 invalid for tag update'); }
        $existing = [];
        if (isset($raw['tags']) && is_array($raw['tags'])) { foreach ($raw['tags'] as $t) { if (is_string($t)) { $existing[$t] = true; } } }
        $removed = isset($existing[$tag]);
        if ($removed) { unset($existing[$tag]); }
        if ($existing) { $raw['tags'] = array_values(array_keys($existing)); }
        else { unset($raw['tags']); }
        $this->write_manifest_v2($uid, $raw);
        try { $this->indexManager?->addOrUpdate($uid); } catch (\Throwable $e) { /* ignore */ }
        return $removed;
    }
}
