<?php

namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Config\config_manager;

class context {
    public config_manager $config;
    public remote_registry $registry;
    public snapshot_manager $manager;
    public remote_snapshot_cache $cache;

    public function __construct(config_manager $config, remote_registry $registry, snapshot_manager $manager, remote_snapshot_cache $cache) {
        $this->config = $config;
        $this->registry = $registry;
        $this->manager = $manager;
        $this->cache = $cache;
    }
}
