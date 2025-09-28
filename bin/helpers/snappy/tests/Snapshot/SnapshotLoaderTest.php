<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\SnapshotLoader;

final class SnapshotLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/snappy_test_loader_' . bin2hex(random_bytes(5));
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

    private function prepareFakeTdb(string $backupPath): void
    {
        @mkdir($backupPath, 0777, true);
        $script = $this->tmpDir . '/fake_tdb_loader.php';
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
    }

    public function testLoadPrefersManifestV2(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        $this->prepareFakeTdb($backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', "message A\nsecond line");
        $loader = new SnapshotLoader();
        $manifest = $loader->load_local($uid, $this->tmpDir);
        self::assertNotNull($manifest);
        self::assertSame(2, $manifest['raw_version']);
        self::assertArrayHasKey('created_utc', $manifest);
        self::assertArrayHasKey('created', $manifest);
        // created alias should end with +00:00 when manifest uses Z
        if (str_ends_with($manifest['created_utc'], 'Z')) {
            self::assertStringEndsWith('+00:00', $manifest['created']);
        }
    }

    public function testFallbackToLegacyMetaWhenManifestMissing(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        $this->prepareFakeTdb($backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', 'message B');
        $snapDir = $manager->local_path($uid);
        @unlink($snapDir . '/manifest-v2.json'); // simulate missing manifest
        $loader = new SnapshotLoader();
        $manifest = $loader->load_local($uid, $this->tmpDir);
        self::assertNotNull($manifest);
        self::assertSame(1, $manifest['raw_version']);
    }

    public function testCorruptManifestFallsBackToLegacy(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        $this->prepareFakeTdb($backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', 'message C');
        $snapDir = $manager->local_path($uid);
        file_put_contents($snapDir . '/manifest-v2.json', '{'); // corrupt
        $loader = new SnapshotLoader();
        $manifest = $loader->load_local($uid, $this->tmpDir);
        self::assertNotNull($manifest);
        self::assertSame(1, $manifest['raw_version']);
    }

    public function testListUsesCreatedAliasAfterManifestRemoval(): void
    {
        $backupPath = $this->tmpDir . '/backups';
        $this->prepareFakeTdb($backupPath);
        $manager = $this->makeManager($backupPath);
        $uid = $manager->create('sql', 'message D');
        $snapDir = $manager->local_path($uid);
        $metaJson = json_decode(file_get_contents($snapDir . '/meta.json'), true);
        $metaCreated = $metaJson['created'];
        @unlink($snapDir . '/manifest-v2.json');
        $list = $manager->list('local');
        $row = null;
        foreach ($list as $r) { if ($r['uid'] === $uid) { $row = $r; break; } }
        self::assertNotNull($row, 'Snapshot row should exist');
        self::assertSame($metaCreated, $row['created']);
    }
}

