<?php

namespace Snappy\Snapshot;

use Throwable;

/**
 * Maintains a local index (snaps/index.json) for O(1) listing of local snapshots.
 * Schema (v1): {
 *   version: 1,
 *   generated_utc: <iso8601>,
 *   snapshots: [
 *     { uid, created_utc, message_first, tags, size_total_bytes, compression:{enabled,algo}, files_count, type? }
 *   ]
 * }
 * Notes:
 * - "type" is an optional extension (not in original ticket schema) to avoid reading each manifest for list output.
 * - Rebuild scans existing snapshot directories (snaps/<uid>/manifest-v2.json or meta.json fallback).
 */
class index_manager {
    private string $basePath; // snapshot root (the parent containing /snaps)
    private ?SnapshotLoader $loader = null;

    public function __construct(string $basePath) {
        $this->basePath = rtrim($basePath, '/');
        // Normalize: callers may accidentally pass the /snaps directory itself (e.g. env SNAPPY_SNAPSHOT_BASE=.../snaps).
        // For index operations we require the parent root containing the /snaps directory.
        if (basename($this->basePath) === 'snaps') {
            $parent = dirname($this->basePath);
            if ($parent !== '' && $parent !== '/' && is_dir($parent)) {
                $this->basePath = $parent; // adjust to parent root
            }
        }
    }

    private function loader(): SnapshotLoader { return $this->loader ??= new SnapshotLoader(); }

    private function indexPath(): string { return $this->basePath . '/snaps/index.json'; }

    public function exists(): bool { return is_file($this->indexPath()); }

    public function load(bool $allowRebuildOnCorrupt = true): ?array {
        $file = $this->indexPath();
        if (!is_file($file)) { return null; }
        $json = @file_get_contents($file);
        $data = @json_decode((string)$json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !isset($data['snapshots']) || !is_array($data['snapshots'])) {
            if ($allowRebuildOnCorrupt) {
                try { $this->rebuild(); return $this->load(false); } catch (Throwable $e) { return null; }
            }
            return null;
        }
        return $data;
    }

    /** Add or update a single snapshot entry based on its manifest/meta. */
    public function addOrUpdate(string $uid): void {
        $index = $this->load(false) ?: [ 'version'=>1, 'generated_utc'=>gmdate('c'), 'snapshots'=>[] ];
        $map = [];
        foreach ($index['snapshots'] as $row) { $map[$row['uid']] = $row; }
        $entry = $this->buildEntry($uid);
        if (!$entry) { return; }
        $map[$uid] = $entry;
        $index['snapshots'] = array_values($map);
        $index['generated_utc'] = gmdate('c');
        $this->write($index);
    }

    public function remove(string $uid): void {
        $index = $this->load(false);
        if (!$index) { return; }
        $index['snapshots'] = array_values(array_filter($index['snapshots'], fn($r) => ($r['uid'] ?? '') !== $uid));
        $index['generated_utc'] = gmdate('c');
        $this->write($index);
    }

    /** Full rebuild scanning /snaps directory */
    public function rebuild(): void {
        $snapsDir = $this->basePath . '/snaps';
        if (!is_dir($snapsDir)) { return; }
        $entries = [];
        $items = @scandir($snapsDir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..' || $it === 'index.json') { continue; }
            $dir = $snapsDir . '/' . $it;
            if (!is_dir($dir)) { continue; }
            $uid = $it;
            $entry = $this->buildEntry($uid);
            if ($entry) { $entries[] = $entry; }
        }
        usort($entries, fn($a,$b)=>strcmp($b['created_utc'],$a['created_utc']));
        $index = [
            'version' => 1,
            'generated_utc' => gmdate('c'),
            'snapshots' => $entries,
        ];
        $this->write($index);
    }

    /**
     * Build index entry using manifest-v2.json (preferred) or meta.json fallback.
     */
    private function buildEntry(string $uid): ?array {
        try {
            $manifest = $this->loader()->load_local($uid, $this->basePath);
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
                'compression' => [
                    'enabled' => (bool)($compression['enabled'] ?? false),
                ],
                'files_count' => $filesCount,
            ];
            if (!empty($compression['enabled']) && isset($compression['algo'])) { $entry['compression']['algo'] = $compression['algo']; }
            // optional extension
            if (isset($manifest['snapshot_type'])) { $entry['type'] = $manifest['snapshot_type']; }
            elseif (isset($manifest['type'])) { $entry['type'] = $manifest['type']; }
            return $entry;
        } catch (Throwable $e) {
            return null; // ignore failures
        }
    }

    private function write(array $index): void {
        $path = $this->indexPath();
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $tmp = sys_get_temp_dir() . '/snappy_index_' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode($index, JSON_PRETTY_PRINT);
        if ($json === false) { return; }
        @file_put_contents($tmp, $json);
        @rename($tmp, $path);
    }
}
