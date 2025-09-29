<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class AliasesTest extends AbstractCliTestCase {
    public function testAliasListParityAndCanonicalCommandName(): void {
        // create snapshots using namespaced form
        $this->runCli('snapshot create -m one');
        $this->runCli('snapshot create -m two');
        $aliasOut = $this->runCli('--json list', $aliasCode); // alias for list
        $this->assertSame(0,$aliasCode,$aliasOut);
        $namespacedOut = $this->runCli('--json snapshot list', $nsCode);
        $this->assertSame(0,$nsCode,$namespacedOut);
        $a = json_decode($aliasOut,true);
        $b = json_decode($namespacedOut,true);
        $this->assertSame('snapshot.list',$a['command']);
        $this->assertSame('snapshot.list',$b['command']);
        $this->assertEquals($b['data']['tables'],$a['data']['tables']);
    }

    public function testAliasHelpSectionPresent(): void {
        $out = $this->runCli('help', $code);
        $this->assertSame(0,$code,$out);
        foreach (['create','list','show','apply'] as $alias) {
            $this->assertStringContainsString('  '.$alias.'  (tsnap snapshot '.$alias.')', $out, 'missing alias line for '.$alias.' in help output: '.$out);
        }
        $this->assertSame(4, substr_count($out,'(tsnap snapshot '), 'expected exactly four default alias lines');
    }

    public function testAliasJsonCommandNames(): void {
        $createOut = $this->runCli('--json create -m alpha', $cCode); // alias create
        $this->assertSame(0,$cCode,$createOut);
        $cDec = json_decode($createOut,true);
        $this->assertSame('snapshot.create',$cDec['command']);
        $uid = $cDec['data']['payload']['uid'] ?? null;
        $this->assertNotEmpty($uid);
        $showOut = $this->runCli('--json show '.$uid, $sCode); // alias show
        $this->assertSame(0,$sCode,$showOut);
        $sDec = json_decode($showOut,true);
        $this->assertSame('snapshot.show',$sDec['command']);
        // metrics no longer aliased by default
        $metricsOut = $this->runCli('--json snapshot metrics', $mCode);
        $this->assertSame(0,$mCode,$metricsOut);
        $mDec = json_decode($metricsOut,true);
        $this->assertSame('snapshot.metrics',$mDec['command']);
    }

    public function testExportImportNamespaced(): void {
        $createOut = $this->runCli('--json snapshot create -m exporttest', $cCode);
        $this->assertSame(0,$cCode,$createOut);
        $uid = json_decode($createOut,true)['data']['payload']['uid'];
        $outDir = $this->tmpRoot.'/art'; @mkdir($outDir,0777,true);
        $exportOut = $this->runCli('--json snapshot export '.$uid.' --out-dir='.escapeshellarg($outDir), $eCode);
        $this->assertSame(0,$eCode,$exportOut);
        $eDec = json_decode($exportOut,true);
        $this->assertSame('snapshot.export',$eDec['command']);
        $artifact = $eDec['data']['payload']['artifact_path'] ?? null;
        $this->assertNotEmpty($artifact);
        $importOut = $this->runCli('--json snapshot import '.escapeshellarg($artifact).' --uid-strategy=new', $iCode);
        $this->assertSame(0,$iCode,$importOut);
        $iDec = json_decode($importOut,true);
        $this->assertSame('snapshot.import',$iDec['command']);
    }
}
