<?php
namespace Snappy\Util;

use RuntimeException;

/**
 * Helper to encode/decode ephemeral remote definitions into compact URL-safe base64 strings.
 * Fields are intentionally short for smaller copy/paste tokens.
 */
class remote_codec {
    /**
     * Encode associative array (expects scalar values) into url-safe base64 without padding.
     */
    public static function encode(array $data): string {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('encode json failed');
        }
        $b64 = base64_encode($json);
        $u = rtrim(strtr($b64, '+/', '-_'), '=');
        return $u;
    }

    /**
     * Decode url-safe base64 encoded string back to associative array.
     */
    public static function decode(string $encoded): array {
        if ($encoded === '') {
            throw new RuntimeException('empty encoded string');
        }
        // restore padding
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

