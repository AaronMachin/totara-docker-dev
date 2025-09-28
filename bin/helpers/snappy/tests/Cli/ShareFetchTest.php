<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class ShareFetchTest extends AbstractCliTestCase {
    private function createSnapshotAndShare(string $expire = '1h'): array {
        $out = $this->runCli('--json snapshot create -m fetchtest', $c1);
        $this->assertSame(0, $c1);
        $uid = (json_decode($out, true)['data']['payload']['uid']) ?? '';
        $this->assertNotSame('', $uid);
        $share = $this->runCli('--json share create ' . $uid . ' --expire=' . $expire, $c2);
        $this->assertSame(0, $c2);
        $decoded = json_decode($share, true);
        $token = $decoded['data']['payload']['share_token'] ?? '';
        $this->assertNotSame('', $token);
        return [$uid, $token];
    }

    public function testFetchConsumesToken(): void {
        [$uid, $token] = $this->createSnapshotAndShare();
        $fetch = $this->runCli('--json share fetch ' . $token, $c1);
        $this->assertSame(0, $c1, 'first fetch should succeed');
        $decoded = json_decode($fetch, true);
        $this->assertSame('ok', $decoded['status']);
        $payload = $decoded['data']['payload'];
        $this->assertSame($uid, $payload['uid']);
        $this->assertDirectoryExists($payload['path']);
        // second fetch should fail (single-use)
        $fetch2 = $this->runCli('--json share fetch ' . $token, $c2);
        $this->assertSame(3, $c2, 'second fetch must fail with error exit');
        $decoded2 = json_decode($fetch2, true);
        $this->assertSame('error', $decoded2['status']);
    }

    public function testFetchRejectsBadFormat(): void {
        $out = $this->runCli('--json share fetch short', $code); // too short (<10 chars)
        $this->assertSame(2, $code);
        $decoded = json_decode($out, true); $this->assertSame('error', $decoded['status']);
    }
}
