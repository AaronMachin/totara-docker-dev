<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;
use Snappy\Snapshot\import_service;

final class ImportServiceTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir().'/snappy_import_test_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
    private function makeManager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $reg=new remote_registry($cfg,$this->root); return new snapshot_manager($reg); }

    public function testKeepStrategyImport(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->makeManager();
        $uid=$manager->create('sql','import keep test');
        $exporter=new export_service($manager); $artifactDir=$this->root.'/exports'; @mkdir($artifactDir,0777,true); $exp=$exporter->export($uid,['out_dir'=>$artifactDir]);
        // import into a different snapshot base so uid is free
        $altRoot=$this->root.'/alt'; @mkdir($altRoot,0777,true);
        $cfg2=new config_manager($altRoot.'/config.json',$altRoot); $cfg2->save(); $reg2=new remote_registry($cfg2,$altRoot); $manager2=new snapshot_manager($reg2);
        $importer=new import_service($manager2); $res=$importer->importArtifact($exp['artifact_path'],['uid_strategy'=>'keep']);
        $this->assertSame($uid,$res['imported_uid']);
        $this->assertSame($uid,$res['original_uid']);
        $this->assertSame('keep',$res['strategy']);
        $this->assertFileExists($manager2->local_path($uid).'/manifest-v2.json');
        $this->assertFileDoesNotExist($manager2->local_path($uid).'/import_provenance.json');
    }

    public function testNewStrategyGeneratesDifferentUidAndProvenance(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->makeManager();
        $uid=$manager->create('sql','import new test');
        $exporter=new export_service($manager); $artifactDir=$this->root.'/exports'; @mkdir($artifactDir,0777,true); $exp=$exporter->export($uid,['out_dir'=>$artifactDir]);
        $importer=new import_service($manager); $res=$importer->importArtifact($exp['artifact_path'],['uid_strategy'=>'new']);
        $this->assertNotSame($uid,$res['imported_uid']);
        $prov=$manager->local_path($res['imported_uid']).'/import_provenance.json';
        $this->assertFileExists($prov);
        $p=json_decode(file_get_contents($prov),true); $this->assertIsArray($p);
        $this->assertSame($uid,$p['original_uid']);
        $this->assertSame($res['imported_uid'],$p['imported_uid']);
        $this->assertSame('new',$p['strategy']);
    }

    public function testKeepStrategyFailsOnExisting(): void {
        $this->expectException(\Snappy\Support\Exception\ValidationException::class);
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->makeManager();
        $uid=$manager->create('sql','dup test');
        $exporter=new export_service($manager); $artifactDir=$this->root.'/exports'; @mkdir($artifactDir,0777,true); $exp=$exporter->export($uid,['out_dir'=>$artifactDir]);
        // attempt to import again with keep
        $importer=new import_service($manager); $importer->importArtifact($exp['artifact_path'],['uid_strategy'=>'keep']);
    }
}
