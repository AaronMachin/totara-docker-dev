<?php

namespace Snappy\Util;

/**
 * Deterministic JSON canonicalizer:
 *  - Recursively sort object keys lexicographically (byte-order)
 *  - Preserve array order
 *  - Encode without pretty print, no escaping slashes/unicode
 *  - No trailing newline added
 */
class canonical_json
{
    /**
     * Return canonical JSON string for provided decoded value (array/object graph).
     * @param mixed $value
     */
    public static function encode(mixed $value): string
    {
        $norm = self::normalize($value);
        return json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            // Distinguish list vs assoc
            if (self::is_list($value)) {
                $out = [];
                foreach ($value as $v) { $out[] = self::normalize($v); }
                return $out;
            }
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $out = [];
            foreach ($keys as $k) { $out[$k] = self::normalize($value[$k]); }
            return $out;
        }
        return $value; // scalars
    }

    private static function is_list(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $_) { if ($k !== $i++) { return false; } }
        return true;
    }
}

