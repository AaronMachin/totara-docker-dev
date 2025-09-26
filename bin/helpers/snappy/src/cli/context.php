<?php

namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\remote_snapshot_cache;

class context {
    public remote_registry $registry;
    public snapshot_manager $manager;
    public remote_snapshot_cache $cache;

    public function __construct(remote_registry $registry, snapshot_manager $manager, remote_snapshot_cache $cache) {
        $this->registry = $registry;
        $this->manager = $manager;
        $this->cache = $cache;
    }
}
