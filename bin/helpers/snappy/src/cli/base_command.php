<?php

namespace Snappy\Cli;

abstract class base_command implements command {
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function usage(): string; // should NOT end with extra blank lines
    abstract public function run(array $args, context $ctx): int;

    public function examples(): array { return []; }

    /**
     * Default metadata implementation used by dynamic help system.
     * Commands can override if they need custom grouping or fields.
     */
    public function metadata(): array {
        $name = $this->name();
        $group = $this->inferGroup($name);
        return [
            'name' => $name,
            'group' => $group,
            'description' => $this->description(),
            'usage' => trim($this->usage()),
            'examples' => $this->examples(),
        ];
    }

    protected function inferGroup(string $name): string {
        $prefix = $name;
        if (str_contains($name, '.')) { $prefix = explode('.', $name, 2)[0]; }
        return match($prefix) {
            'snapshot' => 'Snapshot',
            'share' => 'Share',
            'remote' => 'Remote',
            'config' => 'Config',
            'prune', 'verify', 'doctor', 'gc' => 'Maintenance',
            default => 'Other'
        };
    }

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
