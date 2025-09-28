<?php
namespace Snappy\Cli;

use Snappy\Cli\context;

/**
 * Hierarchical command router: tsnap <primary> <sub> [args]
 */
class command_router {
    /** @var array<string,array<string,command>> */
    private array $map = [];

    public function register(string $primary, string $sub, command $handler): void {
        $this->map[$primary][$sub] = $handler;
    }

    /** Return list of primary=>[subs] */
    public function manifest(): array { return array_map(fn($subs)=>array_keys($subs), $this->map); }

    public function route(array $argv, context $ctx): int {
        file_put_contents('/tmp/snappy_cli_debug.log', date('c')." route argv=".json_encode($argv)."\n", FILE_APPEND);
        $primary = $argv[0] ?? '';
        file_put_contents('/tmp/snappy_cli_debug.log', date('c')." primary=$primary\n", FILE_APPEND);
        if ($primary === '' || $primary === 'help') {
            $this->print_root_help();
            return $primary === 'help' ? 0 : 1;
        }
        $sub = $argv[1] ?? '';
        if ($sub === '' || $sub === 'help') {
            if (!isset($this->map[$primary])) { fwrite(STDERR, "Unknown primary command: $primary\n"); $this->print_root_help(); return 1; }
            $this->print_primary_help($primary); return $sub === 'help' ? 0 : 1;
        }
        if (!isset($this->map[$primary][$sub])) {
            file_put_contents('/tmp/snappy_cli_debug.log', date('c')." unknown_sub sub=$sub\n", FILE_APPEND);
            fwrite(STDERR, "Unknown subcommand: $primary $sub\n");
            $this->print_primary_help($primary);
            return 1;
        }
        // Support global --help after subcommand tokens
        $remaining = array_slice($argv, 2);
        foreach ($remaining as $r) {
            if ($r === '--help' || $r === '-h') {
                file_put_contents('/tmp/snappy_cli_debug.log', date('c')." help_for=$primary.$sub\n", FILE_APPEND);
                $this->map[$primary][$sub]->display_help();
                echo "\n"; return 0;
            }
        }
        file_put_contents('/tmp/snappy_cli_debug.log', date('c')." dispatch=$primary.$sub remaining=".json_encode($remaining)."\n", FILE_APPEND);
        return $this->map[$primary][$sub]->run($remaining, $ctx);
    }

    private function print_root_help(): void {
        echo "Snappy hierarchical CLI (primary subcommand)\n\n";
        echo "Usage: tsnap <primary> <subcommand> [options]\n\n";
        echo "Primary commands:\n";
        foreach ($this->map as $p => $subs) {
            echo "  $p  (".implode(', ', array_keys($subs)).")\n";
        }
        echo "\nExamples:\n";
        echo "  tsnap snapshot create -m 'initial load'\n";
        echo "  tsnap snapshot list --limit=20\n";
        echo "  tsnap share create <uid|prefix>\n";
        echo "  tsnap config get options.snapshot_root\n";
    }

    private function print_primary_help(string $primary): void {
        if (!isset($this->map[$primary])) { return; }
        echo "Usage: tsnap $primary <subcommand> [options]\n\n";
        echo "Subcommands for $primary:\n";
        foreach ($this->map[$primary] as $sub => $handler) {
            echo "  $sub  - ".$handler->description()."\n";
        }
        echo "Use: tsnap $primary <subcommand> --help for details.\n";
    }
}
