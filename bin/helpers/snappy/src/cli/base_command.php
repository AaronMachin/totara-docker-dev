<?php

namespace Snappy\Cli;

abstract class base_command implements command {
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function usage(): string; // should NOT end with extra blank lines
    abstract public function run(array $args, context $ctx): int;

    public function examples(): array { return []; }

    public function display_help(): void {
        echo $this->description() . "\n\n";
        $usage = trim($this->usage());
        if ($usage !== '') { echo $usage . "\n"; }
        $examples = $this->examples();
        if ($examples) {
            echo "\nExamples:\n";
            foreach ($examples as $ex) { echo "  $ex\n"; }
        }
    }

    protected function parseArgs(array $argv, array $definitions): array {
        return args::parse($argv, $definitions);
    }
    protected function firstPositional(array $parsed): ?string {
        return $parsed['positionals'][0] ?? null;
    }
}
