<?php

namespace Snappy\Snapshot;

/**
 * Value object returned by a dump provider.
 */
class DumpResult {
    /** @var array<int,array{name:string,path:string}> */
    private array $files;
    /** @var array<string,mixed> */
    private array $metadata;

    /**
     * @param array<int,array{name:string,path:string}> $files
     * @param array<string,mixed> $metadata
     */
    public function __construct(array $files, array $metadata = []) {
        $norm = [];
        foreach ($files as $f) {
            if (!is_array($f)) { continue; }
            $name = (string)($f['name'] ?? '');
            $path = (string)($f['path'] ?? '');
            if ($name === '' || $path === '') { continue; }
            $norm[] = ['name' => $name, 'path' => $path];
        }
        $this->files = $norm;
        $this->metadata = $metadata;
    }

    /** @return array<int,array{name:string,path:string}> */
    public function files(): array { return $this->files; }

    /** @return array<string,mixed> */
    public function metadata(): array { return $this->metadata; }
}

