<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class OutputModesTest extends AbstractCliTestCase {
    public function testSnapshotListJson(): void {
        // create a couple snapshots first
        $this->runCli('snapshot create -m one');
        $this->runCli('snapshot create -m two');
        $out = $this->runCli('--json snapshot list', $code);
        $this->assertSame(0, $code, 'snapshot list should succeed');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertSame('snapshot.list', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('payload', $decoded['data'], 'payload must exist with snapshots array');
        $this->assertArrayHasKey('snapshots', $decoded['data']['payload']);
        $this->assertGreaterThanOrEqual(2, count($decoded['data']['payload']['snapshots']));
    }

    public function testSnapshotListQuietSuppressesTable(): void {
        // ensure at least one snapshot
        $this->runCli('snapshot create -m quiettest');
        $out = $this->runCli('--quiet snapshot list', $code);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('UID', $out, 'quiet mode should suppress table header');
        $this->assertStringNotContainsString('quiettest', $out, 'quiet mode should suppress snapshot rows');
    }

    public function testQuietHasNoEffectOnJson(): void {
        $this->runCli('snapshot create -m bothflags');
        $out = $this->runCli('--json --quiet snapshot list', $code);
        $this->assertSame(0, $code);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('snapshot.list', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
    }

    public function testJsonErrorShapeForValidation(): void {
        $out = $this->runCli('--json snapshot create --type=unknown -m bad', $code);
        $this->assertSame(2, $code, 'unknown snapshot type should map to exit code 2');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertNotEmpty($decoded['errors']);
        $first = $decoded['errors'][0];
        $this->assertEquals(2, $first['code']);
    }

    public function testNoColorFlagDoesNotBreakOutput(): void {
        $this->runCli('snapshot create -m color1');
        $out = $this->runCli('--no-color snapshot list', $code);
        $this->assertSame(0, $code);
        // We do not currently output color codes in table; ensure no escape sequences present
        $this->assertStringNotContainsString("\033[", $out, 'no ANSI sequences expected');
    }

    public function testQuietDoesNotSuppressErrors(): void {
        $out = $this->runCli('--quiet config get options.nonexistent.value', $code);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('ERROR(2):', $out, 'error line should still appear');
    }

    public function testRemoteListJsonEmpty(): void {
        $out = $this->runCli('--json remote list', $code);
        $this->assertSame(0, $code);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('remote.list', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('payload', $decoded['data']);
        $this->assertArrayHasKey('remotes', $decoded['data']['payload']);
        $this->assertEquals([], $decoded['data']['payload']['remotes']);
    }
}
