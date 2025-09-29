<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class GcTempTest extends InProcessCliTestCase {
    public function testDryRunAndApply(): void {
        // init context
        [$out,$code,$ctx] = $this->runInProcess('snapshot list');
        $this->assertSame(0,$code,$out);
        $base = $this->tmpRoot . '/root';
        $tmpBase = $base . '/tmp';
        @mkdir($tmpBase,0777,true);
        $oldDir = $tmpBase . '/old1'; @mkdir($oldDir,0777,true); @touch($oldDir, time() - 48*3600);
        $newDir = $tmpBase . '/new1'; @mkdir($newDir,0777,true); @touch($newDir, time());
        [$createOut,$createCode] = $this->runInProcess('snapshot create -m s1');
        $this->assertSame(0,$createCode,$createOut);
        $parts = preg_split('/\s+/', trim($createOut)); $uid = end($parts);
        $snapDir = $base . '/snaps/' . $uid; $tmpFile = $snapDir . '/' . $uid . '.tar.gz.tmp'; file_put_contents($tmpFile, 'x'); @touch($tmpFile, time()-7200);
        // Dry run (reuse context)
        [$dryOut,$dryCode] = $this->runInProcess('gc temp --dry-run');
        $this->assertSame(0,$dryCode,$dryOut);
        $this->assertDirectoryExists($oldDir);
        $this->assertFileExists($tmpFile);
        // Apply (reuse context)
        [$applyOut,$applyCode] = $this->runInProcess('gc temp');
        $this->assertSame(0,$applyCode,$applyOut);
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertFileDoesNotExist($tmpFile);
        $this->assertDirectoryExists($newDir);
    }
}
