<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class ShareArchiveTest extends AbstractCliTestCase {
    public function testArchiveCreatedByDefault(): void {
        $out = $this->runCli('--json snapshot create -m archivetest', $c1);
        $this->assertSame(0, $c1);
        $uid = (json_decode($out, true)['data']['payload']['uid']) ?? '';
        $this->assertNotSame('', $uid);
        $share = $this->runCli('--json share create ' . $uid, $c2);
        $this->assertSame(0, $c2);
        $decoded = json_decode($share, true);
        $payload = $decoded['data']['payload'] ?? [];
        $archivePath = $payload['archive_path'] ?? null;
        $this->assertNotNull($archivePath, 'archive_path should be present in share create output');
        $this->assertFileExists($archivePath, 'archive file must exist');
        $this->assertGreaterThan(0, filesize($archivePath));
        $checksumFile = $archivePath . '.sha256';
        $this->assertFileExists($checksumFile, 'checksum file must exist');
        $line = trim(file_get_contents($checksumFile));
        $parts = preg_split('/\s+/', $line);
        $this->assertGreaterThanOrEqual(2, count($parts));
        $hash = $parts[0];
        $this->assertSame($hash, hash_file('sha256', $archivePath), 'checksum must match actual archive hash');
        // Validate tokens meta contains archive fields
        $sharesFile = $this->tmpRoot . '/.snappy/shares.json';
        $this->assertFileExists($sharesFile);
        $sharesJson = json_decode((string)file_get_contents($sharesFile), true);
        $found = null; foreach ($sharesJson['tokens'] as $t) { if (($t['uid'] ?? '') === $uid) { $found = $t; break; } }
        $this->assertNotNull($found, 'token entry must exist');
        $meta = $found['meta'] ?? [];
        $this->assertArrayHasKey('archive_path', $meta);
        $this->assertArrayHasKey('archive_checksum', $meta);
        $this->assertArrayHasKey('archive_size_bytes', $meta);
    }

    public function testNoArchiveFlagSkipsArchive(): void {
        $out = $this->runCli('--json snapshot create -m noarch', $c1);
        $this->assertSame(0, $c1);
        $uid = (json_decode($out, true)['data']['payload']['uid']) ?? '';
        $share = $this->runCli('--json share create ' . $uid . ' --no-archive', $c2);
        $this->assertSame(0, $c2);
        $decoded = json_decode($share, true);
        $payload = $decoded['data']['payload'] ?? [];
        $this->assertArrayNotHasKey('archive_path', $payload, 'archive_path should be absent when --no-archive used');
        $sharesFile = $this->tmpRoot . '/.snappy/shares.json';
        $sharesJson = json_decode((string)file_get_contents($sharesFile), true);
        $found = null; foreach ($sharesJson['tokens'] as $t) { if (($t['uid'] ?? '') === $uid) { $found = $t; break; } }
        $meta = $found['meta'] ?? [];
        $this->assertArrayNotHasKey('archive_path', $meta);
    }
}

