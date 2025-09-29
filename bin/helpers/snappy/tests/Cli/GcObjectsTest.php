<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class GcObjectsTest extends InProcessCliTestCase {
    public function testGcNoObjectsDirectory(): void {
        [$createOut,$createCode] = $this->runInProcess('snapshot create -m one', true);
        $this->assertSame(0,$createCode,$createOut);
        [$gcOut,$gcCode] = $this->runInProcess('gc objects');
        $this->assertSame(0,$gcCode,$gcOut);
        $this->assertStringContainsString('No objects directory', $gcOut);
    }
    public function testGcApplyNoOp(): void {
        [$gcOut,$gcCode] = $this->runInProcess('gc objects --apply', true);
        $this->assertSame(0,$gcCode,$gcOut);
    }
}
