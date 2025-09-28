<?php

namespace Snappy\Snapshot;

/**
 * DumpProviderInterface defines a pluggable mechanism for producing snapshot dump artifacts.
 * Implementations should be stateless (or cheaply instantiable) and rely on provided context.
 */
interface DumpProviderInterface {
    /**
     * Determine if this provider supports the given context (e.g. snapshot type).
     * @param array $context Arbitrary context: at minimum ['type' => string].
     */
    public function supports(array $context): bool;

    /**
     * Execute a dump for the provided UID writing artifacts outside the final snapshot dir.
     * Returned DumpResult file paths will subsequently be copied into the final snapshot directory.
     * @param string $uid Snapshot UID (alias) used when invoking external tooling.
     * @param string $targetDir The final snapshot directory (informational; providers should not write final files here directly).
     * @param array $options Provider specific options (unused for now).
     * @throws \Snappy\Support\Exception\ProcessFailedException on process failure.
     */
    public function dump(string $uid, string $targetDir, array $options = []): DumpResult;
}

