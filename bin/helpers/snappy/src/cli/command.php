<?php

namespace Snappy\Cli;

interface command {
    public function name(): string;

    public function description(): string;

    public function run(array $args, context $ctx): int;
}

