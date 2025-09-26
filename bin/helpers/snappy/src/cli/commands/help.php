<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\command;
use Snappy\Cli\context;

class help extends base_command {
    private array $commands = [];

    public function name(): string { return 'help'; }
    public function description(): string { return 'Show help for all commands or a specific command'; }
    public function usage(): string {
        return "Usage:\n  tsnap help              Show list of commands\n  tsnap help <command>    Show detailed help for a command\n  tsnap <command> --help  Same as above";
    }
    public function examples(): array {
        return [
            'tsnap help',
            'tsnap help snap',
        ];
    }

    public function set_commands(array $commands): void {
        $out = [];
        foreach ($commands as $cmd) {
            if ($cmd instanceof command) { $out[$cmd->name()] = $cmd; }
        }
        ksort($out, SORT_STRING);
        $this->commands = $out;
    }

    public function run(array $args, context $ctx): int {
        $target = $args[0] ?? '';
        if ($target !== '' && isset($this->commands[$target])) {
            $this->commands[$target]->display_help();
            return 0;
        }
        if ($target !== '' && !isset($this->commands[$target])) {
            fwrite(STDERR, "unknown command: $target\n\n");
        }
        $this->printOverview();
        return 0;
    }

    private function printOverview(): void {
        $max = 0; foreach ($this->commands as $cmd) { $max = max($max, strlen($cmd->name())); }
        echo "tsnap snapshot service\n\n";
        echo rtrim($this->usage()) . "\n\nCommands:\n";
        foreach ($this->commands as $cmd) { printf("  %-{$max}s  %s\n", $cmd->name(), $cmd->description()); }
    }
}
