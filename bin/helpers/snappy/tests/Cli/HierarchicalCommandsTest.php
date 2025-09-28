<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class HierarchicalCommandsTest extends InProcessCliTestCase {
    public function testSnapshotCreateListShowVerifyAndPrune(): void {
        [$out1,$code1,$ctx] = $this->runInProcess('snapshot create -m first');
        $this->assertSame(0,$code1);
        $this->assertStringContainsString('created snapshot',$out1);
        $uid1 = trim(substr(strrchr(trim($out1),' '),1));
        [$out2,$code2] = $this->runInProcess('snapshot create -m second --compress');
        $this->assertSame(0,$code2);
        $uid2 = trim(substr(strrchr(trim($out2),' '),1));
        $this->assertNotSame($uid1,$uid2);
        [$list,$listCode] = $this->runInProcess('snapshot list');
        $this->assertSame(0,$listCode);
        $this->assertStringContainsString($uid1,$list);
        $this->assertStringContainsString($uid2,$list);
        [$show,$showCode] = $this->runInProcess('snapshot show '.$uid1);
        $this->assertSame(0,$showCode);
        $this->assertStringContainsString('UID:',$show);
        [$verify,$verifyCode] = $this->runInProcess('verify run '.$uid1);
        $this->assertSame(0,$verifyCode);
        // prune keep=0
        [$prune,$pruneCode] = $this->runInProcess('prune run --keep=0');
        $this->assertSame(0,$pruneCode);
        [$list2,$list2Code] = $this->runInProcess('snapshot list --no-index');
        $this->assertSame(0,$list2Code);
        $this->assertStringNotContainsString($uid1,$list2);
        $this->assertStringNotContainsString($uid2,$list2);
    }

    public function testConfigGetSetPersist(): void {
        [$root,$codeRoot] = $this->runInProcess('config get version');
        $this->assertSame(0,$codeRoot);
        $this->assertSame('1',trim($root));
        [$set,$setCode,$ctx] = $this->runInProcess('config set options.custom.value 123');
        $this->assertSame(0,$setCode);
        $this->assertStringContainsString('updated (in-memory)',$set);
        [$get,$getCode] = $this->runInProcess('config get options.custom.value');
        $this->assertSame(0,$getCode);
        $this->assertSame('123',trim($get));
        [$persist,$persistCode] = $this->runInProcess('config set options.custom.flag true --persist');
        $this->assertSame(0,$persistCode);
        $this->assertStringContainsString('persisted',$persist);
        [$flag,$flagCode] = $this->runInProcess('config get options.custom.flag');
        $this->assertSame(0,$flagCode);
        $this->assertSame('1',trim($flag));
    }

    public function testRouterMissingSubcommand(): void {
        [$out,$code] = $this->runInProcess('snapshot');
        $this->assertSame(1,$code);
        $this->assertTrue(str_contains($out,'Usage: tsnap snapshot') || str_contains($out,'Subcommands for snapshot'));
    }
}
