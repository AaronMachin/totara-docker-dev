<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

final class ShareTokenTest extends AbstractCliTestCase {
    public function testShareCreateStoresHashedRecord(): void {
        // create snapshot
        $out1 = $this->runCli('snapshot create -m sharetest', $code1);
        $this->assertSame(0, $code1, 'snapshot create should succeed');
        // extract uid from JSON? run with --json to simplify
        $outJson = $this->runCli('--json snapshot create -m sharetest2', $code2);
        $this->assertSame(0, $code2);
        $decoded = json_decode($outJson, true);
        $this->assertIsArray($decoded);
        $uid = $decoded['data']['payload']['uid'] ?? '';
        $this->assertNotSame('', $uid, 'uid should be present');
        // create share token with 1h expiry
        $outShare = $this->runCli('--json share create ' . $uid . ' --expire=1h', $code3);
        $this->assertSame(0, $code3, 'share create must succeed');
        $shareDecoded = json_decode($outShare, true);
        $this->assertIsArray($shareDecoded);
        $payload = $shareDecoded['data']['payload'] ?? [];
        $rawToken = $payload['share_token'] ?? '';
        $this->assertNotSame('', $rawToken, 'share token should be returned');
        $hash = hash('sha256', $rawToken);
        $snapBase = $this->tmpRoot . '/snaps';
        // share_registry normalizes snapshot base to parent of /snaps
        $rootBase = $this->tmpRoot; // parent directory
        $sharesFile = $rootBase . '/.snappy/shares.json';
        $this->assertFileExists($sharesFile, 'shares.json should exist');
        $fileContent = file_get_contents($sharesFile);
        $this->assertIsString($fileContent);
        $this->assertStringNotContainsString($rawToken, $fileContent, 'raw token must not be stored');
        $json = json_decode($fileContent, true);
        $this->assertIsArray($json);
        $found = false; $rec = null;
        foreach ($json['tokens'] as $t) { if (($t['token_hash'] ?? '') === $hash) { $found = true; $rec = $t; break; } }
        $this->assertTrue($found, 'hashed token record must exist');
        $this->assertSame($uid, $rec['uid']);
        $this->assertArrayHasKey('expires_utc', $rec);
        $this->assertArrayHasKey('created_utc', $rec);
        // expiry should be in future
        $expTs = strtotime($rec['expires_utc']);
        $this->assertNotFalse($expTs);
        $this->assertGreaterThan(time(), $expTs, 'expiry must be future');
    }

    public function testExpireParsingThirtyMinutes(): void {
        $out = $this->runCli('--json snapshot create -m msg', $code1);
        $this->assertSame(0, $code1);
        $uid = (json_decode($out, true)['data']['payload']['uid']) ?? '';
        $shareOut = $this->runCli('--json share create ' . $uid . ' --expire=30m', $code2);
        $this->assertSame(0, $code2);
        $shareDecoded = json_decode($shareOut, true);
        $token = $shareDecoded['data']['payload']['share_token'] ?? '';
        $this->assertNotSame('', $token);
        $snapBase = $this->tmpRoot . '/snaps';
        $rootBase = $this->tmpRoot;
        $sharesFile = $rootBase . '/.snappy/shares.json';
        $data = json_decode((string)file_get_contents($sharesFile), true);
        $this->assertIsArray($data);
        $hash = hash('sha256', $token);
        $rec = null; foreach ($data['tokens'] as $t) { if ($t['token_hash'] === $hash) { $rec = $t; break; } }
        $this->assertNotNull($rec);
        $created = strtotime($rec['created_utc']);
        $expires = strtotime($rec['expires_utc']);
        $this->assertIsInt($created);
        $this->assertIsInt($expires);
        $diff = $expires - $created;
        $this->assertGreaterThanOrEqual(1700, $diff, 'diff should be close to 1800s (>=1700)');
        $this->assertLessThanOrEqual(1900, $diff, 'diff should be close to 1800s (<=1900)');
    }

    public function testInvalidExpireRejected(): void {
        $out = $this->runCli('--json snapshot create -m base', $c1);
        $this->assertSame(0, $c1);
        $uid = (json_decode($out, true)['data']['payload']['uid']) ?? '';
        $outBad = $this->runCli('--json share create ' . $uid . ' --expire=0', $c2);
        $this->assertSame(2, $c2, 'expire=0 should be rejected');
        $decoded = json_decode($outBad, true);
        $this->assertEquals('error', $decoded['status']);
    }
}
