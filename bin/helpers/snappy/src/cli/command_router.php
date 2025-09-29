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
            // dynamic grouped help
            $this->print_root_help($ctx);
            if ($primary === 'help') { $this->lastCommand = 'help'; }
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
        if (!$ctx) { return; }
        // Collect metadata from all commands
        $metaList = [];
        foreach ($this->map as $primary => $subs) {
            foreach ($subs as $sub => $handler) {
                if (method_exists($handler,'metadata')) { $m = $handler->metadata(); }
                else {
                    // Fallback legacy shape
                    $m = [
                        'name' => $primary.'.'.$sub,
                        'group' => 'Other',
                        'description' => $handler->description(),
                        'usage' => trim($handler->usage()),
                        'examples' => $handler->examples(),
                    ];
                }
                // Ensure canonical name / tokens for display
                $m['primary'] = $primary;
                $m['sub'] = $sub;
                $metaList[] = $m;
            }
        }
        // Group by group key
        $groups = [];
        foreach ($metaList as $m) { $groups[$m['group'] ?? 'Other'][] = $m; }
        // Stable ordering: Snapshot, Remote, Maintenance, Config, Other, then alpha for any extras
        $order = ['Snapshot','Remote','Maintenance','Config','Other'];
        $ordered = [];
        foreach ($order as $g) { if (isset($groups[$g])) { $ordered[$g] = $groups[$g]; unset($groups[$g]); } }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as $g=>$arr) { $ordered[$g] = $arr; }
        // Sort commands within each group by primary then sub
        foreach ($ordered as $g => $arr) {
            usort($arr, function($a,$b){
                return [$a['primary'],$a['sub']] <=> [$b['primary'],$b['sub']];
            });
            $ordered[$g] = $arr;
        }
        if ($ctx->out->isJson()) {
            $ctx->out->json(['commands'=>array_map(function($m){
                // Remove helper fields primary/sub duplicates; keep name
                unset($m['primary'],$m['sub']);
                return $m;
            }, $metaList)]);
            return;
        }
        $ctx->out->info('Snappy CLI - grouped command help');
        $ctx->out->info('');
        $ctx->out->info('Usage: tsnap <primary> <subcommand> [options]');
        $ctx->out->info('');
        foreach ($ordered as $groupName => $entries) {
            $ctx->out->info('['.$groupName.']');
            // Compute padding for nice columns
            $maxCmd = 0; foreach ($entries as $e) { $disp = $e['primary'].' '.$e['sub']; $maxCmd = max($maxCmd, strlen($disp)); }
            foreach ($entries as $e) {
                $disp = $e['primary'].' '.$e['sub'];
                $pad = str_pad($disp, $maxCmd, ' ');
                $ctx->out->info('  '.$pad.'  '.$e['description']);
                $examples = $e['examples'] ?? [];
                if ($examples) {
                    $ctx->out->info('    eg: '.$examples[0]);
                }
            }
            $ctx->out->info('');
        }
        $ctx->out->info('Run: tsnap <primary> <subcommand> --help for detailed usage and more examples.');
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
