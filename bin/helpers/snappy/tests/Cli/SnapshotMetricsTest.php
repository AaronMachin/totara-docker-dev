<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

use Snappy\Snapshot\index_manager; // for direct rebuild after timestamp edits

final class SnapshotMetricsTest extends AbstractCliTestCase {
    private function createSnapshot(string $msg = 'm'): string {
        $out = $this->runCli('snapshot create -m ' . escapeshellarg($msg), $code);
        $this->assertSame(0,$code,$out);
        // output line ends with uid
        $parts = preg_split('/\s+/', trim($out));
        return end($parts);
    }

    public function testEmptyMetrics(): void {
        // ensure snaps dir exists but empty
        // run metrics
        $out = $this->runCli('--json snapshot metrics', $code);
        $this->assertSame(0,$code,$out);
        $decoded = json_decode($out,true);
        $this->assertIsArray($decoded);
        $payload = $decoded['data']['payload'];
        $this->assertSame(0, $payload['snapshots_total']);
        $this->assertSame(0, $payload['size_total_bytes']);
        $this->assertEquals(['lt_1d'=>0,'d1_7'=>0,'d8_30'=>0,'gt_30d'=>0], $payload['age_buckets']);
    }

    public function testAgeBucketsAndTags(): void {
        $uid1 = $this->createSnapshot('s1'); // will become <1d
        $uid2 = $this->createSnapshot('s2'); // 2d
        $uid3 = $this->createSnapshot('s3'); // 15d
        $uid4 = $this->createSnapshot('s4'); // 45d
        // Tag some snapshots
        $this->runCli('snapshot tag ' . $uid1 . ' add alpha');
        $this->runCli('snapshot tag ' . $uid2 . ' add alpha');
        $this->runCli('snapshot tag ' . $uid3 . ' add beta');
        // Determine snapshot parent/root (tmpRoot parent contains /snaps directory)
        $snapParent = $this->tmpRoot; // AbstractCliTestCase sets snapshots under $tmpRoot/snaps
        $snapDir = $snapParent . '/snaps';
        $map = [
            $uid1 => strtotime('-10 minutes'),
            $uid2 => strtotime('-2 days'),
            $uid3 => strtotime('-15 days'),
            $uid4 => strtotime('-45 days'),
        ];
        foreach ($map as $uid => $ts) {
            $manifest = $snapDir . '/' . $uid . '/manifest-v2.json';
            $raw = json_decode((string)@file_get_contents($manifest), true);
            $this->assertIsArray($raw, 'manifest must load for '.$uid);
            $raw['created_utc'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
            file_put_contents($manifest, json_encode($raw, JSON_PRETTY_PRINT));
        }
        // Rebuild index using parent path
        $idx = new index_manager($snapParent);
        $idx->rebuild();

        $out = $this->runCli('--json snapshot metrics', $code);
        $this->assertSame(0,$code,$out);
        $decoded = json_decode($out,true);
        $this->assertIsArray($decoded);
        $payload = $decoded['data']['payload'];
        $this->assertSame(4, $payload['snapshots_total']);
        $ages = $payload['age_buckets'];
        $this->assertSame(1, $ages['lt_1d']);
        $this->assertSame(1, $ages['d1_7']);
        $this->assertSame(1, $ages['d8_30']);
        $this->assertSame(1, $ages['gt_30d']);
        $tags = $payload['tags'];
        $this->assertArrayHasKey('alpha', $tags);
        $this->assertArrayHasKey('beta', $tags);
        $this->assertSame(2, $tags['alpha']);
        $this->assertSame(1, $tags['beta']);
    }
}
