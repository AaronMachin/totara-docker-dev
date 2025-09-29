<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;
use Snappy\Snapshot\import_service;
use Snappy\Support\Exception\ValidationException;

final class ImportEdgeCasesTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir().'/snappy_import_ec_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
    private function makeManager(): snapshot_manager { $cfg=new config_manager($this->root.'/config.json',$this->root); $cfg->save(); $reg=new remote_registry($cfg,$this->root); return new snapshot_manager($reg); }

    private function buildTar(array $entries): string { $tar=''; foreach($entries as $name=>$content){ $size=strlen($content); $header=$this->buildHeader($name,$size); $tar.=$header.$content; $pad=$size%512; if($pad!==0){ $tar.=str_repeat("\0",512-$pad);} } $tar.=str_repeat("\0",1024); return $tar; }
    private function buildHeader(string $name,int $size): string { $nameBytes=substr($name,0,100); $mode=str_pad(decoct(0644),7,'0',STR_PAD_LEFT)."\0"; $uid=str_pad('0',7,'0',STR_PAD_LEFT)."\0"; $gid=str_pad('0',7,'0',STR_PAD_LEFT)."\0"; $sizeOct=str_pad(decoct($size),11,'0',STR_PAD_LEFT)."\0"; $mtime=str_pad(decoct(0),11,'0',STR_PAD_LEFT)."\0"; $chksum='        '; $typeflag='0'; $link=str_repeat("\0",100); $magic='ustar' . "\0"; $version='00'; $uname=str_pad('root',32,"\0"); $gname=str_pad('root',32,"\0"); $devm=str_repeat("\0",8); $devn=str_repeat("\0",8); $prefix=str_repeat("\0",155); $pad=str_repeat("\0",12); $header=str_pad($nameBytes,100,"\0").$mode.$uid.$gid.$sizeOct.$mtime.$chksum.$typeflag.$link.$magic.$version.$uname.$gname.$devm.$devn.$prefix.$pad; $sum=0; for($i=0;$i<512;$i++){ $sum+=ord($header[$i]); } $chk=str_pad(decoct($sum),6,'0',STR_PAD_LEFT)."\0 "; return substr($header,0,148).$chk.substr($header,156); }

    public function testDuplicateEntryFails(): void {
        $manifest=['schema_version'=>2,'uid'=>'u123','created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>'sql','files'=>[['name'=>'backup.sql','size_bytes'=>11,'compressed'=>false]],'checksums'=>['algo'=>'sha256','files'=>['backup.sql'=>hash('sha256','hello world')]]];
        $integrity = new \Snappy\Snapshot\integrity_service();
        $manifestContent = \Snappy\Util\canonical_json::encode($manifest);
        $manifestSha = $integrity->hashManifest($manifest);
        $lines=['MANIFEST manifest-v2.json '.$manifestSha.' '.strlen($manifestContent),'FILE files/backup.sql '.hash('sha256','hello world').' 11'];
        $export=['schema_version'=>1,'source_uid'=>'u123','created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'manifest_sha256'=>$manifestSha,'files'=>[['path'=>'files/backup.sql','size_bytes'=>11,'sha256'=>hash('sha256','hello world')]],'artifact_sha256'=>$integrity->artifactLinesHash($lines)];
        // Build tar manually to allow duplicate manifest entry
        $tar='';
        $add=function(string $name,string $content) use (&$tar){ $size=strlen($content); $hdr=$this->buildHeader($name,$size); $tar.=$hdr.$content; $pad=$size%512; if($pad!==0){ $tar.=str_repeat("\0",512-$pad);} };
        $add('manifest-v2.json',$manifestContent);
        $add('export.json',json_encode($export,JSON_PRETTY_PRINT));
        $add('files/backup.sql','hello world');
        $add('manifest-v2.json',$manifestContent); // duplicate
        $tar.=str_repeat("\0",1024);
        $gz=gzencode($tar,6); $file=$this->root.'/dup.tar.gz'; file_put_contents($file,$gz);
        $m=$this->makeManager(); $imp=new import_service($m); $this->expectException(ValidationException::class); $imp->importArtifact($file,[]);
    }

    public function testCorruptedFileHashMismatch(): void { putenv('SNAPPY_FAKE_DUMP=1'); $m=$this->makeManager(); $uid=$m->create('sql','corrupt test'); $exp=(new export_service($m))->export($uid,['out_dir'=>$this->root]); $path=$exp['artifact_path']; $data=file_get_contents($path); $off=min(1500,max(1,strlen($data)-10)); $data[$off]=chr((ord($data[$off])^0xFF)&0xFF); file_put_contents($path.'x',$data); $this->expectException(ValidationException::class); (new import_service($m))->importArtifact($path.'x',[]); }

    public function testMissingEntries(): void { $manifest=['schema_version'=>2,'uid'=>'m1','created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>'sql','files'=>[],'checksums'=>['algo'=>'sha256','files'=>[]]]; $tar=$this->buildTar(['manifest-v2.json'=>json_encode($manifest)]); $file=$this->root.'/missing.tar'; file_put_contents($file,$tar); $m=$this->makeManager(); $this->expectException(ValidationException::class); (new import_service($m))->importArtifact($file,[]); }

    public function testTruncatedGzip(): void { putenv('SNAPPY_FAKE_DUMP=1'); $m=$this->makeManager(); $uid=$m->create('sql','trunc'); $exp=(new export_service($m))->export($uid,['out_dir'=>$this->root]); $path=$exp['artifact_path']; $data=file_get_contents($path); $short=substr($data,0,(int)(strlen($data)/2)); file_put_contents($path.'trunc',$short); $this->expectException(ValidationException::class); (new import_service($m))->importArtifact($path.'trunc',[]); }

    public function testMultipleNewImportsProduceDistinctUids(): void { putenv('SNAPPY_FAKE_DUMP=1'); $m=$this->makeManager(); $uid=$m->create('sql','multi new'); $exp=(new export_service($m))->export($uid,['out_dir'=>$this->root]); $imp=new import_service($m); $r1=$imp->importArtifact($exp['artifact_path'],['uid_strategy'=>'new']); $r2=$imp->importArtifact($exp['artifact_path'],['uid_strategy'=>'new']); $this->assertNotSame($r1['imported_uid'],$r2['imported_uid']); }

    public function testInvalidArtifactHashMismatch(): void { putenv('SNAPPY_FAKE_DUMP=1'); $m=$this->makeManager(); $uid=$m->create('sql','bad hash'); $exp=(new export_service($m))->export($uid,['out_dir'=>$this->root]); $path=$exp['artifact_path']; $tar=gzdecode(file_get_contents($path)); $tarMod=preg_replace_callback('/artifact_sha256"\s*:\s*"([0-9a-f]{64})"/i',function($m){ $h=$m[1]; $h[0]=$h[0]==='a'?'b':'a'; return 'artifact_sha256":"'.$h.'"'; },$tar,1); $new=gzencode($tarMod,6); file_put_contents($path.'bad',$new); $this->expectException(ValidationException::class); (new import_service($m))->importArtifact($path.'bad',[]); }
}
