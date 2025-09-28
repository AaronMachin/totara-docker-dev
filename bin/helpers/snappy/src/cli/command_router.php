<?php
namespace Snappy\Cli;

use Snappy\Cli\context;

/**
 * Hierarchical command router: tsnap <primary> <sub> [args]
 */
class command_router {
    /** @var array<string,array<string,command>> */
    private array $map = [];
    private ?string $lastCommand = null; // track dispatched command

    public function register(string $primary, string $sub, command $handler): void {
        $this->map[$primary][$sub] = $handler;
    }

    /** Return list of primary=>[subs] */
    public function manifest(): array { return array_map(fn($subs)=>array_keys($subs), $this->map); }

    public function route(array $argv, context $ctx): int {
        file_put_contents('/tmp/snappy_cli_debug.log', date('c')." route argv=".json_encode($argv)."\n", FILE_APPEND);
        $this->lastCommand = null;
        $primary = $argv[0] ?? '';
        if ($primary === '' || $primary === 'help') {
            $this->print_root_help($ctx);
            return $primary === 'help' ? 0 : 1;
        }
        $sub = $argv[1] ?? '';
        if ($sub === '' || $sub === 'help') {
            if (!isset($this->map[$primary])) { $ctx->out->error("Unknown primary command: $primary", 1); $this->print_root_help($ctx); return 1; }
            $this->print_primary_help($primary, $ctx); return $sub === 'help' ? 0 : 1;
        }
        if (!isset($this->map[$primary][$sub])) {
            $ctx->out->error("Unknown subcommand: $primary $sub", 1);
            $this->print_primary_help($primary, $ctx);
            return 1;
        }
        // Support global --help after subcommand tokens
        $remaining = array_slice($argv, 2);
        foreach ($remaining as $r) {
            if ($r === '--help' || $r === '-h') {
                $this->map[$primary][$sub]->display_help();
                $ctx->out->info('');
                return 0;
            }
        }
        $this->lastCommand = $primary . '.' . $sub;
        return $this->map[$primary][$sub]->run($remaining, $ctx);
    }

    public function last_command(): ?string { return $this->lastCommand; }

    private function print_root_help(?context $ctx = null): void {
        $lines = [
            'Snappy hierarchical CLI (primary subcommand)',
            '',
            'Usage: tsnap <primary> <subcommand> [options]',
            '',
            'Primary commands:'
        ];
        foreach ($this->map as $p => $subs) { $lines[] = "  $p  (".implode(', ', array_keys($subs)).")"; }
        $lines[] = ''; $lines[] = 'Examples:';
        $lines[] = "  tsnap snapshot create -m 'initial load'";
        $lines[] = "  tsnap snapshot list --limit=20";
        $lines[] = "  tsnap share create <uid|prefix>";
        $lines[] = "  tsnap config get options.snapshot_root";
        if ($ctx) { foreach ($lines as $l) { $ctx->out->info($l); } }
        else { echo implode("\n", $lines) . "\n"; }
    }

    private function print_primary_help(string $primary, ?context $ctx = null): void {
        if (!isset($this->map[$primary])) { return; }
        $lines = ["Usage: tsnap $primary <subcommand> [options]", '', "Subcommands for $primary:"];
        foreach ($this->map[$primary] as $sub => $handler) { $lines[] = "  $sub  - ".$handler->description(); }
        $lines[] = "Use: tsnap $primary <subcommand> --help for details.";
        if ($ctx) { foreach ($lines as $l) { $ctx->out->info($l); } }
        else { echo implode("\n", $lines) . "\n"; }
    }
}
