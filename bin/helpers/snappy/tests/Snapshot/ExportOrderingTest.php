<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;
use Snappy\Snapshot\integrity_service;

final class ExportOrderingTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root=sys_get_temp_dir().'/snappy_export_order_'.bin2hex(random_bytes(4)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir))return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
    private function manager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $reg=new remote_registry($cfg,$this->root); return new snapshot_manager($reg); }

    public function testTarEntryOrderingAndExportJsonPresent(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $manager=$this->manager();
        $uid=$manager->create('sql','ordering test');
        $snapDir=$manager->local_path($uid);
        // add extra files
        file_put_contents($snapDir.'/extra.txt','hello world');
        file_put_contents($snapDir.'/empty.bin','');
        // modify manifest to include them
        $manPath=$snapDir.'/manifest-v2.json';
        $manifest=json_decode(file_get_contents($manPath),true);
        $manifest['files'][]=['name'=>'extra.txt','size_bytes'=>strlen('hello world'),'compressed'=>false,'stored_inline'=>true];
        $manifest['files'][]=['name'=>'empty.bin','size_bytes'=>0,'compressed'=>false,'stored_inline'=>true];
        file_put_contents($manPath,json_encode($manifest,JSON_PRETTY_PRINT));
        $service=new export_service($manager); $outDir=$this->root.'/exports'; @mkdir($outDir,0777,true); $res=$service->export($uid,['out_dir'=>$outDir]);
        $this->assertFileExists($res['artifact_path']);
        $entries=$this->listTarEntries($res['artifact_path']);
        $this->assertSame(['manifest-v2.json','export.json','files/backup.sql','files/empty.bin','files/extra.txt'],$entries,'Tar entry ordering must be deterministic');
        // Read export.json content
        $exportJson=$this->readTarFile($res['artifact_path'],'export.json');
        $this->assertNotNull($exportJson);
        $decoded=json_decode($exportJson,true); $this->assertIsArray($decoded); $this->assertSame(1,$decoded['schema_version']);
        $this->assertSame($res['artifact_sha256'],$decoded['artifact_sha256']);
        // Ensure zero byte file included line
        $foundEmpty=false; foreach($decoded['files'] as $f){ if($f['path']==='files/empty.bin'){ $foundEmpty=true; $this->assertSame(0,$f['size_bytes']); }} $this->assertTrue($foundEmpty);
        // Validate lines hash by recomputing from export.json file list
        $integrity=new integrity_service();
        $lines=['MANIFEST manifest-v2.json '.$decoded['manifest_sha256'].' '.strlen(file_get_contents($snapDir.'/manifest-v2.json'))];
        $seenNames=[]; foreach($decoded['files'] as $f){ $lines[]='FILE '.$f['path'].' '.$f['sha256'].' '.$f['size_bytes']; $seenNames[]=$f['path']; }
        $this->assertContains('files/backup.sql',$seenNames);
        $this->assertSame($res['artifact_sha256'],$integrity->artifactLinesHash($lines));
    }

    private function listTarEntries(string $artifact): array { $out=[]; $gz=gzopen($artifact,'rb'); if(!$gz) return $out; while(!gzeof($gz)){ $block=gzread($gz,512); if($block===false||$block===''){ break; } if(strlen($block)<512){ break; } if($block===str_repeat("\0",512)){ // check next 512 for EOArchive
                $next=gzread($gz,512); break; }
            $name=rtrim(strtok(substr($block,0,100),"\0")); if($name!==''){ $sizeOct=trim(substr($block,124,12)); $size=octdec($sizeOct); $out[]=$name; // skip file content + padding
                $skip=$size; if($skip>0){ $toSkip=$skip; while($toSkip>0){ $chunk=min(8192,$toSkip); $data=gzread($gz,$chunk); $toSkip-=strlen($data); } $pad=$size%512; if($pad>0){ gzread($gz,512-$pad); } }
            } else { break; }
        } gzclose($gz); return $out; }

    private function readTarFile(string $artifact,string $target): ?string { $gz=gzopen($artifact,'rb'); if(!$gz) return null; $data=null; while(!gzeof($gz)){ $hdr=gzread($gz,512); if(!$hdr||strlen($hdr)<512) break; if($hdr===str_repeat("\0",512)){ break; } $name=rtrim(strtok(substr($hdr,0,100),"\0")); $size=octdec(trim(substr($hdr,124,12))); if($name===$target){ $buf=''; $remain=$size; while($remain>0){ $chunk=gzread($gz,min(8192,$remain)); if($chunk===''){ break; } $buf.=$chunk; $remain-=strlen($chunk); } $data=$buf; $pad=$size%512; if($pad>0){ gzread($gz,512-$pad);} } else { // skip
                $remain=$size; while($remain>0){ $chunk=gzread($gz,min(8192,$remain)); if($chunk===''){ break; } $remain-=strlen($chunk);} $pad=$size%512; if($pad>0){ gzread($gz,512-$pad);} }
        } gzclose($gz); return $data; }
}

