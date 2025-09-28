<?php

namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Config\config_manager;
use Snappy\Snapshot\index_manager; // added

class context {
    public config_manager $config;
    public remote_registry $registry;
    public snapshot_manager $manager;
    public remote_snapshot_cache $cache;
    public index_manager $index; // new index manager reference

    public function __construct(config_manager $config, remote_registry $registry, snapshot_manager $manager, remote_snapshot_cache $cache, index_manager $index) {
        $this->config = $config;
        $this->registry = $registry;
        $this->manager = $manager;
        $this->cache = $cache;
        $this->index = $index;
    }
}
