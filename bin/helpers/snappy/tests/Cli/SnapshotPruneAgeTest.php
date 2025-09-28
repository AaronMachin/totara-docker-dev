<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotPruneAgeTest extends InProcessCliTestCase {
    public function testAgePruneDryRunAndApply(): void {
        $ctx = $this->buildContext(true);
        // Create two snapshots; make first artificially old (2d)
        [$o1,$c1] = $this->runInProcess('snapshot create --tag=oldtag -m old'); $this->assertSame(0,$c1,$o1);
        $uid1 = trim(substr(strrchr(trim($o1),' '),1));
        $base = $ctx->registry->local_base_path();
        $manifest1 = $base.'/snaps/'.$uid1.'/manifest-v2.json';
        $m1 = json_decode((string)file_get_contents($manifest1), true); $m1['created_utc'] = gmdate('Y-m-d\TH:i:s\Z', time()-2*86400); file_put_contents($manifest1, json_encode($m1, JSON_PRETTY_PRINT));
        // second snapshot recent
        [$o2,$c2] = $this->runInProcess('snapshot create --tag=newtag -m new'); $this->assertSame(0,$c2,$o2);
        // Dry run max-age=1d
        [$dry,$dryCode] = $this->runInProcess('prune run --max-age=1d');
        $this->assertSame(0,$dryCode,$dry);
        $this->assertStringContainsString('Dry run: would delete 1 snapshot', $dry);
        $this->assertStringContainsString($uid1,$dry);
        // Apply
        [$apply,$applyCode] = $this->runInProcess('prune run --max-age=1d --apply');
        $this->assertSame(0,$applyCode,$apply);
        $this->assertStringContainsString('Deleted 1 snapshot', $apply);
        // Ensure old gone, new present
        [$list,$listCode] = $this->runInProcess('snapshot list'); $this->assertSame(0,$listCode,$list);
        $this->assertStringNotContainsString($uid1,$list);
        $this->assertStringContainsString('new', $list);
    }

    public function testCombinedKeepLastAndAgeUnion(): void {
        $ctx = $this->buildContext(true);
        // create 4 snapshots
        $uids=[]; for($i=1;$i<=4;$i++){ [$o,$c]=$this->runInProcess('snapshot create -m s'.$i); $this->assertSame(0,$c); $uids[] = trim(substr(strrchr(trim($o),' '),1)); }
        // Make first two snapshots old ( >1d )
        $base = $ctx->registry->local_base_path();
        for($i=0;$i<2;$i++){ $mf=$base.'/snaps/'.$uids[$i].'/manifest-v2.json'; $m=json_decode((string)file_get_contents($mf),true); $m['created_utc']=gmdate('Y-m-d\TH:i:s\Z', time()-2*86400); file_put_contents($mf,json_encode($m,JSON_PRETTY_PRINT)); }
        // Dry run keep-last=2 and max-age=1d => union should include first two old ones OR extras beyond last two.
        [$dry,$code] = $this->runInProcess('prune run --keep-last=2 --max-age=1d');
        $this->assertSame(0,$code,$dry);
        // Expect at least 2 candidate lines (old ones)
        $this->assertStringContainsString($uids[0],$dry);
        $this->assertStringContainsString($uids[1],$dry);
    }

    public function testInvalidMaxAge(): void {
        [$out,$code] = $this->runInProcess('prune run --max-age=12x');
        $this->assertSame(2,$code);
        $this->assertStringContainsString('invalid max-age format',$out);
    }
}

