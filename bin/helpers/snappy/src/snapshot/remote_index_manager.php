<?php

namespace Snappy\Snapshot;

use Throwable;

/**
 * Remote summary index manager (snaps/index.json) for fast remote listing.
 * - Maintained on push (add/update entry for pushed snapshot).
 * - Used during remote listing (live or when cache absent) when full message not requested.
 * - Fallback to scanning remote objects if index missing or corrupt.
 * Schema matches local index_manager (version 1).
 */
class remote_index_manager {
    private remote_registry $registry;
    private ?SnapshotLoader $loader = null;

    public function __construct(remote_registry $registry) { $this->registry = $registry; }
    private function loader(): SnapshotLoader { return $this->loader ??= new SnapshotLoader(); }

    /** Load remote index or return null if missing/corrupt (no rebuild attempt per T4.2 scope). */
    public function load_for_remote(string $remote): ?array {
        $storage = $this->registry->storage($remote);
        try { $json = $storage->read_object('snaps/index.json'); } catch (Throwable $e) { return null; }
        $data = @json_decode($json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !isset($data['snapshots']) || !is_array($data['snapshots'])) { return null; }
        return $data;
    }

    /** Add/update entry in remote index using local manifest/meta details. */
    public function addOrUpdate(string $remote, string $uid): void {
        $storage = $this->registry->storage($remote);
        $index = $this->load_for_remote($remote) ?: ['version'=>1,'generated_utc'=>gmdate('c'),'snapshots'=>[]];
        $map = [];
        foreach ($index['snapshots'] as $row) { if (isset($row['uid'])) { $map[$row['uid']] = $row; } }
        $entry = $this->buildEntryFromLocal($uid);
        if (!$entry) { return; }
        $map[$uid] = $entry;
        $index['snapshots'] = array_values($map);
        $index['generated_utc'] = gmdate('c');
        $this->write($storage, $index);
    }

    /** Build entry using local manifest (preferred) or meta fallback; returns null if not present locally. */
    private function buildEntryFromLocal(string $uid): ?array {
        try {
            $base = rtrim($this->registry->local_base_path(), '/');
            $manifest = $this->loader()->load_local($uid, $base);
            if (!$manifest) { return null; }
            $message = (string)($manifest['message'] ?? '');
            $created = $manifest['created_utc'] ?? ($manifest['created'] ?? '');
            $files = $manifest['files'] ?? [];
            $filesCount = is_array($files) ? count($files) : 0;
            $size = (int)($manifest['size_total_bytes'] ?? 0);
            $compression = $manifest['compression'] ?? ['enabled'=>false];
            $entry = [
                'uid' => $uid,
                'created_utc' => $created,
                'message_first' => ($message === '' ? '' : preg_split('/\r?\n/', $message, 2)[0]),
                'tags' => $manifest['tags'] ?? [],
                'size_total_bytes' => $size,
                'compression' => ['enabled' => (bool)($compression['enabled'] ?? false)],
                'files_count' => $filesCount,
            ];
            if (!empty($compression['enabled']) && isset($compression['algo'])) { $entry['compression']['algo'] = $compression['algo']; }
            if (isset($manifest['snapshot_type'])) { $entry['type'] = $manifest['snapshot_type']; }
            elseif (isset($manifest['type'])) { $entry['type'] = $manifest['type']; }
            return $entry;
        } catch (Throwable $e) { return null; }
    }

    private function write($storage, array $index): void {
        try {
            $tmp = sys_get_temp_dir() . '/snappy_remote_index_' . bin2hex(random_bytes(4)) . '.json';
            $json = json_encode($index, JSON_PRETTY_PRINT);
            if ($json === false) { return; }
            @file_put_contents($tmp, $json);
            // Write temp then final (S3 eventual consistency acceptable)
            try { $storage->put_object('snaps/index.json.tmp', $tmp); } catch (Throwable $e) { /* ignore */ }
            $storage->put_object('snaps/index.json', $tmp);
            @unlink($tmp);
        } catch (Throwable $e) { /* ignore */ }
    }
}

