<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Snapshot\integrity_service;
use Snappy\Snapshot\VerificationResult;

final class IntegrityServiceTest extends TestCase
{
    private integrity_service $svc;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->svc = new integrity_service();
        $this->tmpDir = sys_get_temp_dir() . '/snappy_int_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) { if ($file->isDir()) { @rmdir($file->getPathname()); } else { @unlink($file->getPathname()); } }
            @rmdir($this->tmpDir);
        }
    }

    public function testHashFileDeterministicAndEmpty(): void
    {
        $f = $this->tmpDir . '/empty.txt';
        file_put_contents($f, '');
        $h1 = $this->svc->hashFile($f);
        $h2 = $this->svc->hashFile($f);
        self::assertSame($h1, $h2);
        self::assertSame(hash('sha256', ''), $h1);
    }

    public function testLargeFileStreamingStable(): void
    {
        $f = $this->tmpDir . '/big.bin';
        // ~3MB content
        $chunk = random_bytes(1024);
        $data = str_repeat($chunk, 3000); // 3MB
        file_put_contents($f, $data);
        $expected = hash('sha256', $data);
        $actual = $this->svc->hashFile($f);
        self::assertSame($expected, $actual);
    }

    public function testManifestHashCanonicalization(): void
    {
        $a = ['b'=>1,'a'=>['y'=>2,'x'=>1]];
        $b = ['a'=>['x'=>1,'y'=>2],'b'=>1]; // different key order
        $hA = $this->svc->hashManifest($a);
        $hB = $this->svc->hashManifest($b);
        self::assertSame($hA, $hB);
    }

    public function testArtifactLinesHashDeterministic(): void
    {
        $lines = ['first','second','third'];
        $h1 = $this->svc->artifactLinesHash($lines);
        $h2 = $this->svc->artifactLinesHash($lines);
        self::assertSame($h1, $h2);
        self::assertSame(hash('sha256', implode("\n", $lines)), $h1);
    }

    public function testVerifyFilesDetectsTamper(): void
    {
        $f1 = $this->tmpDir . '/one.txt';
        $f2 = $this->tmpDir . '/two.txt';
        file_put_contents($f1, 'alpha');
        file_put_contents($f2, 'beta');
        $exp = [ 'one.txt' => hash('sha256', 'alpha'), 'two.txt' => hash('sha256', 'beta') ];
        $result = $this->svc->verifyFiles($exp, $this->tmpDir);
        self::assertTrue($result->ok);
        // tamper second
        file_put_contents($f2, 'BETa');
        $result2 = $this->svc->verifyFiles($exp, $this->tmpDir);
        self::assertFalse($result2->ok);
        self::assertArrayHasKey('two.txt', $result2->failures);
        self::assertSame($exp['two.txt'], $result2->failures['two.txt']['expected']);
    }

    public function testShortVerificationCodeFormat(): void
    {
        $hash = str_repeat('ab', 32); // 64 hex chars
        $code = $this->svc->shortVerificationCode($hash);
        // Expect 5 groups of 4 chars lower-case base32 maybe with digits 2-7, separated by '-'
        self::assertMatchesRegularExpression('/^[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}$/', $code);
        // Deterministic
        self::assertSame($code, $this->svc->shortVerificationCode($hash));
    }
}

