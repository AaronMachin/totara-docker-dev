<?php
declare(strict_types=1);

require_once __DIR__ . '/../Cli/AbstractCliTestCase.php';

final class RemoteConfigTest extends AbstractCliTestCase {

    public function testAddListRemoveCycle(): void {
        $name = 'prod';
        $key = 'abcd1234KEYFULL';
        $secret = 'supersecretvalue';
        $cmd = sprintf('remote add %s --endpoint=https://s3.example.com --bucket=mybucket --region=us-east-1 --key=%s --secret=%s --path-style', $name, $key, $secret);
        $out = $this->runCli($cmd, $code);
        $this->assertSame(0, $code, 'add should succeed: '.$out);
        $this->assertStringContainsString('added remote '.$name, $out);
        $this->assertStringContainsString('key: '.substr($key,0,4), $out, 'redacted key prefix shown');
        $this->assertStringNotContainsString($secret, $out, 'secret must not appear in human output');
        // Config file contains full credentials
        $cfgFile = $this->extractConfigPath();
        $cfg = json_decode(file_get_contents($cfgFile), true);
        $this->assertSame($key, $cfg['remotes'][$name]['config']['key']);
        $this->assertSame($secret, $cfg['remotes'][$name]['config']['secret']);
        // List human
        $list = $this->runCli('remote list', $lcode);
        $this->assertSame(0, $lcode, 'list should succeed');
        $this->assertStringContainsString($name, $list);
        $this->assertStringContainsString(substr($key,0,4), $list, 'key prefix');
        $this->assertStringNotContainsString($key, $list, 'full key must be redacted');
        $this->assertStringNotContainsString($secret, $list, 'secret redacted');
        // JSON list (global flag first)
        $jsonOut = $this->runCli('--json remote list', $jcode);
        $this->assertSame(0, $jcode, 'json list success');
        $decoded = json_decode($jsonOut, true);
        $this->assertIsArray($decoded);
        $payload = $decoded['data']['payload'] ?? [];
        $this->assertArrayHasKey('remotes', $payload);
        $remotes = $payload['remotes'];
        $this->assertArrayHasKey($name, $remotes);
        $this->assertArrayHasKey('endpoint', $remotes[$name]);
        $this->assertArrayNotHasKey('key', $remotes[$name], 'key omitted in JSON list');
        $this->assertArrayNotHasKey('secret', $remotes[$name], 'secret omitted in JSON list');
        // Remove
        $rmOut = $this->runCli('remote remove '.$name, $rmCode);
        $this->assertSame(0, $rmCode, 'remove success: '.$rmOut);
        // List now empty
        $after = $this->runCli('remote list', $afterCode);
        $this->assertSame(0, $afterCode);
        $this->assertStringContainsString('(none)', $after);
        // Removing again should fail with remote exception code 4
        $again = $this->runCli('remote remove '.$name, $againCode);
        $this->assertSame(4, $againCode, 'remove unknown should use RemoteException code');
    }

    public function testDuplicateAddError(): void {
        $cmd = 'remote add dup --endpoint=http://h --bucket=b --key=kkkk --secret=ssss';
        $this->runCli($cmd, $firstCode);
        $this->assertSame(0, $firstCode, 'initial add ok');
        $out2 = $this->runCli($cmd, $secondCode);
        $this->assertSame(2, $secondCode, 'duplicate should be validation error code 2: '.$out2);
    }

    public function testInvalidNamePattern(): void {
        $out = $this->runCli('remote add BADNAME --endpoint=http://h --bucket=b --key=kkkk --secret=ssss', $code);
        $this->assertSame(2, $code, 'invalid name should be validation error');
        $this->assertStringContainsString('invalid name pattern', $out);
    }

    public function testMissingRequiredFlags(): void {
        $out = $this->runCli('remote add short --endpoint=http://h --bucket=b', $code); // missing key & secret
        $this->assertSame(64, $code, 'missing required flags should yield usage error 64');
        $this->assertStringContainsString('missing required flags', $out);
    }

    private function extractConfigPath(): string {
        // Parse env prefix to find config file path used in AbstractCliTestCase
        foreach (explode(' ', $this->envPrefix) as $chunk) {
            if (str_starts_with($chunk, 'SNAPPY_CONFIG_FILE=')) {
                $path = trim(substr($chunk, strlen('SNAPPY_CONFIG_FILE=')), "'\"");
                return $path;
            }
        }
        $this->fail('config path not found');
    }
}
