<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Snapshot\remote_registry;
use Snappy\Config\config_manager;
use Snappy\Remote\remote_pull_service;
use Snappy\Util\snapshot_uid;
use Snappy\Snapshot\integrity_service;
use Snappy\Support\Exception\ValidationException;

final class RemotePullServiceTest extends TestCase {
    private string $tmpRoot; private remote_registry $registry; private config_manager $cfg; private integrity_service $integrity;

    protected function setUp(): void { parent::setUp(); $this->tmpRoot=sys_get_temp_dir().'/snappy_rpull_'.bin2hex(random_bytes(5)); @mkdir($this->tmpRoot,0777,true); $cfgFile=$this->tmpRoot.'/config.json'; file_put_contents($cfgFile,json_encode(['version'=>1])); $this->cfg=new config_manager($cfgFile,$this->tmpRoot.'/.prov'); $this->registry=new remote_registry($this->cfg,$this->tmpRoot); $this->integrity=new integrity_service(); $this->registry->add('mem','memory',[]); }
    protected function tearDown(): void { $this->rm($this->tmpRoot); parent::tearDown(); }

    private function rm(string $dir): void { if(!is_dir($dir)) return; $it=scandir($dir); if(!$it) return; foreach($it as $e){ if($e==='.'||$e==='..') continue; $p=$dir.'/'.$e; if(is_dir($p)) $this->rm($p); else @unlink($p);} @rmdir($dir); }

    private function seedSnapshot(string $uid, array $files, bool $withManifest=true, bool $tamper=false, bool $metaOnly=false): array {
        $storage=$this->registry->storage('mem'); $checks=[]; foreach($files as $name=>$content){ $tmp=$this->tmpRoot.'/seed_'.bin2hex(random_bytes(4)); file_put_contents($tmp,$tamper && $name==='backup.sql' ? $content.'x' : $content); $storage->put_object('snaps/'.$uid.'/'.$name,$tmp); $checks[$name]=$this->integrity->hashFile($tmp); }
        if($withManifest && !$metaOnly){ $manifest=[ 'schema_version'=>2,'uid'=>$uid,'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>'sql','message'=>'test','files'=>[], 'checksums'=>['algo'=>'sha256','files'=>$checks],'size_total_bytes'=>array_sum(array_map('strlen',$files)),'compression'=>['enabled'=>false],'provenance'=>['source'=>'test'] ]; foreach(array_keys($files) as $n){ $manifest['files'][]=['name'=>$n,'size_bytes'=>strlen($files[$n]),'compressed'=>false,'stored_inline'=>true]; } $mf=$this->tmpRoot.'/m_'.bin2hex(random_bytes(4)); file_put_contents($mf,json_encode($manifest,JSON_PRETTY_PRINT)); $storage->put_object('snaps/'.$uid.'/manifest-v2.json',$mf); return $checks; }
        if($metaOnly){ $meta=['uid'=>$uid,'created'=>gmdate('c'),'type'=>'sql','message'=>'meta only']; $mf=$this->tmpRoot.'/meta_'.bin2hex(random_bytes(4)); file_put_contents($mf,json_encode($meta)); $storage->put_object('snaps/'.$uid.'/meta.json',$mf); }
        return $checks; }

    public function testPullWithManifestSuccess(): void {
        $uid=snapshot_uid::generate(); $this->seedSnapshot($uid,['backup.sql'=>'DATA']); $svc=new remote_pull_service($this->registry); $res=$svc->pull('mem',$uid,[]); $this->assertTrue($res['success']); $this->assertSame($uid,$res['final_uid']); $this->assertTrue($res['verified']); $this->assertDirectoryExists($this->tmpRoot.'/snaps/'.$uid); $this->assertFileExists($this->tmpRoot.'/snaps/'.$uid.'/manifest-v2.json'); }

    public function testPullMetaOnlySynthesisesManifest(): void { $uid=snapshot_uid::generate(); $this->seedSnapshot($uid,['backup.sql'=>'XYZ'],withManifest:false,metaOnly:true); $svc=new remote_pull_service($this->registry); $res=$svc->pull('mem',$uid,[]); $this->assertFileExists($this->tmpRoot.'/snaps/'.$uid.'/manifest-v2.json'); $man=json_decode(file_get_contents($this->tmpRoot.'/snaps/'.$uid.'/manifest-v2.json'),true); $this->assertSame(2,$man['schema_version']); }

    public function testUidStrategyNewWritesProvenance(): void { $uid=snapshot_uid::generate(); $this->seedSnapshot($uid,['backup.sql'=>'AAAA']); $svc=new remote_pull_service($this->registry); $res=$svc->pull('mem',$uid,['uid_strategy'=>'new']); $this->assertNotSame($uid,$res['final_uid']); $this->assertFileExists($this->tmpRoot.'/snaps/'.$res['final_uid'].'/import_provenance_remote.json'); }

    public function testChecksumMismatchFails(): void { $uid=snapshot_uid::generate(); $this->seedSnapshot($uid,['backup.sql'=>'ORIG']); // manifest matches original
        // mutate object content after manifest uploaded
        $storage=$this->registry->storage('mem'); $tmp=$this->tmpRoot.'/mut_'.bin2hex(random_bytes(4)); file_put_contents($tmp,'ORIG-CHANGED'); $storage->put_object('snaps/'.$uid.'/backup.sql',$tmp);
        $svc=new remote_pull_service($this->registry); $this->expectException(ValidationException::class); $this->expectExceptionMessage('checksum mismatch'); $svc->pull('mem',$uid,[]); }

    public function testAmbiguousPrefixError(): void { $uid1=snapshot_uid::generate(); do { $uid2=snapshot_uid::generate(); } while(substr($uid2,0,6)!==substr($uid1,0,6) || $uid2===$uid1); $prefix=substr($uid1,0,6); $this->seedSnapshot($uid1,['backup.sql'=>'A']); $this->seedSnapshot($uid2,['backup.sql'=>'B']); $svc=new remote_pull_service($this->registry); $this->expectException(ValidationException::class); $this->expectExceptionMessage('ambiguous'); $svc->pull('mem',$prefix,[]); }

    public function testTargetExistsWithoutForce(): void { $uid=snapshot_uid::generate(); $this->seedSnapshot($uid,['backup.sql'=>'X']); $svc=new remote_pull_service($this->registry); $svc->pull('mem',$uid,[]); $this->expectException(ValidationException::class); $this->expectExceptionMessage('target exists'); $svc->pull('mem',$uid,[]); }

    public function testMissingSnapshotNotFound(): void { $uid=snapshot_uid::generate(); $svc=new remote_pull_service($this->registry); $this->expectException(ValidationException::class); $this->expectExceptionMessage('snapshot not found'); $svc->pull('mem',$uid,[]); }
}
