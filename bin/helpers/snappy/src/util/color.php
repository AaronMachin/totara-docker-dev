<?php

namespace Snappy\Util;

class color {
    private static ?bool $enabled = null;
    private static array $palette = [
        'local' => 32, // green
    ];
    private static array $available = [34, 36, 35, 33, 31]; // blue, cyan, magenta, yellow, red

    public static function enabled(): bool {
        if (self::$enabled === null) {
            $term = getenv('TERM') ?: '';
            $no = getenv('NO_COLOR');
            self::$enabled = function_exists('posix_isatty') && posix_isatty(STDOUT) && !$no && $term !== '';
        }
        return self::$enabled;
    }

    public static function disable(): void {
        self::$enabled = false;
    }

    public static function colorize(string $text, int $code): string {
        if (!self::enabled()) {
            return $text;
        }
        return "\033[" . $code . "m" . $text . "\033[0m";
    }

    public static function remote(string $name): string {
        if (!isset(self::$palette[$name]) && $name !== 'local') {
            // stable assignment based on hash
            $idx = crc32($name) % count(self::$available);
            self::$palette[$name] = self::$available[$idx];
        }
        $code = self::$palette[$name] ?? 37; // default white
        return self::colorize($name, $code);
    }
}

