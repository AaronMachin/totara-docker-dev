<?php
namespace Snappy\Support\Process;

/**
 * Immutable value object carrying process execution results.
 */
class process_result {
    public readonly int $exitCode;
    public readonly string $stdout;
    public readonly string $stderr;
    public readonly int $durationMs;
    public readonly bool $stdoutTruncated;
    public readonly bool $stderrTruncated;

    public function __construct(int $exitCode, string $stdout, string $stderr, int $durationMs, bool $stdoutTruncated = false, bool $stderrTruncated = false) {
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->durationMs = $durationMs;
        $this->stdoutTruncated = $stdoutTruncated;
        $this->stderrTruncated = $stderrTruncated;
    }
}
