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
        $out = $this->runCli('--json snapshot metrics', $code);
        $this->assertSame(0,$code,$out);
        $decoded = json_decode($out,true);
        $this->assertIsArray($decoded);
        $payload = $decoded['data']['payload'];
        $this->assertSame(0, $payload['total_snapshots']);
        $this->assertSame(0, $payload['total_bytes']);
        $this->assertSame(0, $payload['average_size']);
        $this->assertSame(0, $payload['compressed_count']);
        $this->assertNull($payload['newest_uid']);
        $this->assertNull($payload['largest_uid']);
    }

    public function testAggregateMetrics(): void {
        $uid1 = $this->createSnapshot('a1');
        $uid2 = $this->createSnapshot('a2');
        // mutate size_total_bytes for second snapshot by appending to backup.sql(.gz?)
        $snapDir = $this->tmpRoot . '/snaps';
        foreach ([$uid1,$uid2] as $u) {
            $manPath = $snapDir . '/' . $u . '/manifest-v2.json';
            $raw = json_decode((string)@file_get_contents($manPath), true);
            $this->assertIsArray($raw);
            // ensure differing created_utc ordering
            if ($u === $uid1) { $raw['created_utc'] = gmdate('Y-m-d\TH:i:s\Z', time()-60); }
            else { $raw['created_utc'] = gmdate('Y-m-d\TH:i:s\Z', time()); }
            file_put_contents($manPath, json_encode($raw, JSON_PRETTY_PRINT));
        }
        $out = $this->runCli('--json snapshot metrics', $code);
        $this->assertSame(0,$code,$out);
        $decoded = json_decode($out,true);
        $payload = $decoded['data']['payload'];
        $this->assertSame(2, $payload['total_snapshots']);
        $this->assertIsInt($payload['total_bytes']);
        $this->assertGreaterThanOrEqual(0, $payload['total_bytes']);
        $this->assertSame($payload['total_bytes']>0 ? (int)floor($payload['total_bytes']/2) : 0, $payload['average_size']);
        $this->assertSame($payload['newest_uid'], $uid2); // second snapshot newer
        $this->assertContains($payload['largest_uid'], [$uid1,$uid2]);
    }
}
