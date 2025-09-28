<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_index_manager;
use Snappy\Snapshot\index_manager;
use Snappy\Snapshot\remote_snapshot_cache;

final class RemoteIndexManagerTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/snappy_remote_index_' . bin2hex(random_bytes(5));
        @mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void {
        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
            @rmdir($this->root);
        }
        putenv('SNAPPY_TDB_BIN');
        putenv('SNAPPY_TEST_BACKUP_PATH');
    }

    private function makeRegistry(): remote_registry {
        $configFile = $this->root . '/config.json';
        $cfg = new config_manager($configFile, $this->root);
        // configure backup path for dump provider discovery
        $backupDir = $this->root . '/backups';
        @mkdir($backupDir, 0777, true);
        $cfg->set('options.backup_path', $backupDir, false);
        $cfg->save();
        return new remote_registry($cfg, $this->root);
    }

    private function makeManager(remote_registry $registry): snapshot_manager {
        $manager = new snapshot_manager($registry);
        $cache = new remote_snapshot_cache($this->root);
        $manager->set_cache($cache);
        $index = new index_manager($this->root);
        $manager->set_index($index);
        $manager->set_remote_index(new remote_index_manager($registry));
        return $manager;
    }

    private function createLocalSnapshot(snapshot_manager $manager, string $message): string {
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
        file_put_contents($script, $code); @chmod($script, 0755);
        putenv('SNAPPY_TDB_BIN=' . $script);
        $dumpDir = $this->root . '/backups'; @mkdir($dumpDir, 0777, true); putenv('SNAPPY_TEST_BACKUP_PATH=' . $dumpDir);
        return $manager->create('sql', $message);
    }

    public function testRemoteIndexUpdatedOnPushAndUsedForList(): void {
        $registry = $this->makeRegistry();
        $registry->add('mem1', 'memory', []); // fake in-memory remote
        $manager = $this->makeManager($registry);
        $uid = $this->createLocalSnapshot($manager, "hello world\nsecond line");
        $manager->push($uid, 'mem1', 'local');
        $rows = $manager->list('mem1'); // should use remote index fast path
        $found = null; foreach ($rows as $r) { if ($r['uid'] === $uid) { $found = $r; break; } }
        self::assertNotNull($found, 'Snapshot entry expected in remote listing via index');
        self::assertSame('hello world', $found['message']); // only first line
    }

    public function testRemoteIndexFallbackWhenMissing(): void {
        $registry = $this->makeRegistry();
        $registry->add('mem2', 'memory', []);
        $manager = $this->makeManager($registry);
        $uid = $this->createLocalSnapshot($manager, 'abc');
        $manager->push($uid, 'mem2', 'local');
        // Remove index to force fallback
        $storage = $registry->storage('mem2');
        if (method_exists($storage, 'delete')) { $storage->delete('snaps/index.json'); }
        $rows = $manager->list('mem2'); // will scan meta.json
        $seen = false; foreach ($rows as $r) { if ($r['uid'] === $uid) { $seen = true; break; } }
        self::assertTrue($seen, 'Snapshot should still be listed via scan fallback after index deletion');
    }
}
