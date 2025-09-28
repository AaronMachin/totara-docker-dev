<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_metrics extends base_command {
    public function name(): string { return 'snapshot.metrics'; }
    public function description(): string { return 'Show snapshot metrics (counts, total size, tags, age buckets) from local index'; }
    public function usage(): string { return "Usage: tsnap snapshot metrics\nShows aggregate metrics derived from local snapshot index."; }
    public function examples(): array { return [
        'tsnap snapshot metrics',
        'tsnap snapshot metrics --json'
    ]; }

    public function run(array $args, context $ctx): int {
        $index = $ctx->index->load();
        if (!$index) { // attempt rebuild once then reload
            $ctx->index->rebuild();
            $index = $ctx->index->load(false);
        }
        $snapshots = $index['snapshots'] ?? [];
        $total = count($snapshots);
        $totalSize = 0;
        $tagCounts = [];
        $buckets = ['lt_1d'=>0,'d1_7'=>0,'d8_30'=>0,'gt_30d'=>0];
        $now = time();
        foreach ($snapshots as $row) {
            $totalSize += (int)($row['size_total_bytes'] ?? 0);
            $tags = $row['tags'] ?? [];
            if (is_array($tags)) {
                // de-dup per snapshot
                $uniq = array_values(array_unique(array_filter(array_map('strval',$tags),'strlen')));
                foreach ($uniq as $t) { $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1; }
            }
            $createdIso = $row['created_utc'] ?? '';
            $ts = $createdIso ? strtotime($createdIso) : null;
            if ($ts) {
                $days = (int)floor(($now - $ts) / 86400);
                if ($days < 1) { $buckets['lt_1d']++; }
                elseif ($days <= 7) { $buckets['d1_7']++; }
                elseif ($days <= 30) { $buckets['d8_30']++; }
                else { $buckets['gt_30d']++; }
            }
        }
        ksort($tagCounts, SORT_NATURAL | SORT_FLAG_CASE);
        // Text tables
        $ctx->out->table(['METRIC','VALUE'], [
            ['snapshots_total', (string)$total],
            ['size_total_bytes', (string)$totalSize],
        ]);
        $ctx->out->table(['AGE_BUCKET','COUNT'], [
            ['<1d', (string)$buckets['lt_1d']],
            ['1-7d', (string)$buckets['d1_7']],
            ['8-30d', (string)$buckets['d8_30']],
            ['>30d', (string)$buckets['gt_30d']],
        ]);
        $tagRows = [];
        if ($tagCounts) {
            // sort descending counts then alpha
            uasort($tagCounts, function($a,$b) use ($tagCounts){ return $b <=> $a; });
            foreach ($tagCounts as $tag => $cnt) { $tagRows[] = [$tag, (string)$cnt]; }
        }
        $ctx->out->table(['TAG','COUNT'], $tagRows ?: [['(none)','0']]);
        $payload = [
            'snapshots_total' => $total,
            'size_total_bytes' => $totalSize,
            'age_buckets' => [
                'lt_1d' => $buckets['lt_1d'],
                'd1_7' => $buckets['d1_7'],
                'd8_30' => $buckets['d8_30'],
                'gt_30d' => $buckets['gt_30d'],
            ],
            'tags' => $tagCounts,
            'generated_utc' => $index['generated_utc'] ?? null,
            'now_utc' => gmdate('c'),
        ];
        $ctx->out->json($payload);
        return 0;
    }
}

