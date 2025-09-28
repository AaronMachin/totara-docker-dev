<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class GcObjectsTest extends InProcessCliTestCase {
    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) return; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir);
    }

    public function testDryRunListsOrphanObject(): void {
        putenv('SNAPPY_FAKE_DUMP_CONST=1');
        [$createOut,$code,$ctx] = $this->runInProcess('snapshot create --hash-store -m one', true);
        $this->assertSame(0,$code,$createOut);
        $tokens = array_values(array_filter(explode(' ', trim($createOut))));
        $uid = end($tokens);
        $base = $ctx->registry->local_base_path();
        $manifestPath = $base.'/snaps/'.$uid.'/manifest-v2.json';
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $fileEntry = $manifest['files'][0];
        $hash = $fileEntry['object_hash'] ?? '';
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$hash,'expected object hash');
        $objPath = $base.'/objects/sha256/'.substr($hash,0,2).'/'.$hash;
        $this->assertFileExists($objPath,'object file must exist');
        // Orphan object by deleting snapshot dir
        $this->recursiveDelete($base.'/snaps/'.$uid);
        $this->assertDirectoryDoesNotExist($base.'/snaps/'.$uid);
        // Run dry-run GC
        [$gcOut,$gcCode] = $this->runInProcess('gc objects');
        $this->assertSame(0,$gcCode,$gcOut);
        $this->assertStringContainsString('Dry run: would delete 1 object',$gcOut);
        $this->assertStringContainsString(substr($hash,0,8),$gcOut);
        putenv('SNAPPY_FAKE_DUMP_CONST');
    }

    public function testApplyRemovesOrphanObject(): void {
        putenv('SNAPPY_FAKE_DUMP_CONST=1');
        [$createOut,$code,$ctx] = $this->runInProcess('snapshot create --hash-store -m two', true);
        $this->assertSame(0,$code,$createOut);
        $tokens = array_values(array_filter(explode(' ', trim($createOut))));
        $uid = end($tokens);
        $base = $ctx->registry->local_base_path();
        $manifestPath = $base.'/snaps/'.$uid.'/manifest-v2.json';
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $fileEntry = $manifest['files'][0];
        $hash = $fileEntry['object_hash'];
        $objPath = $base.'/objects/sha256/'.substr($hash,0,2).'/'.$hash;
        $this->assertFileExists($objPath);
        $this->recursiveDelete($base.'/snaps/'.$uid);
        [$applyOut,$applyCode] = $this->runInProcess('gc objects --apply');
        $this->assertSame(0,$applyCode,$applyOut);
        $this->assertStringContainsString('Removed 1 orphan object',$applyOut);
        $this->assertFileDoesNotExist($objPath,'object should be deleted');
        putenv('SNAPPY_FAKE_DUMP_CONST');
    }

    public function testNoOpWhenNoOrphans(): void {
        [$createOut,$code] = $this->runInProcess('snapshot create --hash-store -m keep');
        $this->assertSame(0,$code,$createOut);
        [$gcOut,$gcCode] = $this->runInProcess('gc objects');
        $this->assertSame(0,$gcCode,$gcOut);
        $this->assertStringContainsString('No orphan objects found',$gcOut);
    }
}
