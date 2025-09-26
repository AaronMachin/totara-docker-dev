<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Throwable;
use Snappy\Snapshot\remote_snapshot_cache;

class fetch implements command {
    public function name(): string { return 'fetch'; }
    public function description(): string { return 'Fetch (refresh) remote snapshot metadata cache: fetch [--remote=name1,name2|--all] [--limit=N]'; }

    public function run(array $args, context $ctx): int {
        $remote_csv = null; $limit = 100; $all = false; $staleOnly = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--remote=')) { $remote_csv = substr($arg, 9); }
            elseif (str_starts_with($arg, '--limit=')) { $limit = (int) substr($arg, 8); }
            elseif ($arg === '--all') { $all = true; }
            elseif ($arg === '--stale-only') { $staleOnly = true; }
            elseif ($arg === '--help') { $this->usage(); return 0; }
            else { fwrite(STDERR, "unknown option: $arg\n"); return 1; }
        }
        $names = [];
        if ($all || $remote_csv === null) { $names = array_filter($ctx->registry->names(), fn($n)=>$n!=='local'); }
        if ($remote_csv !== null) {
            $parts = array_filter(array_map('trim', explode(',', $remote_csv)), 'strlen');
            $names = [];
            foreach ($parts as $p) {
                if (!$ctx->registry->has($p)) { fwrite(STDERR, "unknown remote: $p\n"); return 2; }
                if ($p === 'local') continue; $names[] = $p;
            }
        }
        if (!$names) { echo "No non-local remotes to fetch. Use tsnap remote add ... first.\n"; return 0; }
        $totalFetched=0; $totalRemoved=0; $totalSnapshots=0; $count=0; $skipped=0;
        foreach ($names as $r) {
            if ($staleOnly) {
                $c = $ctx->cache->load($r);
                if ($c && !\Snappy\Snapshot\remote_snapshot_cache::is_stale($c['fetched_at'], $ctx->registry)) { $skipped++; continue; }
            }
            try {
                $stats = $ctx->cache->update_remote($r, $ctx->registry, $ctx->manager, $limit*20);
                $totalFetched += $stats['fetched'];
                $totalRemoved += $stats['removed'];
                $totalSnapshots += $stats['total'];
                $count++;
                echo "Fetched $r: +{$stats['fetched']} / -{$stats['removed']} (total {$stats['total']})\n";
            } catch (Throwable $e) {
                fwrite(STDERR, "fetch failed for $r: " . $e->getMessage() . "\n");
            }
        }
        echo "Updated $count remote cache(s)" . ($staleOnly?" (stale-only)":"") . ". Skipped fresh: $skipped. Changed: +$totalFetched -$totalRemoved. Total cached snapshots: $totalSnapshots\n";
        return 0;
    }

    private function usage(): void {
        echo "Usage: tsnap fetch [--remote=name1,name2|--all] [--limit=N] [--stale-only]\n";
        echo "Refreshes local cache of remote snapshot metadata. Use --stale-only to update only stale caches.\n";
    }
}
