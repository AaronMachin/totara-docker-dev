<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class SnapshotApplyTest extends AbstractCliTestCase {
    private function createSnapshot(string $msg='m', bool $compress=false): string {
        $cmd = 'snapshot create ' . ($compress ? '--compress ' : '') . '-m ' . escapeshellarg($msg);
        $out = $this->runCli($cmd, $code);
        $this->assertSame(0,$code,$out);
        $parts = preg_split('/\s+/', trim($out));
        return end($parts);
    }

    public function testApplySuccess(): void {
        $uid = $this->createSnapshot('apply-ok');
        $out = $this->runCli('snapshot apply '.$uid, $code);
        $this->assertSame(0,$code,$out);
        $this->assertStringContainsString('applied '.$uid, $out);
    }

    public function testApplyAlias(): void {
        $uid = $this->createSnapshot('alias');
        $out = $this->runCli('apply '.$uid, $code);
        $this->assertSame(0,$code,$out);
        $this->assertStringContainsString('applied '.$uid, $out);
    }

    public function testApplyJsonOutput(): void {
        $uid = $this->createSnapshot('json');
        $out = $this->runCli('--json snapshot apply '.$uid, $code);
        $this->assertSame(0,$code,$out);
        $dec = json_decode($out,true);
        $this->assertIsArray($dec);
        $this->assertSame('snapshot.apply',$dec['command']);
        $payload = $dec['data']['payload'] ?? [];
        $this->assertSame($uid, $payload['applied_uid'] ?? null);
        $this->assertIsInt($payload['applied_bytes'] ?? null);
        $this->assertIsArray($payload['files_used'] ?? null);
        $this->assertSame(1, $payload['command_version'] ?? 0);
    }

    public function testApplyCompressedSnapshot(): void {
        $uid = $this->createSnapshot('compressed', true);
        // Ensure compressed file exists
        $snapDir = $this->tmpRoot . '/snaps/' . $uid;
        $this->assertFileExists($snapDir.'/backup.sql.gz');
        $this->assertFileDoesNotExist($snapDir.'/backup.sql');
        $out = $this->runCli('snapshot apply '.$uid, $code);
        $this->assertSame(0,$code,$out);
        $this->assertStringContainsString('applied '.$uid, $out);
    }

    public function testNotFound(): void {
        $out = $this->runCli('snapshot apply doesnotexist', $code);
        $this->assertSame(2,$code,$out);
        $this->assertStringContainsString('not found', $out);
    }

    public function testAmbiguousPrefix(): void {
        $uids = []; $byFirst = [];
        for ($i=0;$i<30;$i++) {
            $u = $this->createSnapshot('amb'.$i);
            $uids[] = $u; $first = $u[0]; $byFirst[$first] = ($byFirst[$first] ?? 0) + 1;
            if ($byFirst[$first] >= 2) { $prefix = $first; break; }
        }
        if (!isset($prefix)) { $this->markTestSkipped('Could not produce ambiguous prefix'); }
        $out = $this->runCli('snapshot apply '.$prefix, $code);
        $this->assertSame(64,$code,$out);
        foreach ($uids as $u) { $this->assertDirectoryExists($this->tmpRoot.'/snaps/'.$u); }
    }

    public function testUnsupportedType(): void {
        $uid = $this->createSnapshot('will-edit');
        $man = $this->tmpRoot.'/snaps/'.$uid.'/manifest-v2.json';
        $raw = json_decode(file_get_contents($man), true);
        $raw['snapshot_type'] = 'other';
        file_put_contents($man, json_encode($raw, JSON_PRETTY_PRINT));
        $out = $this->runCli('snapshot apply '.$uid, $code);
        $this->assertSame(2,$code,$out);
        $this->assertStringContainsString('unsupported', $out);
    }

    public function testProviderFailureBubbles(): void {
        $uid = $this->createSnapshot('willfail');
        $out = $this->runCli('snapshot apply '.$uid, $code, 'SNAPPY_FAKE_DUMP_APPLY_FAIL=1');
        $this->assertSame(5,$code,$out);
        $this->assertStringContainsString('simulated apply failure', $out);
    }
}

