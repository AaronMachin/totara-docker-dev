<?php

namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;

/**
 * SnapshotLoader loads a snapshot manifest in a unified (normalized) structure.
 * Prefers manifest-v2.json; falls back to legacy meta.json.
 */
class SnapshotLoader {
    /**
     * Load snapshot by UID from a local base path.
     * Returns normalized array or null if not found.
     */
    public function load_local(string $uid, string $local_base_path): ?array {
        $dir = rtrim($local_base_path, '/') . '/snaps/' . $uid;
        if (!is_dir($dir)) { return null; }
        $manifestPath = $dir . '/manifest-v2.json';
        $metaPath = $dir . '/meta.json';
        $errors = [];
        if (is_file($manifestPath)) {
            $json = @file_get_contents($manifestPath);
            $data = @json_decode($json, true);
            if (is_array($data)) {
                try { return $this->normalize_v2($data); } catch (ValidationException $e) { $errors[] = $e->getMessage(); /* fall back */ }
            } else { $errors[] = 'manifest-v2.json not valid JSON'; }
        }
        // Fallback to legacy meta.json
        if (is_file($metaPath)) {
            $json = @file_get_contents($metaPath);
            $data = @json_decode($json, true);
            if (is_array($data)) { try { return $this->normalize_legacy($data, $dir); } catch (ValidationException $e) { $errors[] = $e->getMessage(); } }
            else { $errors[] = 'meta.json not valid JSON'; }
        }
        if ($errors) {
            // Emit first error to STDERR for operator visibility (best-effort)
            @fwrite(STDERR, "[snappy] SnapshotLoader errors for $uid: " . implode('; ', $errors) . "\n");
        }
        return null;
    }

    private function normalize_v2(array $m): array {
        $required = ['schema_version','uid','created_utc','snapshot_type','files','checksums'];
        foreach ($required as $k) { if (!array_key_exists($k, $m)) { throw new ValidationException('manifest-v2 missing field ' . $k); } }
        if ((int)$m['schema_version'] !== 2) { throw new ValidationException('Unsupported schema_version ' . $m['schema_version']); }
        if (!is_array($m['files'])) { throw new ValidationException('files not array'); }
        $filesNorm = [];
        $total = 0;
        foreach ($m['files'] as $f) {
            if (!is_array($f) || !isset($f['name'])) { throw new ValidationException('Invalid file entry'); }
            $name = (string)$f['name'];
            $size = isset($f['size_bytes']) ? (int)$f['size_bytes'] : 0;
            $compressed = (bool)($f['compressed'] ?? false);
            $filesNorm[] = [ 'name' => $name, 'size_bytes' => $size, 'compressed' => $compressed ];
            $total += $size;
        }
        $checksums = [];
        if (is_array($m['checksums']['files'] ?? null)) { $checksums = $m['checksums']['files']; }
        $totalBytes = (int)($m['size_total_bytes'] ?? $total);
        // Provide legacy 'created' alias (RFC3339 with +00:00) for list output stability
        $createdUtc = (string)$m['created_utc'];
        $createdAlias = $createdUtc;
        if (str_ends_with($createdUtc, 'Z')) { $createdAlias = substr($createdUtc, 0, -1) . '+00:00'; }
        return [
            'uid' => (string)$m['uid'],
            'created_utc' => $createdUtc,
            'created' => $createdAlias,
            'type' => (string)$m['snapshot_type'],
            'message' => (string)($m['message'] ?? ''),
            'tags' => is_array($m['tags'] ?? null) ? array_values($m['tags']) : [],
            'files' => $filesNorm,
            'checksums' => $checksums,
            'size_total_bytes' => $totalBytes,
            'raw_version' => 2,
        ];
    }

    private function normalize_legacy(array $meta, string $dir): array {
        $required = ['uid','created','type','files'];
        foreach ($required as $k) { if (!array_key_exists($k,$meta)) { throw new ValidationException('meta.json missing field ' . $k); } }
        $files = [];
        $total = 0;
        foreach (($meta['files'] ?? []) as $name) {
            $path = $dir . '/' . $name;
            $size = is_file($path) ? filesize($path) : 0;
            $files[] = [ 'name' => $name, 'size_bytes' => $size, 'compressed' => str_ends_with($name, '.gz') ];
            $total += $size;
        }
        $checksums = is_array($meta['file_checksums'] ?? null) ? $meta['file_checksums'] : [];
        return [
            'uid' => (string)$meta['uid'],
            'created_utc' => (string)$meta['created'],
            'created' => (string)$meta['created'],
            'type' => (string)$meta['type'],
            'message' => (string)($meta['message'] ?? ''),
            'tags' => [],
            'files' => $files,
            'checksums' => $checksums,
            'size_total_bytes' => $total,
            'raw_version' => 1,
        ];
    }
}
