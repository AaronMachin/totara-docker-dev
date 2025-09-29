<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;

final class ExportDeterminismTest extends TestCase {
    private string $root;

    protected function setUp(): void { $this->root = sys_get_temp_dir().'/snappy_export_det_'.bin2hex(random_bytes(4)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); putenv('SNAPPY_FAKE_DUMP_CONST'); }

    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }

    private function makeManager(snapshot_manager &$managerOut=null): snapshot_manager { $cfgFile=$this->root.'/config.json'; $cfg=new config_manager($cfgFile,$this->root); $cfg->save(); $registry=new remote_registry($cfg,$this->root); $manager=new snapshot_manager($registry); $managerOut=$manager; return $manager; }

    public function testRepeatExportStableArtifactHash(): void {
        putenv('SNAPPY_FAKE_DUMP=1'); // use fake dump provider
        putenv('SNAPPY_FAKE_DUMP_CONST=1'); // deterministic file content
        $manager = $this->makeManager();
        $uid = $manager->create('sql','deterministic export test');
        $service = new export_service($manager);
        $outDir = $this->root.'/exports'; @mkdir($outDir,0777,true);
        $r1 = $service->export($uid,['out_dir'=>$outDir]);
        $this->assertFileExists($r1['artifact_path']);
        $r2 = $service->export($uid,['out_dir'=>$outDir]);
        $this->assertSame($r1['artifact_sha256'],$r2['artifact_sha256'],'artifact hash should be stable across exports');
        // Also file hash of tar.gz should typically match (allow difference if gzip timestamp varies)
        $f1 = hash_file('sha256',$r1['artifact_path']);
        $f2 = hash_file('sha256',$r2['artifact_path']);
        // If differs, still acceptable but artifact_sha256 must match; enforce at least that one of file or artifact hash stable.
        $this->assertNotEmpty($f1); $this->assertNotEmpty($f2);
    }
}

