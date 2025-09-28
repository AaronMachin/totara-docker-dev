<?php
namespace Snappy\Support\Process;

// (not used here directly but likely in callers)

class process_runner {
    public const OUTPUT_LIMIT = 1048576; // 1MB per stream

    /**
     * Run a command and capture stdout, stderr, exit code & duration.
     * @param array $command Command vector, first element binary path.
     * @param array $env Additional/override env vars.
     * @param int|null $timeout Timeout in seconds (soft). Null = unlimited.
     */
    public function run(array $command, array $env = [], ?int $timeout = null): process_result {
        $start = (int) (microtime(true) * 1000);
        $descriptor = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        $fullEnv = $this->buildEnv($env);
        $proc = @proc_open($command, $descriptor, $pipes, null, $fullEnv, [
            'bypass_shell' => true,
        ]);
        if (!\is_resource($proc)) {
            // Could not start process; simulate failed result with exitCode 127
            return new process_result(127, '', 'Failed to start process', (int) (microtime(true) * 1000) - $start);
        }
        foreach ($pipes as $p) { stream_set_blocking($p, false); }
        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $limit = self::OUTPUT_LIMIT;
        $deadline = $timeout ? (microtime(true) + $timeout) : null;
        while (true) {
            $status = proc_get_status($proc);
            $running = $status['running'] ?? false;
            $read = [];
            if (isset($pipes[1])) { $read[] = $pipes[1]; }
            if (isset($pipes[2])) { $read[] = $pipes[2]; }
            $write = $except = [];
            if (!$read) { break; }
            @stream_select($read, $write, $except, 0, 200000); // 200ms
            foreach ($read as $r) {
                $data = @fread($r, 8192);
                if ($data === false || $data === '') { continue; }
                if ($r === $pipes[1]) {
                    if (!$stdoutTruncated) {
                        if (strlen($stdout) + strlen($data) <= $limit) { $stdout .= $data; }
                        else {
                            $remain = $limit - strlen($stdout);
                            if ($remain > 0) { $stdout .= substr($data, 0, $remain); }
                            $stdoutTruncated = true;
                        }
                    }
                } elseif ($r === $pipes[2]) {
                    if (!$stderrTruncated) {
                        if (strlen($stderr) + strlen($data) <= $limit) { $stderr .= $data; }
                        else {
                            $remain = $limit - strlen($stderr);
                            if ($remain > 0) { $stderr .= substr($data, 0, $remain); }
                            $stderrTruncated = true;
                        }
                    }
                }
            }
            if (!$running) { break; }
            if ($deadline && microtime(true) > $deadline) {
                // Timeout exceeded; terminate and mark.
                @proc_terminate($proc, 9);
                $stderr .= ($stderr ? "\n" : '') . '[snappy] Process timeout exceeded (' . $timeout . 's)';
                $stderrTruncated = strlen($stderr) > $limit;
                break;
            }
        }
        // Close pipes
        foreach ($pipes as $p) { @fclose($p); }
        $exitCode = proc_close($proc);
        $duration = (int) (microtime(true) * 1000) - $start;
        return new process_result($exitCode, $stdout, $stderr, $duration, $stdoutTruncated, $stderrTruncated);
    }

    private function buildEnv(array $overrides): array {
        // Start with current environment. getenv() without args returns array in some SAPIs; fallback to $_ENV+$_SERVER.
        $base = function_exists('getenv') ? (array) getenv() : [];
        if (!$base) { $base = $_ENV + $_SERVER; }
        foreach ($overrides as $k => $v) { $base[$k] = $v; }
        return $base;
    }
}
