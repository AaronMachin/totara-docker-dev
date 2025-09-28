<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/InProcessCliTestCase.php';

final class ShareTokenFlowIntegrationTest extends InProcessCliTestCase {
    private function extractUid(string $out): string {
        foreach (explode("\n", $out) as $l) { $l=trim($l); if (str_starts_with($l,'created snapshot ')) { return trim(substr($l,17)); } }
        $parts = preg_split('/\s+/', trim($out)); return $parts? end($parts):'';
    }
    private function extractToken(string $out): string {
        foreach (explode("\n", $out) as $l) { if (stripos($l,'Share token')!==false) { $parts = preg_split('/\s+/', trim($l)); return $parts? end($parts):''; } }
        return '';
    }

    public function testSingleUse(): void {
        [$createOut,$createCode] = $this->runInProcess('snapshot create -m flowshare');
        $this->assertSame(0,$createCode,$createOut);
        $uid = $this->extractUid($createOut); $this->assertNotSame('', $uid);
        [$shareOut,$shareCode] = $this->runInProcess('share create '.$uid.' --expire=15m');
        $this->assertSame(0,$shareCode,$shareOut);
        $token = $this->extractToken($shareOut); $this->assertNotSame('', $token);
        [$fetchOut1,$fetchCode1] = $this->runInProcess('share fetch '.$token);
        $this->assertSame(0,$fetchCode1,$fetchOut1);
        [$fetchOut2,$fetchCode2] = $this->runInProcess('share fetch '.$token);
        $this->assertSame(3,$fetchCode2,$fetchOut2);
    }

    public function testExpiry(): void {
        [$createOut,$createCode] = $this->runInProcess('snapshot create -m expshare');
        $this->assertSame(0,$createCode,$createOut);
        $uid = $this->extractUid($createOut); $this->assertNotSame('', $uid);
        [$shareOut,$shareCode] = $this->runInProcess('share create '.$uid.' --expire=1');
        $this->assertSame(0,$shareCode,$shareOut);
        $token = $this->extractToken($shareOut); $this->assertNotSame('', $token);
        usleep(2_000_000);
        [$fetchOut,$fetchCode] = $this->runInProcess('share fetch '.$token);
        $this->assertSame(3,$fetchCode,$fetchOut);
    }
}

