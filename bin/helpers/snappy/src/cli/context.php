<?php

namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Config\config_manager;
use Snappy\Snapshot\index_manager;

class context {
    public config_manager $config;
    public remote_registry $registry;
    public snapshot_manager $manager;
    public index_manager $index;
    public output_formatter $out;

    public function __construct(config_manager $config, remote_registry $registry, snapshot_manager $manager, index_manager $index, ?output_formatter $out = null) {
        $this->config = $config;
        $this->registry = $registry;
        $this->manager = $manager;
        $this->index = $index;
        $this->out = $out ?? new output_formatter(false, false);
    }
}
