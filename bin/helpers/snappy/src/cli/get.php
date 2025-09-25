<?php
namespace Snappy\Cli;

class get implements command {
    public function name(): string { return 'get'; }
    public function description(): string { return 'Download a remote object to a local file'; }

    public function run(array $args, context $ctx): int {
        $key = $args[0] ?? '';
        if ($key === '') {
            fwrite(STDERR, "key required\n");
            return 1;
        }
        $output = '';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $output = substr($arg, 9);
            }
        }
        if ($output === '') {
            $output = basename($key);
        }
        $dir = dirname($output);
        if ($dir && !is_dir($dir)) {
            fwrite(STDERR, 'directory does not exist: ' . $dir . "\n");
            return 5;
        }
        try {
            $ctx->storage->get_object($key, $output);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'download failed: ' . $e->getMessage() . "\n");
            return 6;
        }
        echo 'saved to ' . $output . "\n";
        return 0;
    }
}

