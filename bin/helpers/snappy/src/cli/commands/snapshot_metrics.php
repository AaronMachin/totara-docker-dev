<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_metrics extends base_command {
    public function name(): string { return 'snapshot.metrics'; }
    public function description(): string { return 'Show snapshot aggregate metrics (counts, sizes, newest, largest, compression)'; }
    public function usage(): string { return "Usage: tsnap snapshot metrics\nScans manifest-v2.json files only (no index dependency) to produce aggregate metrics."; }
    public function examples(): array { return [ 'tsnap snapshot metrics', 'tsnap snapshot metrics --json' ]; }
    public function metadata(): array { $m = parent::metadata(); $m['group']='Maintenance'; return $m; }

    public function run(array $args, context $ctx): int {
        $base = rtrim($ctx->registry->local_base_path(), '/');
        $snapsDir = $base . '/snaps';
        $totalSnapshots = 0; $totalBytes = 0; $compressedCount = 0;
        $newest = ['uid'=>null,'created'=>null];
        $largest = ['uid'=>null,'bytes'=>0];
        if (is_dir($snapsDir)) {
            $entries = @scandir($snapsDir) ?: [];
            foreach ($entries as $e) {
                if ($e==='.'||$e==='..' || $e==='index.json') { continue; }
                $manPath = $snapsDir.'/'.$e.'/manifest-v2.json';
                if (!is_file($manPath)) { continue; }
                $raw = @json_decode((string)@file_get_contents($manPath), true);
                if (!is_array($raw)) { continue; }
                $uid = $raw['uid'] ?? $e; if(!is_string($uid) || $uid==='') { $uid = $e; }
                $created = $raw['created_utc'] ?? ($raw['created'] ?? null);
                $size = (int)($raw['size_total_bytes'] ?? 0);
                $compression = $raw['compression']['enabled'] ?? false;
                $totalSnapshots++; $totalBytes += $size; if ($compression) { $compressedCount++; }
                if ($created && (!$newest['created'] || strcmp($created,$newest['created'])>0)) { $newest = ['uid'=>$uid,'created'=>$created]; }
                if ($size > $largest['bytes']) { $largest = ['uid'=>$uid,'bytes'=>$size]; }
            }
        }
        $averageSize = $totalSnapshots>0 ? (int)floor($totalBytes / $totalSnapshots) : 0;
        // Text output tables
        $ctx->out->table(['METRIC','VALUE'], [
            ['total_snapshots', (string)$totalSnapshots],
            ['total_bytes', (string)$totalBytes],
            ['average_size', (string)$averageSize],
            ['compressed_count', (string)$compressedCount],
        ]);
        $ctx->out->table(['NEWEST_UID','CREATED_UTC'], [ [ (string)($newest['uid'] ?? ''), (string)($newest['created'] ?? '') ] ]);
        $ctx->out->table(['LARGEST_UID','BYTES'], [ [ (string)($largest['uid'] ?? ''), (string)$largest['bytes'] ] ]);
        $payload = [
            'total_snapshots' => $totalSnapshots,
            'total_bytes' => $totalBytes,
            'average_size' => $averageSize,
            'compressed_count' => $compressedCount,
            'newest_uid' => $newest['uid'],
            'newest_created' => $newest['created'],
            'largest_uid' => $largest['uid'],
            'largest_bytes' => $largest['bytes'],
            'generated_utc' => gmdate('c'),
        ];
        $ctx->out->json($payload);
        return 0;
    }
}
