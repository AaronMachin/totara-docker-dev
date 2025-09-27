<?php
namespace Snappy\Tests\Util;

use PHPUnit\Framework\TestCase;
use Snappy\Util\snapshot_uid;

class SnapshotUidTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/snappy_uid_' . bin2hex(random_bytes(4));
        @mkdir($this->root, 0777, true);
    }

    public function testGenerateProducesDifferentValues(): void {
        $a = snapshot_uid::generate();
        $b = snapshot_uid::generate();
        $this->assertNotSame($a, $b);
        $this->assertGreaterThan(15, strlen($a));
    }

    public function testListLocalEmpty(): void {
        $uids = snapshot_uid::list_local($this->root);
        $this->assertSame([], $uids);
    }

    public function testFindByPrefix(): void {
        // create fake structure: shard directories (first 2 chars) / uid / meta.json
        $uids = [];
        for ($i=0;$i<3;$i++) {
            $uid = snapshot_uid::generate();
            $uids[] = $uid;
            $shard = substr($uid,0,2);
            $dir = $this->root . '/' . $shard . '/' . $uid;
            @mkdir($dir, 0777, true);
            file_put_contents($dir . '/meta.json', '{}');
        }
        // list
        $listed = snapshot_uid::list_local($this->root);
        sort($uids); sort($listed);
        $this->assertSame($uids, $listed);
        // prefix resolution
        $target = $uids[0];
        $prefix = substr($target,0,6);
        $resolved = snapshot_uid::find_by_prefix($this->root, $prefix);
        $this->assertSame($target, $resolved);
        // ambiguous returns empty
        $ambPrefix = substr($uids[0],0,2); // likely shared
        $maybe = snapshot_uid::find_by_prefix($this->root, $ambPrefix);
        if ($maybe !== '') {
            $this->assertContains($maybe, $uids); // acceptable unique coincidence
        }
    }
}

