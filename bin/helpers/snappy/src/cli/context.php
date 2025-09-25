<?php
namespace Snappy\Cli;

use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_registry;

class context {
    public remote_registry $registry;
    public snapshot_manager $manager;

    public function __construct(remote_registry $registry, snapshot_manager $manager) {
        $this->registry = $registry;
        $this->manager = $manager;
    }
}
