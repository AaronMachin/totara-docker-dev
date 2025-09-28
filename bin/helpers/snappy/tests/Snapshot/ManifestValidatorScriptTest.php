<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;

final class ManifestValidatorScriptTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/snappy_test_val_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir()) { @rmdir($file->getPathname()); } else { @unlink($file->getPathname()); }
            }
            @rmdir($this->tmpDir);
        }
        putenv('SNAPPY_TDB_BIN');
        putenv('SNAPPY_TEST_BACKUP_PATH');
    }

    private function makeConfig(array $overrides = []): config_manager
    {
        $configFile = $this->tmpDir . '/config.json';
        $schemaFile = $this->tmpDir . '/config.schema.json';
        $schema = [ 'fields' => [ 'options.backup_path' => ['required' => false] ] ];
        file_put_contents($schemaFile, json_encode($schema, JSON_PRETTY_PRINT));
        $cfg = new config_manager($configFile, $this->tmpDir);
        foreach ($overrides as $k => $v) { $cfg->set($k, $v, false); }
        $cfg->save();
        return $cfg;
    }

    private function makeManager(string $backupPath): snapshot_manager
    {
        @mkdir($backupPath, 0777, true);
        $cfg = $this->makeConfig(['options.backup_path' => $backupPath]);
        $registry = new remote_registry($cfg, $this->tmpDir);
        return new snapshot_manager($registry);
    }

    public function testValidatorScriptPassesOnGeneratedManifest(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        @mkdir($backupPath, 0777, true);
        // fake tdb generator
        $script = $this->tmpDir . '/fake_tdb_val.php';
        $code = <<<'PHP'
#!/usr/bin/env php
<?php
$alias = 'unknown';
for ($i=1;$i<count($argv);$i++) { if ($argv[$i] === '--alias' && isset($argv[$i+1])) { $alias = $argv[$i+1]; break; } }
$backupDir = getenv('SNAPPY_TEST_BACKUP_PATH') ?: sys_get_temp_dir();
file_put_contents($backupDir . '/' . $alias . '.sql', "-- dummy dump for $alias\n");
exit(0);
PHP;
        file_put_contents($script, $code);
        @chmod($script, 0755);
        putenv('SNAPPY_TDB_BIN=' . $script);
        putenv('SNAPPY_TEST_BACKUP_PATH=' . $backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', 'validation test');
        $snapDir = $manager->local_path($uid);
        $manifest = $snapDir . '/manifest-v2.json';
        self::assertFileExists($manifest, 'manifest-v2.json should exist');
        $validator = realpath(__DIR__ . '/../../schema/validate_manifest.php');
        if (!$validator || !is_file($validator)) {
            $this->markTestSkipped('Validator script not found');
        }
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($manifest) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            $this->fail("Validator failed (exit $code) output:\n" . implode("\n", $output));
        }
        $json = json_decode(file_get_contents($manifest), true);
        self::assertSame(2, $json['schema_version']);
    }
}

