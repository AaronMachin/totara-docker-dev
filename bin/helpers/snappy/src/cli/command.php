<?php

namespace Snappy\Cli;

interface command {
    public function name(): string;
    public function description(): string;
    public function usage(): string;
    public function examples(): array;
    /** Return structured metadata for help system */
    public function metadata(): array;
    public function display_help(): void;
    public function run(array $args, context $ctx): int;
}
