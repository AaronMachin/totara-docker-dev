<?php
namespace Snappy\Cli;

use Snappy\Storage\s3_storage;
use Snappy\Snapshot\remote_cache;
use Snappy\Snapshot\snapshot_manager;

class context {
    public s3_storage $storage;
    public remote_cache $cache;
    public snapshot_manager $manager;
    public string $root_dir;

    public function __construct(s3_storage $storage, remote_cache $cache, snapshot_manager $manager, string $root_dir) {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->manager = $manager;
        $this->root_dir = $root_dir;
    }
}

