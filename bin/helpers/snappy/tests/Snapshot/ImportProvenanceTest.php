<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;
use Snappy\Snapshot\import_service;

final class ImportProvenanceTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root=sys_get_temp_dir().'/snappy_import_prov_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }

    private function manager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $reg=new remote_registry($cfg,$this->root); return new snapshot_manager($reg); }

    public function testProvenanceFileFields(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->manager();
        $uid=$manager->create('sql','prov test');
        $export=(new export_service($manager))->export($uid,['out_dir'=>$this->root]);
        $importer=new import_service($manager); $res=$importer->importArtifact($export['artifact_path'],['uid_strategy'=>'new']);
        $provPath=$manager->local_path($res['imported_uid']).'/import_provenance.json';
        $this->assertFileExists($provPath);
        $prov=json_decode(file_get_contents($provPath),true); $this->assertIsArray($prov);
        $expected=['original_uid','original_manifest_sha256','original_artifact_sha256','imported_uid','imported_utc','strategy'];
        foreach($expected as $k){ $this->assertArrayHasKey($k,$prov,$k.' missing'); }
        $this->assertSame($uid,$prov['original_uid']);
        $this->assertSame($res['imported_uid'],$prov['imported_uid']);
        $this->assertSame('new',$prov['strategy']);
        $this->assertSame($export['artifact_sha256'],$prov['original_artifact_sha256']);
        $this->assertSame($res['artifact_sha256'],$prov['original_artifact_sha256']);
        $this->assertSame($res['manifest_sha256'],$prov['original_manifest_sha256']);
        $this->assertMatchesRegularExpression('/^20\d{2}-\d{2}-\d{2}T/',$prov['imported_utc']);
    }
}

