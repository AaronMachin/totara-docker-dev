<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Snapshot\remote_registry;
use Snappy\Config\config_manager;
use Snappy\Remote\remote_lister;

final class RemoteListerTest extends TestCase {
    private string $root; private remote_registry $registry; private config_manager $cfg;

    protected function setUp(): void { parent::setUp(); $this->root=sys_get_temp_dir().'/snappy_rl_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); file_put_contents($this->root.'/config.json', json_encode(['version'=>1])); $this->cfg = new config_manager($this->root.'/config.json',$this->root.'/.prov'); $this->registry = new remote_registry($this->cfg,$this->root); $this->registry->add('mem','memory',[]); }
    protected function tearDown(): void { $this->rm($this->root); parent::tearDown(); }
    private function rm(string $dir): void { if(!is_dir($dir)) return; foreach(@scandir($dir)?:[] as $e){ if($e==='.'||$e==='..') continue; $p=$dir.'/'.$e; if(is_dir($p)) $this->rm($p); else @unlink($p);} @rmdir($dir); }

    private function put(string $key,string $content): void { $tmp=$this->root.'/obj_'.bin2hex(random_bytes(4)); file_put_contents($tmp,$content); $this->registry->storage('mem')->put_object($key,$tmp); }

    public function testEmpty(): void { $l=new remote_lister(); $res=$l->enumerate($this->registry,'mem',10,false); $this->assertSame([], $res['rows']); $this->assertSame(0,$res['errors']); }

    public function testManifestListingOrdersByCreated(): void {
        $uid1='a'.bin2hex(random_bytes(4)); $uid2='b'.bin2hex(random_bytes(4));
        $this->put('snaps/'.$uid1.'/backup.sql','AAA');
        $this->put('snaps/'.$uid2.'/backup.sql','BBBB');
        $man1=json_encode(['schema_version'=>2,'uid'=>$uid1,'created_utc'=>'2025-01-01T00:00:00Z','snapshot_type'=>'sql','message'=>'first','files'=>[],'checksums'=>['algo'=>'sha256','files'=>[]],'size_total_bytes'=>3]);
        $man2=json_encode(['schema_version'=>2,'uid'=>$uid2,'created_utc'=>'2025-01-02T00:00:00Z','snapshot_type'=>'sql','message'=>'second','files'=>[],'checksums'=>['algo'=>'sha256','files'=>[]],'size_total_bytes'=>4]);
        $this->put('snaps/'.$uid1.'/manifest-v2.json',$man1);
        $this->put('snaps/'.$uid2.'/manifest-v2.json',$man2);
        $l=new remote_lister(); $res=$l->enumerate($this->registry,'mem',10,false);
        $this->assertCount(2,$res['rows']);
        $this->assertSame($uid2,$res['rows'][0]['uid']); // newer first
        $this->assertArrayHasKey('size_bytes',$res['rows'][0]);
    }

    public function testMetaFallbackAndMessageTruncationVsFull(): void {
        $uid='c'.bin2hex(random_bytes(4));
        $this->put('snaps/'.$uid.'/backup.sql','DATA');
        $meta=json_encode(['uid'=>$uid,'created'=>'2025-02-01T00:00:00Z','type'=>'sql','message'=>'line1\nline2']);
        $this->put('snaps/'.$uid.'/meta.json',$meta);
        $l=new remote_lister(); $short=$l->enumerate($this->registry,'mem',10,false); $full=$l->enumerate($this->registry,'mem',10,true);
        $this->assertSame('line1',$short['rows'][0]['message']);
        $this->assertSame('line1 | line2',$full['rows'][0]['message']);
    }

    public function testCorruptAllEntriesYieldsErrors(): void {
        $uid='d'.bin2hex(random_bytes(4));
        $this->put('snaps/'.$uid.'/manifest-v2.json','{not-json');
        $l=new remote_lister(); $res=$l->enumerate($this->registry,'mem',10,false);
        $this->assertSame(1,$res['errors']);
        $this->assertSame(1,$res['candidates']);
        $this->assertSame([], $res['rows']);
    }

    public function testLimitApplied(): void {
        $lister=new remote_lister();
        $uids=[]; for($i=0;$i<5;$i++){ $uid='e'.bin2hex(random_bytes(4)); $uids[]=$uid; $this->put('snaps/'.$uid.'/backup.sql','X'); $man=json_encode(['schema_version'=>2,'uid'=>$uid,'created_utc'=>sprintf('2025-03-0%dT00:00:00Z',$i+1),'snapshot_type'=>'sql','message'=>'m'.$i,'files'=>[],'checksums'=>['algo'=>'sha256','files'=>[]],'size_total_bytes'=>1]); $this->put('snaps/'.$uid.'/manifest-v2.json',$man); }
        $res=$lister->enumerate($this->registry,'mem',3,false); $this->assertCount(3,$res['rows']);
        // ensure all returned have uid present in seeded
        foreach($res['rows'] as $r){ $this->assertContains($r['uid'],$uids); }
    }
}

