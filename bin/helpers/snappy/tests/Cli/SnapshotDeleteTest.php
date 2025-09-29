<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class SnapshotDeleteTest extends AbstractCliTestCase {
    private function createSnapshot(string $msg='m'): string {
        $out = $this->runCli('snapshot create -m ' . escapeshellarg($msg), $code);
        $this->assertSame(0,$code,$out);
        $parts = preg_split('/\s+/', trim($out));
        return end($parts);
    }

    public function testDeleteSuccessRemovesDirectoryAndIndex(): void {
        $uid1 = $this->createSnapshot('first');
        $uid2 = $this->createSnapshot('second');
        $snapDir1 = $this->tmpRoot . '/snaps/' . $uid1;
        $this->assertDirectoryExists($snapDir1);
        $out = $this->runCli('snapshot delete ' . $uid1, $code);
        $this->assertSame(0,$code,$out);
        $this->assertDirectoryDoesNotExist($snapDir1);
        $list = $this->runCli('snapshot list', $listCode);
        $this->assertSame(0,$listCode,$list);
        $this->assertStringNotContainsString($uid1,$list);
        $this->assertStringContainsString($uid2,$list);
    }

    public function testAmbiguousPrefix(): void {
        $uids = [];
        $byFirst = [];
        for ($i=0;$i<20;$i++) { // up to 20 attempts to ensure collision on first char
            $u = $this->createSnapshot('amb'.$i);
            $uids[] = $u;
            $first = $u[0];
            $byFirst[$first] = ($byFirst[$first] ?? 0) + 1;
            if ($byFirst[$first] >= 2) { $prefix = $first; break; }
        }
        if (!isset($prefix)) { $this->markTestSkipped('Could not produce ambiguous prefix'); }
        $out = $this->runCli('snapshot delete ' . $prefix, $code);
        $this->assertSame(64,$code,$out);
        foreach($uids as $u){ $this->assertDirectoryExists($this->tmpRoot . '/snaps/' . $u); }
    }

    public function testNotFound(): void {
        $out = $this->runCli('snapshot delete zzzzdoesnotexist', $code);
        $this->assertSame(2,$code,$out);
        $this->assertStringContainsString('not found',$out);
    }

    public function testIdempotentSecondDelete(): void {
        $uid = $this->createSnapshot('x');
        $out1 = $this->runCli('snapshot delete ' . $uid, $code1);
        $this->assertSame(0,$code1,$out1);
        $out2 = $this->runCli('snapshot delete ' . $uid, $code2);
        $this->assertSame(2,$code2,$out2);
    }

    public function testJsonOutput(): void {
        $uid = $this->createSnapshot('json');
        $out = $this->runCli('--json snapshot delete ' . $uid, $code);
        $this->assertSame(0,$code,$out);
        $decoded = json_decode($out,true);
        $this->assertIsArray($decoded);
        $this->assertSame('snapshot.delete',$decoded['command']);
        $payload = $decoded['data']['payload'] ?? [];
        $this->assertSame($uid, $payload['deleted_uid'] ?? null);
        $this->assertTrue($payload['index_pruned'] ?? false);
    }
}
