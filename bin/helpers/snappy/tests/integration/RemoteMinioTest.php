<?php
declare(strict_types=1);

/**
 * T10.3 Remote Push/Pull Integration (MinIO S3)
 * Skips when docker not available or SNAPPY_REMOTE_TESTS not explicitly enabled.
 */
require_once __DIR__ . '/../Cli/InProcessCliTestCase.php';

final class RemoteMinioTest extends InProcessCliTestCase {
    private string $containerName = '';
    private int $hostPort = 0;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('SNAPPY_REMOTE_TESTS') !== '1') { $this->markTestSkipped('SNAPPY_REMOTE_TESTS=1 not set'); }
        if (!$this->hasDocker()) { $this->markTestSkipped('docker not available'); }
        $this->hostPort = $this->randomPort();
        $this->containerName = 'snappy_minio_' . bin2hex(random_bytes(4));
        $cmd = sprintf('docker run -d --rm --name %s -p %d:9000 -e MINIO_ROOT_USER=snappy -e MINIO_ROOT_PASSWORD=snappy123 minio/minio server /data 2>&1', escapeshellarg($this->containerName), $this->hostPort);
        exec($cmd, $out, $code);
        if ($code !== 0) { $this->markTestSkipped('failed to start minio container: '.implode("\n", $out)); }
        // Wait for health endpoint
        $ready = false; $deadline = time()+30; $url = 'http://127.0.0.1:'.$this->hostPort.'/minio/health/ready';
        while(time() < $deadline) { if ($this->isHealthy($url)) { $ready = true; break; } usleep(300000); }
        if (!$ready) { $this->tearDownContainer(); $this->markTestSkipped('minio not ready'); }
    }

    protected function tearDown(): void {
        $this->tearDownContainer();
        parent::tearDown();
    }

    private function hasDocker(): bool { exec('command -v docker 2>/dev/null', $o, $c); return $c===0; }
    private function randomPort(): int { return random_int(20000, 40000); }
    private function isHealthy(string $url): bool { $ctx = stream_context_create(['http'=>['timeout'=>1]]); $data = @file_get_contents($url,false,$ctx); return $data !== false; }
    private function tearDownContainer(): void { if ($this->containerName!=='') { exec('docker rm -f '.escapeshellarg($this->containerName).' 2>/dev/null'); $this->containerName=''; } }

    public function testPushPullRoundTrip(): void {
        // Add remote via CLI
        $remoteName = 'rminio';
        $endpoint = 'http://127.0.0.1:'.$this->hostPort;
        [$addOut,$addCode] = $this->runInProcess(sprintf('remote add %s s3 --endpoint=%s --bucket=snappytest --key=snappy --secret=snappy123 --region=us-east-1 --path-style', $remoteName, $endpoint));
        $this->assertSame(0,$addCode,$addOut);
        // Create snapshot locally
        [$createOut,$createCode,$ctx] = $this->runInProcess('snapshot create -m remotetest');
        $this->assertSame(0,$createCode,$createOut);
        $uid = trim(substr($createOut, strrpos(trim($createOut), ' ')+1));
        $metaPath = $ctx->registry->local_base_path().'/snaps/'.$uid.'/meta.json';
        $this->assertFileExists($metaPath);
        $originalMeta = json_decode((string)file_get_contents($metaPath), true);
        $this->assertIsArray($originalMeta);
        $origChecksums = $originalMeta['file_checksums'] ?? [];
        $this->assertNotEmpty($origChecksums);
        // Push using manager API
        $count = $ctx->manager->push($uid, $remoteName, 'local');
        $this->assertGreaterThanOrEqual(count($origChecksums)+1, $count, 'push count includes files + meta');
        // Remove local snapshot directory to force pull
        $localDir = $ctx->registry->local_base_path().'/snaps/'.$uid;
        $this->assertDirectoryExists($localDir);
        // inline recursive delete (cannot use parent's private helper)
        $this->recursiveDelete($localDir);
        $this->assertDirectoryDoesNotExist($localDir);
        // Pull
        $pulledUid = $ctx->manager->pull($uid, $remoteName, false);
        $this->assertSame($uid, $pulledUid);
        $meta2 = json_decode((string)file_get_contents($ctx->registry->local_base_path().'/snaps/'.$uid.'/meta.json'), true);
        $this->assertSame($origChecksums, $meta2['file_checksums'] ?? []);
        // Verify checksum of a file
        foreach ($origChecksums as $file => $hash) { $this->assertFileExists($localDir.'/'.$file); $this->assertSame($hash, hash_file('sha256',$localDir.'/'.$file)); break; }
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
