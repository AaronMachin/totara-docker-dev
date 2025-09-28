<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class OutputModesExtendedTest extends AbstractCliTestCase {
    public function testSnapshotCreateJsonPayload(): void {
        $out = $this->runCli('--json snapshot create -m createjson');
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame('snapshot.create', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
        $payload = $decoded['data']['payload'] ?? [];
        $this->assertArrayHasKey('uid', $payload);
        $this->assertSame('sql', $payload['type']);
    }

    public function testSnapshotShowJsonPayload(): void {
        $create = $this->runCli('--json snapshot create -m showjson');
        $c = json_decode($create, true);
        $uid = $c['data']['payload']['uid'];
        $out = $this->runCli('--json snapshot show ' . $uid);
        $decoded = json_decode($out, true);
        $this->assertSame('snapshot.show', $decoded['command']);
        $this->assertSame('ok', $decoded['status']);
        $manifest = $decoded['data']['payload']['manifest'] ?? [];
        $this->assertSame($uid, $manifest['uid']);
    }

    public function testSnapshotShowJsonErrorUnknown(): void {
        $out = $this->runCli('--json snapshot show doesnotexist');
        $d = json_decode($out, true);
        $this->assertSame('error', $d['status']);
        $this->assertNotEmpty($d['errors']);
    }

    public function testVerifyJson(): void {
        $create = $this->runCli('--json snapshot create -m verifyjson');
        $c = json_decode($create, true);
        $uid = $c['data']['payload']['uid'];
        $verify = $this->runCli('--json verify run ' . $uid);
        $d = json_decode($verify, true);
        $this->assertSame('verify.run', $d['command']);
        $this->assertSame('ok', $d['status']);
        $this->assertSame('ok', $d['data']['payload']['status']);
    }

    public function testPruneJson(): void {
        $this->runCli('snapshot create -m prune1');
        $this->runCli('snapshot create -m prune2');
        $out = $this->runCli('--json prune run --keep=0');
        $d = json_decode($out, true);
        $this->assertSame('prune.run', $d['command']);
        $this->assertSame('ok', $d['status']);
        $p = $d['data']['payload'];
        $this->assertSame(0, $p['kept']);
        $this->assertGreaterThanOrEqual(2, $p['deleted']);
    }

    public function testConfigSetGetJson(): void {
        $set = $this->runCli('--json config set options.test.flag true --persist');
        $d = json_decode($set, true);
        $this->assertSame('config.set', $d['command']);
        $this->assertTrue((bool)$d['data']['payload']['value']);
        $get = $this->runCli('--json config get options.test.flag');
        $g = json_decode($get, true);
        $this->assertSame('config.get', $g['command']);
        $this->assertTrue((bool)$g['data']['payload']['value']);
    }

    public function testConfigGetFullJson(): void {
        $out = $this->runCli('--json config get');
        $d = json_decode($out, true);
        $this->assertSame('config.get', $d['command']);
        $this->assertArrayHasKey('value', $d['data']['payload']);
        $this->assertIsArray($d['data']['payload']['value']);
    }

    public function testQuietSuppressesSnapshotCreate(): void {
        $out = $this->runCli('--quiet snapshot create -m quietcreate', $code);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('created snapshot', $out);
    }

    public function testQuietSuppressesPrune(): void {
        $this->runCli('snapshot create -m qprune1');
        $out = $this->runCli('--quiet prune run --keep=1', $code);
        $this->assertSame(0, $code);
        $this->assertSame('', trim($out), 'prune summary should be suppressed');
    }

    public function testRemoteAddListRemoveJson(): void {
        $add = $this->runCli('--json remote add mem1 memory');
        $a = json_decode($add, true);
        $this->assertSame('remote.add', $a['command']);
        $list = $this->runCli('--json remote list');
        $l = json_decode($list, true);
        $names = array_keys($l['data']['payload']['remotes']);
        $this->assertContains('mem1', $names);
        $remove = $this->runCli('--json remote remove mem1');
        $r = json_decode($remove, true);
        $this->assertSame('remote.remove', $r['command']);
        $list2 = $this->runCli('--json remote list');
        $l2 = json_decode($list2, true);
        $this->assertArrayNotHasKey('mem1', $l2['data']['payload']['remotes']);
    }

    public function testRemoteAddUnsupportedType(): void {
        $out = $this->runCli('--json remote add test local'); // local disallowed
        $d = json_decode($out, true);
        $this->assertSame('error', $d['status']);
        $this->assertNotEmpty($d['errors']);
    }

    public function testSnapshotListMessageTruncation(): void {
        $long = str_repeat('X', 140);
        $this->runCli('snapshot create -m ' . $long);
        $out = $this->runCli('snapshot list', $code);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('XXX', $out); // ensure presence
        $this->assertStringContainsString('...', $out);
        $this->assertStringNotContainsString($long, $out, 'long message should be truncated in non-full mode');
        $full = $this->runCli('snapshot list --full');
        $this->assertStringContainsString($long, $full);
    }
}
