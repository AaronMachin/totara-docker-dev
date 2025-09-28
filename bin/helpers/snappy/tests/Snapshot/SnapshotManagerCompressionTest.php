<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;

final class SnapshotManagerCompressionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/snappy_test_comp_' . bin2hex(random_bytes(5));
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

    public function testCreateCompressedSqlBackup(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        @mkdir($backupPath, 0777, true);
        // fake tdb generator script
        $script = $this->tmpDir . '/fake_tdb_comp.php';
        $code = <<<'PHP'
#!/usr/bin/env php
<?php
$alias = 'unknown';
for ($i=1;$i<count($argv);$i++) { if ($argv[$i] === '--alias' && isset($argv[$i+1])) { $alias = $argv[$i+1]; break; } }
$backupDir = getenv('SNAPPY_TEST_BACKUP_PATH') ?: sys_get_temp_dir();
// create a larger-ish dump content to allow compression benefit
$data = str_repeat("INSERT INTO t VALUES ('row');\n", 2000);
file_put_contents($backupDir . '/' . $alias . '.sql', $data);
exit(0);
PHP;
        file_put_contents($script, $code);
        @chmod($script, 0755);
        putenv('SNAPPY_TDB_BIN=' . $script);
        putenv('SNAPPY_TEST_BACKUP_PATH=' . $backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', 'compressed snapshot test', 'local', true);
        $snapDir = $manager->local_path($uid);
        $compressed = $snapDir . '/backup.sql.gz';
        self::assertFileExists($compressed, 'Compressed dump should exist');
        self::assertFileDoesNotExist($snapDir . '/backup.sql');
        $meta = json_decode(file_get_contents($snapDir . '/meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame(['backup.sql.gz'], $meta['files']);
        self::assertArrayHasKey('backup.sql.gz', $meta['file_checksums']);
        $manifest = json_decode(file_get_contents($snapDir . '/manifest-v2.json'), true);
        self::assertTrue($manifest['compression']['enabled']);
        self::assertSame('gzip', $manifest['compression']['algo']);
        self::assertArrayHasKey('ratio', $manifest['compression']);
        self::assertGreaterThan(0, $manifest['compression']['original_size_bytes']);
        self::assertGreaterThan(0, $manifest['compression']['compressed_size_bytes']);
        // ratio should be >0; may be >1 if overhead, normally <1
        self::assertGreaterThan(0, $manifest['compression']['ratio']);
        $fileEntry = $manifest['files'][0];
        self::assertSame('backup.sql.gz', $fileEntry['name']);
        self::assertTrue($fileEntry['compressed']);
        self::assertSame('gzip', $fileEntry['compression_algo']);
    }
}

