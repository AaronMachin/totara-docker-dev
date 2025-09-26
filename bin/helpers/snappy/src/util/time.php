<?php

namespace Snappy\Util;

class time {
    public static function human_age(?string $iso): string {
        if (!$iso) {
            return 'unknown';
        }
        $t = strtotime($iso);
        if (!$t) {
            return 'unknown';
        }
        $diff = time() - $t;
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        return floor($diff / 86400) . 'd ago';
    }
}

