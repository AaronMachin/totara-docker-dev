<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class EdgeCasesTest extends InProcessCliTestCase {
    public function testLegacyCommandNameRemoved(): void {
        [$out,$code] = $this->runInProcess('snap -m test');
        $this->assertSame(1,$code);
    }
    public function testVerifyDetectsCorruption(): void {
        [$create,$createCode] = $this->runInProcess('snapshot create -m verifytest');
        $this->assertSame(0,$createCode);
        $uid = trim(substr(strrchr(trim($create),' '),1));
        [$ignored,$ignoredCode,$ctx] = $this->runInProcess('config get options.snapshot_root');
        $snapDir = $ctx->registry->local_base_path() . '/snaps/' . $uid;
        $file = $snapDir . '/backup.sql';
        $this->assertFileExists($file);
        file_put_contents($file, "CORRUPTED\n", FILE_APPEND);
        [$verify,$verifyCode] = $this->runInProcess('verify run ' . $uid);
        $this->assertSame(2,$verifyCode); // ValidationException exit code
    }
    public function testSnapshotShowUnknown(): void {
        [$show,$code] = $this->runInProcess('snapshot show doesnotexist');
        $this->assertSame(3,$code);
    }
    public function testConfigGetMissingPath(): void {
        [$out,$code] = $this->runInProcess('config get options.nonexistent.path');
        $this->assertSame(2,$code);
    }
    public function testPruneKeepGreaterThanCount(): void {
        $this->runInProcess('snapshot create -m one');
        $this->runInProcess('snapshot create -m two');
        [$prune,$code] = $this->runInProcess('prune run --keep=10');
        $this->assertSame(0,$code);
        $this->assertStringContainsString('Nothing to prune',$prune);
    }
}
