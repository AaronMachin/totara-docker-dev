<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class EdgeCasesTest extends InProcessCliTestCase {
    public function testLegacyCommandNameRemoved(): void {
        [$out,$code] = $this->runInProcess('snap -m test');
        $this->assertSame(1,$code);
    }
    public function testSnapshotShowUnknown(): void {
        [$show,$code] = $this->runInProcess('snapshot show doesnotexist');
        $this->assertSame(3,$code);
    }
    public function testConfigGetMissingPath(): void {
        [$out,$code] = $this->runInProcess('config get options.nonexistent.path');
        $this->assertSame(2,$code);
    }
}
