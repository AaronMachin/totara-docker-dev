<?php

namespace Snappy\Hosting;

use RuntimeException;

class remote_codec {
    public static function encode(array $data): string {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('encode json failed');
        }
        $b64 = base64_encode($json);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }

    public static function decode(string $encoded): array {
        if ($encoded === '') {
            throw new RuntimeException('empty encoded string');
        }
        $pad = strlen($encoded) % 4;
        if ($pad > 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $b64 = strtr($encoded, '-_', '+/');
        $json = base64_decode($b64, true);
        if ($json === false) {
            throw new RuntimeException('base64 decode failed');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('json decode failed');
        }
        return $data;
    }
}

