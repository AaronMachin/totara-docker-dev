<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;

class help implements command {
    private array $commands = [];

    public function name(): string {
        return 'help';
    }

    public function description(): string {
        return 'Show help for all commands';
    }

    public function set_commands(array $commands): void {
        // Filter only command instances
        $out = [];
        foreach ($commands as $k => $cmd) {
            if ($cmd instanceof command) {
                $out[$cmd->name()] = $cmd;
            }
        }
        ksort($out, SORT_STRING);
        $this->commands = $out;
    }

    public function run(array $args, context $ctx): int {
        $this->print_usage();
        return 0;
    }

    private function print_usage(): void {
        $lines = [];
        $max = 0;
        foreach ($this->commands as $cmd) {
            $n = $cmd->name();
            $max = max($max, strlen($n));
        }
        echo "tsnap snapshot service\n\nCommands:\n";
        foreach ($this->commands as $cmd) {
            $name = $cmd->name();
            $desc = $cmd->description();
            printf("  %-{$max}s  %s\n", $name, $desc);
        }
        echo "\nUsage examples:\n";
        echo "  tsnap snap -m 'before upgrade'\n";
        echo "  tsnap push a1b2c3 origin\n";
        echo "  tsnap pull a1b2c3 origin\n";
        echo "  tsnap list --full --remote=origin --limit=20\n";
        echo "  tsnap remote list\n";
        echo "  tsnap remote add origin s3 --endpoint=https://s3.example --bucket=mybucket --region=us-east-1 --key=AKIA... --secret=...\n";
        echo "  tsnap remote remove origin\n";
        echo "\nSet SNAPPY_SNAPSHOT_ROOT to change local snapshot root.\n";
    }
}
