<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Support\Exception\ProcessFailedException;

final class SnapshotManagerProcessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/snappy_test_' . bin2hex(random_bytes(5));
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
                if ($file->isDir()) @rmdir($file->getPathname()); else @unlink($file->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        putenv('SNAPPY_TDB_BIN'); // unset
        putenv('SNAPPY_TEST_BACKUP_PATH');
    }

    private function makeConfig(array $overrides = []): config_manager
    {
        $configFile = $this->tmpDir . '/config.json';
        $schemaFile = $this->tmpDir . '/config.schema.json';
        // Minimal schema with backup_path default fallback if needed
        $schema = [
            'fields' => [
                'options.backup_path' => ['required' => false],
            ],
        ];
        file_put_contents($schemaFile, json_encode($schema, JSON_PRETTY_PRINT));
        $cfg = new config_manager($configFile, $this->tmpDir);
        foreach ($overrides as $k => $v) {
            $cfg->set($k, $v, false);
        }
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

    public function testCreateSqlBackupSuccess(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        @mkdir($backupPath, 0777, true);
        // Create fake tdb binary script
        $script = $this->tmpDir . '/fake_tdb.php';
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
        $uid = $manager->create('sql', 'test snapshot');
        $snapDir = $manager->local_path($uid);
        self::assertFileExists($snapDir . '/backup.sql');
        self::assertFileExists($snapDir . '/meta.json');
        $meta = json_decode(file_get_contents($snapDir . '/meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame($uid, $meta['uid']);
        self::assertSame(['backup.sql'], $meta['files']);
    }

    public function testCreateSqlBackupFailure(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        @mkdir($backupPath, 0777, true);
        // Point to non-existent binary to force failure
        putenv('SNAPPY_TDB_BIN=' . $this->tmpDir . '/does_not_exist');
        $manager = $this->makeManager($backupPath);
        $this->expectException(ProcessFailedException::class);
        try {
            $manager->create('sql', 'should fail');
        } catch (ProcessFailedException $e) {
            $result = $e->result();
            if ($result) {
                self::assertNotSame(0, $result->exitCode);
            }
            throw $e; // rethrow to satisfy expectException
        }
    }
}

