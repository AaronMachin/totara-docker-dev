<?php
namespace Snappy\Cli;

use Snappy\Util\color;

class listing implements command {
    public function name(): string { return 'list'; }
    public function description(): string { return 'List snapshots (unified across selected stores)'; }

    public function run(array $args, context $ctx): int {
        $full = false; $limit = 100; $remote_csv = null; $since = null; $before = null; $parallel = true;
        foreach ($args as $arg) {
            if ($arg === '--full' || $arg === '--full-message') { $full = true; }
            elseif (str_starts_with($arg, '--limit=')) { $limit = (int)substr($arg, 8); }
            elseif (str_starts_with($arg, '--remote=')) { $remote_csv = substr($arg, 9); }
            elseif (str_starts_with($arg, '--since=')) { $since = $this->parse_time(substr($arg, 8)); }
            elseif (str_starts_with($arg, '--before=')) { $before = $this->parse_time(substr($arg, 9)); }
            elseif ($arg === '--no-parallel') { $parallel = false; }
            else { fwrite(STDERR, "unknown option: $arg\n"); return 1; }
        }
        $all_remotes = $ctx->registry->names();
        $selected = $all_remotes;
        if ($remote_csv !== null) {
            if ($remote_csv === '') { $selected = $all_remotes; }
            else {
                $parts = array_filter(array_map('trim', explode(',', $remote_csv)), 'strlen');
                $selected = [];
                foreach ($parts as $p) { if (!$ctx->registry->has($p)) { fwrite(STDERR, "unknown remote/store: $p\n"); return 2; } $selected[] = $p; }
                $selected = array_values(array_unique($selected));
            }
        }
        usort($selected, function($a,$b){ if ($a==='local'&&$b!=='local') return -1; if ($b==='local'&&$a!=='local') return 1; return strcmp($a,$b); });
        $rows = $ctx->manager->list_multi($selected, $full, $limit, $parallel);
        // Time filtering
        if ($since || $before) {
            $rows = array_filter($rows, function($r) use ($since,$before) {
                $created = strtotime($r['created'] ?? '') ?: 0;
                if ($since && $created < $since) { return false; }
                if ($before && $created > $before) { return false; }
                return true;
            });
            $rows = array_values($rows);
        }
        echo "Snapshots (sources: " . implode(', ', array_map(fn($n)=>color::remote($n), $selected)) . ")" . ($since||$before?" [filtered]":"") . "\n";
        if (!$rows) { echo "(none)\n"; return 0; }
        // Widths
        $w_uid=3;$w_created=7;$w_type=4;$w_locations=9;
        foreach ($rows as $r) { // widths based on raw (uncoloured) locations text
            $w_uid = max($w_uid, strlen($r['uid']));
            $w_created = max($w_created, strlen($r['created']));
            $w_type = max($w_type, strlen($r['type']));
            $w_locations = max($w_locations, strlen($r['locations']));
        }
        printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %-{$w_locations}s  %s\n", 'UID','CREATED','TYPE','LOCATIONS','MESSAGE');
        foreach ($rows as $r) {
            $m = $r['message']; if (!$full && strlen($m)>120) { $m = substr($m,0,117).'...'; }
            $coloredLoc = $this->color_and_pad_locations($r['locations'], $w_locations);
            printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s  %s\n", $r['uid'],$r['created'],$r['type'],$coloredLoc,$m);
        }
        return 0;
    }

    private function color_and_pad_locations(string $raw, int $width): string {
        $parts = array_map('trim', explode(',', $raw));
        $coloredParts = array_map(fn($n)=>color::remote($n), $parts);
        $colored = implode(', ', $coloredParts);
        $printableLen = strlen(implode(', ', $parts)); // visible length without ANSI
        if ($printableLen < $width) {
            $colored .= str_repeat(' ', $width - $printableLen);
        }
        return $colored;
    }

    private function parse_time(string $expr): ?int {
        $expr = trim($expr);
        if ($expr === '') { return null; }
        // Relative formats: Nd / Nh / Nm / Ns
        if (preg_match('/^(\d+)([smhd])$/i', $expr, $m)) {
            $n = (int)$m[1]; $u = strtolower($m[2]); $sec = 0;
            switch ($u) { case 's': $sec=$n; break; case 'm': $sec=$n*60; break; case 'h': $sec=$n*3600; break; case 'd': $sec=$n*86400; break; }
            return time() - $sec; // since X = now - offset
        }
        $ts = strtotime($expr);
        return $ts ?: null;
    }
}
