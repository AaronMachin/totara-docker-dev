<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;
use Snappy\Snapshot\import_service;
use Snappy\Support\Exception\ValidationException;

final class ImportDuplicateUidTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root=sys_get_temp_dir().'/snappy_import_dup_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
    private function manager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $reg=new remote_registry($cfg,$this->root); return new snapshot_manager($reg); }

    public function testImportKeepStrategyDuplicateFailsAndNoTempLeft(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $m=$this->manager();
        $uid=$m->create('sql','duplicate base');
        $artifact=(new export_service($m))->export($uid,['out_dir'=>$this->root]);
        $imp=new import_service($m);
        $this->expectException(ValidationException::class);
        try {
            $imp->importArtifact($artifact['artifact_path'],['uid_strategy'=>'keep']);
        } finally {
            $tmpBase=$m->registry()->local_base_path().'/tmp';
            if(is_dir($tmpBase)) {
                $entries=array_filter(scandir($tmpBase)?:[],fn($e)=>!in_array($e,['.','..']));
                foreach($entries as $e){ $this->assertFalse(str_starts_with($e,'import_'),'temp import dir leaked: '.$e); }
            }
        }
    }
}

