<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotListFilterTest extends InProcessCliTestCase {
    private function extractUid(string $out): string {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($out)),'strlen'));
        return end($parts);
    }

    public function testFilterByTagAndUidPrefix(): void {
        [$outA,$codeA,$ctx] = $this->runInProcess('snapshot create --tag=alpha -m first');
        $this->assertSame(0,$codeA,$outA);
        $uidA = $this->extractUid($outA);
        usleep(200000); // slight gap
        [$outB,$codeB] = $this->runInProcess('snapshot create --tag=beta -m second');
        $this->assertSame(0,$codeB,$outB);
        // tag filter alpha
        [$listTag,$codeTag] = $this->runInProcess('snapshot list --filter=tag=alpha');
        $this->assertSame(0,$codeTag,$listTag);
        $this->assertStringContainsString($uidA, $listTag);
        $this->assertStringNotContainsString('beta', $listTag); // message of second
        // uid prefix filter
        $prefix = substr($uidA,0,6);
        [$listUid,$codeUid] = $this->runInProcess('snapshot list --filter=uid='.$prefix);
        $this->assertSame(0,$codeUid,$listUid);
        $this->assertStringContainsString($uidA,$listUid);
    }

    public function testAgeComparisons(): void {
        [$outOld,$codeOld,$ctx] = $this->runInProcess('snapshot create --tag=old -m oldsnap');
        $this->assertSame(0,$codeOld,$outOld);
        $uidOld = $this->extractUid($outOld);
        // Modify created timestamp to 2 hours ago
        $base = $ctx->registry->local_base_path();
        $manifestPath = $base . '/snaps/' . $uidOld . '/manifest-v2.json';
        $this->assertFileExists($manifestPath);
        $m = json_decode((string)file_get_contents($manifestPath), true);
        $past = gmdate('Y-m-d\TH:i:s\Z', time()-2*3600);
        $m['created_utc'] = $past; // original field
        // ensure legacy alias maybe used
        $json = json_encode($m, JSON_PRETTY_PRINT);
        file_put_contents($manifestPath, $json);
        // Create a new recent snapshot
        [$outNew,$codeNew] = $this->runInProcess('snapshot create --tag=new -m newsnap');
        $this->assertSame(0,$codeNew,$outNew);
        // age>1h should include old only (use --no-index to bypass cached index)
        [$listOld,$codeListOld] = $this->runInProcess('snapshot list --no-index --filter=age>3600');
        $this->assertSame(0,$codeListOld,$listOld);
        $this->assertStringContainsString($uidOld,$listOld);
        $this->assertStringNotContainsString('newsnap',$listOld);
        // age<10m should include recent snapshot but not old (10m = 600s)
        [$listRecent,$codeRecent] = $this->runInProcess('snapshot list --no-index --filter=age<600');
        $this->assertSame(0,$codeRecent,$listRecent);
        $this->assertStringNotContainsString($uidOld,$listRecent);
        $this->assertStringContainsString('newsnap',$listRecent);
        // Combined filter: tag=old age>1h
        [$combined,$codeComb] = $this->runInProcess('snapshot list --no-index --filter="tag=old age>3600"');
        $this->assertSame(0,$codeComb,$combined);
        $this->assertStringContainsString($uidOld,$combined);
        $this->assertStringNotContainsString('newsnap',$combined);
    }

    public function testInvalidFilterToken(): void {
        [$out,$code] = $this->runInProcess('snapshot list --filter=unknownOp');
        $this->assertSame(2,$code);
        $this->assertStringContainsString('invalid filter token', $out);
    }
}

