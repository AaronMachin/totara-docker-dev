<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;

final class LargeFileExportTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir().'/snappy_export_large_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
    private function manager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $registry=new remote_registry($cfg,$this->root); return new snapshot_manager($registry); }

    public function testLargeFileStreamsWithoutMemorySpike(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->manager();
        $uid=$manager->create('sql','large file test');
        $snapDir=$manager->local_path($uid);
        // create ~5MB pseudo-random content file
        $largePath=$snapDir.'/big.dat';
        $chunk=str_repeat('A',1024); $fh=fopen($largePath,'wb'); for($i=0;$i<5120;$i++){ fwrite($fh,$chunk); } fclose($fh); // 5MB
        $manPath=$snapDir.'/manifest-v2.json'; $manifest=json_decode(file_get_contents($manPath),true);
        $manifest['files'][]=['name'=>'big.dat','size_bytes'=>filesize($largePath),'compressed'=>false,'stored_inline'=>true];
        file_put_contents($manPath,json_encode($manifest,JSON_PRETTY_PRINT));
        $beforeMem=memory_get_peak_usage(true);
        $service=new export_service($manager); $outDir=$this->root.'/exports'; @mkdir($outDir,0777,true); $res=$service->export($uid,['out_dir'=>$outDir]);
        $afterMem=memory_get_peak_usage(true);
        $this->assertFileExists($res['artifact_path']);
        // Ensure memory increase stayed reasonable (<25MB delta)
        $this->assertLessThan(25*1024*1024,$afterMem-$beforeMem,'export should stream large file without large memory spike');
    }
}

