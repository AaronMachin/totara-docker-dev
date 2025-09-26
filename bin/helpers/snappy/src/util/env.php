<?php

namespace Snappy\Util;

class env {
    public static function get(string $name, string $default = ''): string {
        $val = getenv($name);
        if ($val === false || $val === '') {
            return $default;
        }
        return $val;
    }
}
