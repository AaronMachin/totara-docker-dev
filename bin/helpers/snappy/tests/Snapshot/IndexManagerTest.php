<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\index_manager;

final class IndexManagerTest extends TestCase
{
    private string $root;
    private string $backupPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/snappy_test_index_' . bin2hex(random_bytes(5));
        $this->backupPath = $this->root . '/backups';
        @mkdir($this->backupPath, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($this->root);
        }
        putenv('SNAPPY_TDB_BIN');
        putenv('SNAPPY_TEST_BACKUP_PATH');
    }

    private function makeConfig(): config_manager
    {
        $configFile = $this->root . '/config.json';
        $cfg = new config_manager($configFile, $this->root);
        $cfg->set('options.backup_path', $this->backupPath, false);
        $cfg->save();
        return $cfg;
    }

    private function makeFakeDump(): void
    {
        @mkdir($this->backupPath, 0777, true);
        $script = $this->root . '/fake_tdb.php';
        $code = <<<'PHP'
#!/usr/bin/env php
<?php
$alias = 'a';
for ($i=1;$i<count($argv);$i++) { if ($argv[$i]==='--alias' && isset($argv[$i+1])) { $alias=$argv[$i+1]; break; } }
$destDir = getenv('SNAPPY_TEST_BACKUP_PATH') ?: sys_get_temp_dir();
file_put_contents($destDir . '/' . $alias . '.sql', "-- dump for $alias\n");
exit(0);
PHP;
        file_put_contents($script, $code);
        @chmod($script, 0755);
        putenv('SNAPPY_TDB_BIN=' . $script);
        putenv('SNAPPY_TEST_BACKUP_PATH=' . $this->backupPath);
    }

    private function makeManager(?index_manager &$indexOut = null): snapshot_manager
    {
        $cfg = $this->makeConfig();
        $registry = new remote_registry($cfg, $this->root);
        $manager = new snapshot_manager($registry);
        $index = new index_manager($this->root);
        $manager->set_index($index);
        $indexOut = $index;
        return $manager;
    }

    public function testIndexCreatedOnSnapshotCreation(): void
    {
        $this->makeFakeDump();
        $manager = $this->makeManager($index);
        $uid1 = $manager->create('sql', "first message\nsecond line");
        $uid2 = $manager->create('sql', 'second message');
        $idxFile = $this->root . '/snaps/index.json';
        self::assertFileExists($idxFile, 'index.json should be created');
        $json = json_decode(file_get_contents($idxFile), true);
        self::assertIsArray($json);
        self::assertSame(1, $json['version']);
        $uids = array_map(fn($r)=>$r['uid'], $json['snapshots']);
        self::assertContains($uid1, $uids);
        self::assertContains($uid2, $uids);
        $row1 = null; foreach ($json['snapshots'] as $r) { if ($r['uid']===$uid1) { $row1=$r; break; } }
        self::assertNotNull($row1);
        self::assertStringNotContainsString('second line', $row1['message_first']); // only first line stored
    }

    public function testRebuildRemovesDeletedSnapshot(): void
    {
        $this->makeFakeDump();
        $manager = $this->makeManager($index);
        $uid1 = $manager->create('sql', 'alpha');
        $uid2 = $manager->create('sql', 'beta');
        // delete one directory manually
        $dir2 = $manager->local_path($uid2);
        $this->recursiveDelete($dir2);
        // index still has it
        $before = $index->load();
        $uidsBefore = array_map(fn($r)=>$r['uid'], $before['snapshots']);
        self::assertContains($uid2, $uidsBefore);
        $index->rebuild();
        $after = $index->load();
        $uidsAfter = array_map(fn($r)=>$r['uid'], $after['snapshots']);
        self::assertContains($uid1, $uidsAfter);
        self::assertNotContains($uid2, $uidsAfter);
    }

    public function testCorruptIndexAutoRebuild(): void
    {
        $this->makeFakeDump();
        $manager = $this->makeManager($index);
        $uid = $manager->create('sql', 'gamma');
        $idxFile = $this->root . '/snaps/index.json';
        file_put_contents($idxFile, '{corrupt');
        $list = $manager->list('local'); // should trigger auto rebuild
        $found = false; foreach ($list as $r) { if ($r['uid']===$uid) { $found=true; break; } }
        self::assertTrue($found, 'List should still return snapshot after corrupt index');
        $json = json_decode(file_get_contents($idxFile), true);
        self::assertIsArray($json, 'Index file should be repaired');
        self::assertSame(1, $json['version']);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
