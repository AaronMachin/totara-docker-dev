<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotCreateTagsTest extends InProcessCliTestCase {
    private function uidFromCreate(string $out): string { $out = trim($out); $parts = explode(' ', $out); return end($parts); }

    public function testCreateWithSingleAndMultiTags(): void {
        [$out1,$code1,$ctx] = $this->runInProcess('snapshot create --tag=alpha -m one');
        $this->assertSame(0,$code1,$out1);
        $uid1 = $this->uidFromCreate($out1);
        $manifest1 = json_decode((string)file_get_contents($ctx->registry->local_base_path().'/snaps/'.$uid1.'/manifest-v2.json'),true);
        $this->assertSame(['alpha'],$manifest1['tags']);

        [$out2,$code2] = $this->runInProcess('snapshot create --tag=alpha --tag=beta --tags=beta,gamma,alpha -m two');
        $this->assertSame(0,$code2,$out2);
        $uid2 = $this->uidFromCreate($out2);
        $manifest2 = json_decode((string)file_get_contents($ctx->registry->local_base_path().'/snaps/'.$uid2.'/manifest-v2.json'),true);
        $this->assertSame(['alpha','beta','gamma'],$manifest2['tags']);
        // index reflects
        $entry2 = null; $idx = $ctx->index->load(); foreach ($idx['snapshots'] as $r) { if ($r['uid']===$uid2) { $entry2=$r; break; } }
        $this->assertSame(['alpha','beta','gamma'],$entry2['tags']);
    }

    public function testCreateWithInvalidTagFails(): void {
        [$out,$code] = $this->runInProcess('snapshot create --tag=BadTag -m invalid');
        $this->assertSame(4,$code,$out);
        $this->assertStringContainsString('invalid tag: BadTag',$out);
    }
}

