<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;

final class MetadataCreationTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir().'/snappy_meta_create_'.bin2hex(random_bytes(4));
        @mkdir($this->root,0777,true);
        putenv('SNAPPY_FAKE_DUMP=1');
    }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); }

    private function recursiveDelete(string $dir): void {
        if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); }
        @rmdir($dir);
    }

    private function manager(): snapshot_manager {
        $cfg = new config_manager($this->root.'/config.json',$this->root); $cfg->save();
        $reg = new remote_registry($cfg,$this->root);
        return new snapshot_manager($reg);
    }

    public function testMetadataSummaryGeneratedOnCreate(): void {
        $m = $this->manager();
        $uid = $m->create('sql','meta summary test');
        $snapDir = $m->local_path($uid);
        $summary = $snapDir.'/metadata/summary.json';
        $this->assertFileExists($summary,'summary.json not generated');
        $raw = file_get_contents($summary);
        $this->assertNotFalse($raw);
        $data = json_decode($raw,true);
        $this->assertIsArray($data);
        $this->assertSame($uid, $data['uid'] ?? null);
        $this->assertSame('sql', $data['snapshot_type'] ?? null);
        $this->assertSame(1, $data['file_count'] ?? -1, 'expected single backup file count');
        $this->assertGreaterThan(0, $data['total_size_bytes'] ?? 0);
        $this->assertArrayHasKey('schema_version',$data);
    }

    public function testMetadataSummaryGeneratedOnCompressedCreate(): void {
        $m = $this->manager();
        $uid = $m->create('sql','meta summary compressed', 'local', true);
        $snapDir = $m->local_path($uid);
        $summary = $snapDir.'/metadata/summary.json';
        $this->assertFileExists($summary,'summary.json not generated (compressed)');
        $data = json_decode((string)file_get_contents($summary),true);
        $this->assertIsArray($data);
        $this->assertSame($uid, $data['uid'] ?? null);
        $this->assertSame('sql', $data['snapshot_type'] ?? null);
        $this->assertSame(1, $data['file_count'] ?? -1, 'expected single compressed backup file count');
        $this->assertGreaterThan(0, $data['total_size_bytes'] ?? 0);
        $this->assertTrue(($data['compression'] ?? false) === true, 'compression flag should be true');
    }
}

