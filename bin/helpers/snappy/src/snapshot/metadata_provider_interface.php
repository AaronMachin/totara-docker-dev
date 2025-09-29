<?php
namespace Snappy\Snapshot;

interface metadata_provider_interface {
    /**
     * Generate one or more metadata files for a snapshot export.
     * @param string $uid Snapshot UID
     * @param string $snapshotDir Path to snapshot directory
     * @param array $context Arbitrary context: ['manifest'=>array,'files'=>array<int,string>,'file_hashes'=>array<string,string>,'artifact_sha256'=>string]
     * @return array<int,array{name:string,content:string}> List of metadata file descriptors (small strings only)
     */
    public function generate(string $uid,string $snapshotDir,array $context): array;
}

