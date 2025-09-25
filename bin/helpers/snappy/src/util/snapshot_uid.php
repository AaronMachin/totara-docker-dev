<?php
namespace Snappy\Util;

/**
 * UID / snapshot identifier helpers
 */
class snapshot_uid {
    public static function generate(): string {
        $random = bin2hex(random_bytes(5));
        $suffix = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        return $random . $suffix;
    }

    public static function list_local(string $root_dir): array {
        $uids = [];
        if (!is_dir($root_dir)) {
            return $uids;
        }
        $shards = @scandir($root_dir);
        if (!$shards) {
            return $uids;
        }
        foreach ($shards as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $shard_path = $root_dir . '/' . $shard;
            if (!is_dir($shard_path)) {
                continue;
            }
            $entries = @scandir($shard_path);
            if (!$entries) {
                continue;
            }
            foreach ($entries as $uid) {
                if ($uid === '.' || $uid === '..') {
                    continue;
                }
                if (is_dir($shard_path . '/' . $uid) && is_file($shard_path . '/' . $uid . '/meta.json')) {
                    $uids[] = $uid;
                }
            }
        }
        return $uids;
    }

    public static function find_by_prefix(string $root_dir, string $partial): string {
        $partial = trim($partial);
        if ($partial === '') {
            return '';
        }
        $uids = self::list_local($root_dir);
        $exact = array_search($partial, $uids, true);
        if ($exact !== false) {
            return $partial;
        }
        $matches = [];
        foreach ($uids as $uid) {
            if (strpos($uid, $partial) === 0) {
                $matches[] = $uid;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        return '';
    }
}

