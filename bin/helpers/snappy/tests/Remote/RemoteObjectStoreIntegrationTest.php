<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\index_manager;
use Snappy\Remote\remote_pull_service;
use Snappy\Snapshot\integrity_service;

/**
 * Integration test against a real S3-compatible object store endpoint (optional).
 * Requires docker compose bucket service running locally:
 *   docker compose -f compose/bucket.yml up -d bucket
 * Enable with: SNAPPY_OBJECTSTORE_IT=1
 * Env vars:
 *   SNAPPY_OBJECTSTORE_ENDPOINT (default http://localhost:8000)
 *   SNAPPY_OBJECTSTORE_USER (default admin)
 *   SNAPPY_OBJECTSTORE_SECRET (default admin12345)
 */
final class RemoteObjectStoreIntegrationTest extends TestCase {
    private string $tmpRoot; private string $bucket; private string $endpoint; private string $user; private string $secret;

    protected function setUp(): void {
        parent::setUp();
        if (!getenv('SNAPPY_OBJECTSTORE_IT')) { $this->markTestSkipped('Set SNAPPY_OBJECTSTORE_IT=1 to run object store integration tests'); }
        $this->endpoint = getenv('SNAPPY_OBJECTSTORE_ENDPOINT') ?: 'http://localhost:8000';
        $this->user = getenv('SNAPPY_OBJECTSTORE_USER') ?: 'admin';
        $this->secret = getenv('SNAPPY_OBJECTSTORE_SECRET') ?: 'admin12345';
        $this->bucket = 'snappy-it-' . substr(bin2hex(random_bytes(4)),0,8);
        $this->tmpRoot = sys_get_temp_dir().'/snappy_objstore_it_'.bin2hex(random_bytes(5)); @mkdir($this->tmpRoot,0777,true);
    }

    protected function tearDown(): void { if(is_dir($this->tmpRoot)) $this->rm($this->tmpRoot); parent::tearDown(); }
    private function rm(string $dir): void { $items=@scandir($dir); if(!$items) { @rmdir($dir); return;} foreach($items as $i){ if($i==='.'||$i==='..') continue; $p=$dir.'/'.$i; if(is_dir($p)) $this->rm($p); else @unlink($p);} @rmdir($dir); }

    private function buildContext(): array {
        $cfgFile = $this->tmpRoot.'/config.json'; file_put_contents($cfgFile,json_encode(['version'=>1]));
        $cfg = new config_manager($cfgFile,$this->tmpRoot.'/.prov');
        $registry = new remote_registry($cfg,$this->tmpRoot.'/root');
        $manager = new snapshot_manager($registry); $index=new index_manager($this->tmpRoot.'/root'); $manager->set_index($index);
        return [$cfg,$registry,$manager,$index];
    }

    private function seedRemoteSnapshot(remote_registry $registry,string $remote,string $uid,bool $manifest=true): void {
        $storage = $registry->storage($remote); // s3_storage
        if(method_exists($storage,'ensure_bucket')) { $storage->ensure_bucket(); }
        $tmpFile=$this->tmpRoot.'/file_'.$uid.'.sql'; file_put_contents($tmpFile,"-- integration test $uid\nSELECT 1;\n");
        $storage->put_object('snaps/'.$uid.'/backup.sql',$tmpFile);
        $integrity = new integrity_service(); $hash = $integrity->hashFile($tmpFile); $size=filesize($tmpFile)?:0;
        if($manifest){
            $man=[ 'schema_version'=>2,'uid'=>$uid,'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>'sql','message'=>'integration test','files'=>[['name'=>'backup.sql','size_bytes'=>$size,'compressed'=>false,'stored_inline'=>true]],'checksums'=>['algo'=>'sha256','files'=>['backup.sql'=>$hash]],'size_total_bytes'=>$size,'compression'=>['enabled'=>false],'provenance'=>['source'=>'it'] ];
            $mf=$this->tmpRoot.'/manifest_'.$uid.'.json'; file_put_contents($mf,json_encode($man,JSON_PRETTY_PRINT));
            $storage->put_object('snaps/'.$uid.'/manifest-v2.json',$mf);
        } else {
            $meta=['uid'=>$uid,'created'=>gmdate('c'),'type'=>'sql','message'=>'meta only']; $mf=$this->tmpRoot.'/meta_'.$uid.'.json'; file_put_contents($mf,json_encode($meta)); $storage->put_object('snaps/'.$uid.'/meta.json',$mf);
        }
    }

    public function testRemotePullWithManifestFromObjectStore(): void {
        [$cfg,$registry] = $this->buildContext();
        $registry->add('objectstore','s3',[ 'endpoint'=>$this->endpoint, 'bucket'=>$this->bucket, 'region'=>'us-east-1', 'key'=>$this->user, 'secret'=>$this->secret, 'path_style'=>true]);
        $uid = 'itpull'.substr(bin2hex(random_bytes(6)),0,12);
        $this->seedRemoteSnapshot($registry,'objectstore',$uid,true);
        $svc = new remote_pull_service($registry);
        $res = $svc->pull('objectstore',$uid,[]);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['verified']);
        $this->assertDirectoryExists($registry->local_base_path().'/snaps/'.$uid);
    }

    public function testRemotePullMetaOnlyFromObjectStore(): void {
        [$cfg,$registry] = $this->buildContext();
        $registry->add('objectstore','s3',[ 'endpoint'=>$this->endpoint, 'bucket'=>$this->bucket, 'region'=>'us-east-1', 'key'=>$this->user, 'secret'=>$this->secret, 'path_style'=>true]);
        $uid = 'itmeta'.substr(bin2hex(random_bytes(6)),0,12);
        $this->seedRemoteSnapshot($registry,'objectstore',$uid,false);
        $svc = new remote_pull_service($registry);
        $res = $svc->pull('objectstore',$uid,[]);
        $this->assertTrue($res['success']);
        $this->assertFileExists($registry->local_base_path().'/snaps/'.$uid.'/manifest-v2.json');
    }
}
