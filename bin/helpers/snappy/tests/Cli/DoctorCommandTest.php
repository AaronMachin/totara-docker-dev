<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';
require_once __DIR__ . '/AbstractCliTestCase.php';

final class DoctorCommandTest extends InProcessCliTestCase {
    public function testDoctorEmptyEnvironment(): void {
        [$out,$code] = $this->runInProcess('doctor run', true);
        $this->assertSame(0,$code,$out);
        $this->assertStringContainsString('System diagnostics:', $out);
        $this->assertStringContainsString('Integrity', $out);
        $this->assertStringContainsString('SKIP', $out, 'Expected integrity SKIP when no snapshots');
        $this->assertStringContainsString('Index', $out);
    }

    public function testDoctorCorruptIndexTriggersFail(): void {
        // create snapshot to produce index
        [$createOut,$createCode,$ctx] = $this->runInProcess('snapshot create -m one', true);
        $this->assertSame(0,$createCode,$createOut);
        $indexPath = rtrim($ctx->registry->local_base_path(),'/').'/snaps/index.json';
        $this->assertFileExists($indexPath,'index should exist after snapshot');
        // Corrupt index
        file_put_contents($indexPath, '{not-json');
        [$out,$code] = $this->runInProcess('doctor run');
        $this->assertSame(2,$code,$out); // non-zero on FAIL
        $this->assertStringContainsString('Index', $out);
        $this->assertStringContainsString('FAIL', $out,'Index should report FAIL');
    }

    public function testDoctorIntegrityFailure(): void {
        [$createOut,$createCode,$ctx] = $this->runInProcess('snapshot create -m initial', true);
        $this->assertSame(0,$createCode,$createOut);
        // Determine snapshot uid
        $rows = $ctx->manager->list('local', false, 10);
        $this->assertNotEmpty($rows);
        $uid = $rows[0]['uid'];
        $snapDir = $ctx->registry->local_base_path().'/snaps/'.$uid;
        $file = $snapDir.'/backup.sql';
        $this->assertFileExists($file,'snapshot data file exists');
        @unlink($file); // remove to cause integrity failure
        $this->assertFileDoesNotExist($file,'file removed');
        [$out,$code] = $this->runInProcess('doctor run');
        $this->assertSame(2,$code,$out);
        $this->assertStringContainsString('Integrity', $out);
        $this->assertStringContainsString('FAIL', $out,'Integrity should FAIL after deletion');
    }

    public function testDoctorJsonMode(): void {
        // use external cli wrapper for json to exercise output_formatter
        $php = escapeshellcmd((string)PHP_BINARY);
        $cli = escapeshellarg(__DIR__.'/../../tsnap_cli.php');
        $tmpRoot = sys_get_temp_dir().'/snappy_doctor_json_'.bin2hex(random_bytes(4));
        $cfgDir = $tmpRoot.'/cfg'; $snapDir = $tmpRoot.'/snaps'; $provDir = $tmpRoot.'/prov';
        @mkdir($cfgDir,0777,true); @mkdir($snapDir,0777,true); @mkdir($provDir,0777,true);
        $configFile = $cfgDir.'/config.json';
        // run doctor
        $env = 'SNAPPY_CONFIG_FILE='.escapeshellarg($configFile).' SNAPPY_SNAPSHOT_BASE='.escapeshellarg($snapDir).' SNAPPY_PROVISIONAL_BASE='.escapeshellarg($provDir);
        $cmd = $env.' '.$php.' '.$cli.' --json doctor run 2>&1';
        exec($cmd, $lines, $exit);
        $raw = implode("\n", $lines);
        $this->assertSame(0,$exit,$raw);
        $decoded = json_decode($raw,true);
        $this->assertIsArray($decoded,$raw);
        $this->assertSame('doctor.run',$decoded['command'] ?? null);
        $payload = $decoded['data']['payload'] ?? [];
        $this->assertArrayHasKey('diagnostics',$payload);
        $this->assertArrayHasKey('summary',$payload);
        $diag = $payload['diagnostics'];
        $foundIntegrity = false; foreach ($diag as $d) { if (($d['check'] ?? '')==='Integrity') { $foundIntegrity = true; } }
        $this->assertTrue($foundIntegrity,'Integrity check present');
    }
}

