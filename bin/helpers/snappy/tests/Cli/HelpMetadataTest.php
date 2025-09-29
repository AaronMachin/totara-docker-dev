<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class HelpMetadataTest extends AbstractCliTestCase {
    public function testJsonHelpListsAllCommandsWithExamplesAndGroups(): void {
        $out = $this->runCli('--json help', $code);
        $this->assertSame(0, $code, 'help command should succeed');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'help output must be JSON');
        $this->assertSame('help', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('payload', $decoded['data']);
        $payload = $decoded['data']['payload'];
        $this->assertArrayHasKey('commands', $payload);
        $commands = $payload['commands'];
        $this->assertIsArray($commands);
        // Index by name for quick lookup
        $map = [];
        foreach ($commands as $entry) { $map[$entry['name']] = $entry; }
        // Ensure a few representative commands exist
        foreach (['snapshot.create','snapshot.list','remote.add','gc.objects','config.get'] as $required) {
            $this->assertArrayHasKey($required, $map, "missing command metadata for $required");
            $this->assertNotEmpty($map[$required]['examples'], "$required should have examples");
            $this->assertNotEmpty($map[$required]['group'], "$required should have a group");
        }
        // Group expectations
        $this->assertSame('Snapshot', $map['snapshot.create']['group']);
        $this->assertSame('Remote', $map['remote.add']['group']);
        $this->assertSame('Config', $map['config.get']['group']);
    }

    public function testTextHelpShowsGroupedSections(): void {
        $out = $this->runCli('help', $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[Snapshot]', $out);
        $this->assertStringContainsString('[Remote]', $out);
        $this->assertStringContainsString('[Maintenance]', $out);
        $this->assertStringContainsString('[Config]', $out);
        $this->assertStringContainsString('snapshot create', $out);
    }
}
