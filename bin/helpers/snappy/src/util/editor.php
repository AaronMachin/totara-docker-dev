<?php
namespace Snappy\Util;

class editor {
    public static function acquire(string $template): string {
        $content = getenv('SNAPPY_MESSAGE');
        if ($content !== false && $content !== '') {
            $msg = self::process($content);
            return $msg;
        }
        $has_posix = function_exists('posix_isatty');
        $stdin_tty = $has_posix ? @posix_isatty(STDIN) : false;
        $stdout_tty = $has_posix ? @posix_isatty(STDOUT) : false;
        $dev_tty_path = '/dev/tty';
        $dev_tty_available = is_readable($dev_tty_path) && is_writable($dev_tty_path);
        $interactive = ($stdin_tty && $stdout_tty) || $dev_tty_available;
        if ($interactive) {
            $editor = getenv('EDITOR') ?: 'vi';
            $tmp = tempnam(sys_get_temp_dir(), 'snappymsg');
            file_put_contents($tmp, $template);
            $cmd = escapeshellcmd($editor) . ' ' . escapeshellarg($tmp);
            if ($dev_tty_available) {
                $cmd .= ' </dev/tty >/dev/tty 2>&1';
            }
            $rc = 0;
            system($cmd, $rc);
            $content = @file_get_contents($tmp);
            @unlink($tmp);
            $msg = self::process($content);
            if ($rc === 0 && $msg !== '') {
                return $msg;
            }
            if ($dev_tty_available) {
                $fh = @fopen($dev_tty_path, 'r');
                if ($fh) {
                    fwrite(STDERR, "Enter snapshot message: ");
                    $line = fgets($fh);
                    fclose($fh);
                    return self::process($line);
                }
            }
        }
        fwrite(STDERR, "Non-interactive: supply -m or SNAPPY_MESSAGE.\n");
        return '';
    }

    private static function process($content): string {
        if ($content === false || $content === null) {
            return '';
        }
        $lines = preg_split('/\r?\n/', $content);
        $filtered = [];
        foreach ($lines as $ln) {
            if (preg_match('/^\s*#/', $ln)) {
                continue;
            }
            $filtered[] = rtrim($ln, "\r");
        }
        while (count($filtered) && trim($filtered[0]) === '') {
            array_shift($filtered);
        }
        while (count($filtered) && trim($filtered[count($filtered)-1]) === '') {
            array_pop($filtered);
        }
        return trim(implode("\n", $filtered));
    }
}
