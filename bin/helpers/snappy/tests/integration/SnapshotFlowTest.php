<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/InProcessCliTestCase.php';

final class SnapshotFlowTest extends InProcessCliTestCase {
    private function uidFromCreate(string $out): string { $out = trim($out); $parts = explode(' ', $out); return end($parts); }

    public function testEndToEndLocalSnapshotFlow(): void {
        [$createOut,$createCode,$ctx] = $this->runInProcess('snapshot create -m flow');
        $this->assertSame(0,$createCode,$createOut);
        $uid = $this->uidFromCreate($createOut);
        $this->assertNotSame('', $uid, 'UID extracted');

        $manifestPath = $ctx->registry->local_base_path().'/snaps/'.$uid.'/manifest-v2.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame(2, $manifest['schema_version'] ?? null);
        $this->assertSame('flow', $manifest['message'] ?? null);
        $this->assertSame([], $manifest['tags'] ?? []);

        [$listOut,$listCode] = $this->runInProcess('snapshot list --limit=10');
        $this->assertSame(0,$listCode,$listOut);
        $this->assertStringContainsString($uid, $listOut);

        [$tagOut,$tagCode] = $this->runInProcess('snapshot tag '.$uid.' add flowtag');
        $this->assertSame(0,$tagCode,$tagOut);
        $manifest2 = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertSame(['flowtag'], $manifest2['tags'] ?? []);

        [$verifyOut,$verifyCode] = $this->runInProcess('verify run '.$uid);
        $this->assertSame(0,$verifyCode,$verifyOut);
        $this->assertStringContainsString('verified '.$uid.' OK', $verifyOut);
    }
}

