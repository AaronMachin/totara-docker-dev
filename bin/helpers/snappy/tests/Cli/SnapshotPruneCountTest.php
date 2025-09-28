<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotPruneCountTest extends InProcessCliTestCase {
    public function testPruneKeepLastDryRunAndApply(): void {
        $ctx = $this->buildContext(true);
        // create 5 snapshots
        for($i=1;$i<=5;$i++){ [$o,$c] = $this->runInProcess('snapshot create --tag=t'.$i.' -m snap'.$i); $this->assertSame(0,$c,$o); }
        // Dry run keep last 3
        [$dry,$code] = $this->runInProcess('prune run --keep-last=3');
        $this->assertSame(0,$code,$dry);
        $this->assertStringContainsString('Dry run: would delete 2 snapshot', $dry);
        // Apply
        [$apply,$applyCode] = $this->runInProcess('prune run --keep-last=3 --apply');
        $this->assertSame(0,$applyCode,$apply);
        $this->assertStringContainsString('Deleted 2 snapshot', $apply);
        // Verify only 3 remain
        [$list,$listCode] = $this->runInProcess('snapshot list');
        $this->assertSame(0,$listCode,$list);
        $lines = array_filter(explode("\n", trim($list))); // header + rows
        $this->assertGreaterThanOrEqual(4, count($lines)); // header + 3 rows
    }
}

