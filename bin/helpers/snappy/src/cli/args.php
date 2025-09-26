<?php

namespace Snappy\Cli;

/**
 * Lightweight argv parser to DRY simple flag handling.
 * Definition format (array $definitions):
 *   key => [
 *     'flags' => ['--full','-f'],            // optional array of exact match flags (boolean true when present)
 *     'prefix' => '--limit=',                // optional string prefix to capture value after '=' (last occurrence wins)
 *     'type' => 'bool'|'int'|'string',       // basic casting
 *     'default' => mixed,                    // default value
 *   ]
 * Returned structure: [ 'options'=>[key=>value,...], 'positionals'=>[...], 'errors'=>[...unknown flags...] ]
 */
class args {
    public static function parse(array $argv, array $definitions): array {
        $opts = [];
        $errors = [];
        $positionals = [];
        // seed defaults
        foreach ($definitions as $k => $def) {
            if (array_key_exists('default', $def)) {
                $opts[$k] = $def['default'];
            } else {
                $opts[$k] = ($def['type'] ?? 'string') === 'bool' ? false : null;
            }
        }
        foreach ($argv as $arg) {
            if ($arg === '' ) { continue; }
            if ($arg[0] !== '-') { $positionals[] = $arg; continue; }
            $matched = false;
            foreach ($definitions as $k => $def) {
                // exact flags
                if (isset($def['flags'])) {
                    foreach ($def['flags'] as $flag) {
                        if ($arg === $flag) {
                            $opts[$k] = ($def['type'] ?? 'bool') === 'bool' ? true : $opts[$k];
                            $matched = true; break 2;
                        }
                    }
                }
                // prefix pattern
                if (!$matched && isset($def['prefix']) && str_starts_with($arg, $def['prefix'])) {
                    $val = substr($arg, strlen($def['prefix']));
                    $type = $def['type'] ?? 'string';
                    if ($type === 'int') { $val = (int)$val; }
                    $opts[$k] = $val; $matched = true; break;
                }
            }
            if (!$matched) {
                // help flags deliberately not treated as error (handled outside)
                if ($arg === '--help' || $arg === '-h') { continue; }
                $errors[] = 'unknown option: ' . $arg;
            }
        }
        return ['options'=>$opts,'positionals'=>$positionals,'errors'=>$errors];
    }
}

