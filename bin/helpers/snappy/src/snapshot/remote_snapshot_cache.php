<?php

namespace Snappy\Snapshot;

use RuntimeException;
use Throwable;

class remote_snapshot_cache {
    private string $cache_dir;

    public function __construct(string $base_path) {
        $this->cache_dir = rtrim($base_path, '/') . '/cache';
        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0777, true);
        }
    }

    private function file_for(string $remote): string {
        return $this->cache_dir . '/remote_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $remote) . '.json';
    }

    public function load(string $remote): ?array {
        $file = $this->file_for($remote);
        if (!is_file($file)) {
            return null;
        }
        $data = @json_decode(@file_get_contents($file), true);
        if (!is_array($data) || empty($data['snapshots']) || !isset($data['fetched_at'])) {
            return null;
        }
        return $data;
    }

    public function save(string $remote, array $snapshots): void {
        $payload = [
            'remote' => $remote,
            'fetched_at' => date('c'),
            'snapshots' => $snapshots,
            'version' => 1,
        ];
        $file = $this->file_for($remote);
        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
    }

    /**
     * Update cache for a remote using minimal remote calls.
     * Only downloads meta.json files whose last_modified has changed or are new.
     */
    public function update_remote(string $remote, remote_registry $registry, snapshot_manager $manager, int $overscan = 2000): array {
        if (!$registry->has($remote)) {
            throw new RuntimeException('unknown remote ' . $remote);
        }
        $storage = $registry->storage($remote);
        $existing = $this->load($remote);
        $cached = $existing['snapshots'] ?? [];
        $cached_by_uid = $cached; // keyed by uid already
        // Build map of uid => last_modified from remote list
        $objects = [];
        try {
            $objects = $storage->list_objects('snaps/', $overscan);
        } catch (Throwable $e) {
            throw new RuntimeException('list_objects failed: ' . $e->getMessage());
        }
        $metaEntries = [];
        foreach ($objects as $o) {
            $key = $o['key'];
            if (preg_match('#^snaps/([^/]+)/meta\.json$#', $key, $m)) {
                $uid = $m[1];
                $metaEntries[$uid] = [
                    'key' => $key,
                    'last_modified' => $o['last_modified'] ?? '',
                ];
            }
        }
        // Determine added/changed
        $to_fetch = [];
        foreach ($metaEntries as $uid => $info) {
            if (!isset($cached_by_uid[$uid])) {
                $to_fetch[] = $uid; // new
                continue;
            }
            $prev = $cached_by_uid[$uid]['last_modified'] ?? '';
            if ($prev !== $info['last_modified']) {
                $to_fetch[] = $uid; // modified
            }
        }
        $removed_uids = array_diff(array_keys($cached_by_uid), array_keys($metaEntries));
        // Fetch meta for changed/new
        foreach ($to_fetch as $uid) {
            $key = $metaEntries[$uid]['key'];
            try {
                $json = $storage->read_object($key);
                $meta = @json_decode($json, true);
                if (!is_array($meta)) {
                    continue;
                }
                $cached_by_uid[$uid] = [
                    'uid' => $uid,
                    'created' => $meta['created'] ?? '',
                    'type' => $meta['type'] ?? '',
                    'message' => $meta['message'] ?? '',
                    'last_modified' => $metaEntries[$uid]['last_modified'],
                ];
            } catch (Throwable $e) {
                // skip this uid on error
            }
        }
        // Remove deleted
        foreach ($removed_uids as $uid) {
            unset($cached_by_uid[$uid]);
        }
        // Sort by created desc
        usort($cached_by_uid, function ($a, $b) {
            return strcmp($b['created'], $a['created']);
        });
        // Re-key by uid after sort
        $final = [];
        foreach ($cached_by_uid as $row) {
            $final[$row['uid']] = $row;
        }
        $this->save($remote, $final);
        return [
            'fetched' => count($to_fetch),
            'removed' => count($removed_uids),
            'total' => count($final),
            'remote' => $remote,
        ];
    }

    public static function max_age_seconds(remote_registry $registry): int {
        $opt = $registry->get_option('cache_max_age_seconds');
        if ($opt !== null && (int)$opt > 0) {
            return (int)$opt;
        }
        $default = 900; // 15 minutes default stored in config
        $registry->set_option('cache_max_age_seconds', $default);
        return $default;
    }

    public static function age_seconds(string $iso): int {
        $ts = strtotime($iso);
        if (!$ts) return PHP_INT_MAX;
        return time() - $ts;
    }

    public static function is_stale(string $iso, remote_registry $registry): bool {
        $age = self::age_seconds($iso);
        return $age >= self::max_age_seconds($registry);
    }

    public static function human_age(string $iso): string {
        $ts = strtotime($iso);
        if (!$ts) return 'unknown';
        $diff = time() - $ts;
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return intval($diff/60) . 'm ago';
        if ($diff < 86400) return intval($diff/3600) . 'h ago';
        return intval($diff/86400) . 'd ago';
    }
}
