<?php

namespace Snappy\Cli;

interface command {
    public function name(): string;
    public function description(): string;
    public function usage(): string;
    public function examples(): array;
    public function display_help(): void;
    public function run(array $args, context $ctx): int;
}
