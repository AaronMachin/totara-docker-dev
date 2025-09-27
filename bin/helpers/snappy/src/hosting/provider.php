<?php
namespace Snappy\Hosting;

/**
 * Provider interface abstracts how an external/public endpoint is created
 * for the locally running S3/minio service.
 */
interface provider {
    /** Provider machine readable name (e.g. 'ngrok'). */
    public function name(): string;

    /**
     * Start the provider and return provider specific state. Must include:
     *  - pid (int) if a background process was launched
     *  - endpoint (string) externally accessible base URL (no trailing slash)
     *  - started (ISO8601 string)
     *  - log_file (string) optional path to log file
     */
    public function start(options $opts, string $root): array;

    /** Determine if provider is still running given saved state. */
    public function isRunning(array $state): bool;

    /** Attempt a graceful stop. */
    public function stop(array $state): void;

    /** Stream logs to STDOUT until interrupted. */
    public function streamLogs(array $state): void;
}

