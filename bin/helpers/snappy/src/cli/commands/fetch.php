<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Throwable;
use Snappy\Snapshot\remote_snapshot_cache;

class fetch extends base_command {
    public function name(): string { return 'fetch'; }
    public function description(): string { return 'Fetch (refresh) remote snapshot metadata cache'; }
    public function usage(): string { return 'Usage: tsnap fetch [--remote=name1,name2|--all] [--limit=N] [--stale-only]
Refresh local cache of remote snapshot metadata. Without --remote or --all it fetches all non-local remotes.'; }
    public function examples(): array { return ['tsnap fetch --all','tsnap fetch --remote=origin,backup','tsnap fetch --stale-only','tsnap fetch --limit=500 --all']; }

    public function run(array $args, context $ctx): int {
        [$opts, $errors] = $this->parseFetchArgs($args);
        if ($errors) { foreach ($errors as $e) { fwrite(STDERR, $e."\n"); } return 1; }
        $remote_csv = $opts['remote'];
        $limit = $opts['limit'];
        $all = $opts['all'];
        $staleOnly = $opts['stale'];
        $names = $this->determineRemotes($remote_csv, $all, $ctx);
        if ($names === null) { fwrite(STDERR, "No non-local remotes to fetch. Use tsnap remote add ... first.\n"); return 0; }
        $stats = $this->updateCaches($names, $staleOnly, $limit, $ctx);
        echo "Updated {$stats['updated']} remote cache(s)" . ($staleOnly?" (stale-only)":"") . ". Skipped fresh: {$stats['skipped']}. Changed: +{$stats['fetched']} -{$stats['removed']}. Total cached snapshots: {$stats['totalSnapshots']}\n";
        return 0;
    }

    private function parseFetchArgs(array $argv): array {
        $def = [
            'remote'=>['prefix'=>'--remote=','type'=>'string','default'=>null],
            'limit'=>['prefix'=>'--limit=','type'=>'int','default'=>100],
            'all'=>['flags'=>['--all'],'type'=>'bool','default'=>false],
            'stale'=>['flags'=>['--stale-only'],'type'=>'bool','default'=>false],
        ];
        $parsed = $this->parseArgs($argv, $def);
        return [
            [
                'remote'=>$parsed['options']['remote'],
                'limit'=>$parsed['options']['limit'],
                'all'=>$parsed['options']['all'],
                'stale'=>$parsed['options']['stale'],
            ],
            $parsed['errors']
        ];
    }

    private function determineRemotes(?string $remoteCsv, bool $all, context $ctx): ?array {
        if ($remoteCsv !== null) {
            $parts = array_filter(array_map('trim', explode(',', $remoteCsv)), 'strlen');
            $names = [];
            foreach ($parts as $p) {
                if ($p === 'local') { continue; }
                if (!$ctx->registry->has($p)) { fwrite(STDERR, "unknown remote: $p\n"); return []; }
                $names[] = $p;
            }
            return $names ?: null;
        }
        if ($all || $remoteCsv === null) {
            $names = array_filter($ctx->registry->names(), fn($n)=>$n!=='local');
            return $names ?: null;
        }
        return null;
    }

    private function updateCaches(array $names, bool $staleOnly, int $limit, context $ctx): array {
        $totalFetched=0; $totalRemoved=0; $totalSnapshots=0; $updated=0; $skipped=0;
        foreach ($names as $r) {
            if ($staleOnly) {
                $c = $ctx->cache->load($r);
                if ($c && !remote_snapshot_cache::is_stale($c['fetched_at'], $ctx->registry)) { $skipped++; continue; }
            }
            try {
                $stats = $ctx->cache->update_remote($r, $ctx->registry, $ctx->manager, $limit*20);
                $totalFetched += $stats['fetched'];
                $totalRemoved += $stats['removed'];
                $totalSnapshots += $stats['total'];
                $updated++;
                echo "Fetched $r: +{$stats['fetched']} / -{$stats['removed']} (total {$stats['total']})\n";
            } catch (Throwable $e) {
                fwrite(STDERR, "fetch failed for $r: " . $e->getMessage() . "\n");
            }
        }
        return [
            'fetched'=>$totalFetched,
            'removed'=>$totalRemoved,
            'totalSnapshots'=>$totalSnapshots,
            'updated'=>$updated,
            'skipped'=>$skipped,
        ];
    }
}
