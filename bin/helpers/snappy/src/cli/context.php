<?php

namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Config\config_manager;
use Snappy\Snapshot\index_manager; // added
use Snappy\Snapshot\remote_index_manager; // new

class context {
    public config_manager $config;
    public remote_registry $registry;
    public snapshot_manager $manager;
    public remote_snapshot_cache $cache;
    public index_manager $index; // local index manager
    public remote_index_manager $remote_index; // remote index manager
    public output_formatter $out; // global output formatter

    public function __construct(config_manager $config, remote_registry $registry, snapshot_manager $manager, remote_snapshot_cache $cache, index_manager $index, remote_index_manager $remoteIndex, ?output_formatter $out = null) {
        $this->config = $config;
        $this->registry = $registry;
        $this->manager = $manager;
        $this->cache = $cache;
        $this->index = $index;
        $this->remote_index = $remoteIndex;
        $this->out = $out ?? new output_formatter(false, false);
    }
}
