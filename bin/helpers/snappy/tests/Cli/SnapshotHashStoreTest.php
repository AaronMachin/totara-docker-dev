<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotHashStoreTest extends InProcessCliTestCase {
    private function manifest(string $base, string $uid): array {
        $p = $base.'/snaps/'.$uid.'/manifest-v2.json';
        return json_decode((string)file_get_contents($p), true);
    }

    public function testHashStoreCreatesObjectAndReferences(): void {
        putenv('SNAPPY_FAKE_DUMP_CONST=1');
        [$out,$code,$ctx] = $this->runInProcess('snapshot create --hash-store -m one');
        $this->assertSame(0,$code,$out);
        $tokens = array_values(array_filter(explode(' ', trim($out))));
        $uid = end($tokens);
        $base = $ctx->registry->local_base_path();
        $manifest = $this->manifest($base,$uid);
        $this->assertArrayHasKey('files',$manifest);
        $fileEntry = $manifest['files'][0];
        $this->assertArrayHasKey('object_hash',$fileEntry);
        $hash = $fileEntry['object_hash'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$hash);
        $this->assertArrayHasKey('stored_inline',$fileEntry);
        $this->assertFalse($fileEntry['stored_inline']);
        $objPath = $base.'/objects/sha256/'.substr($hash,0,2).'/'.$hash;
        $this->assertFileExists($objPath);
        $snapFile = $base.'/snaps/'.$uid.'/backup.sql';
        $this->assertFileExists($snapFile);
        $this->assertSame(filesize($objPath), filesize($snapFile));
        // one object only
        $objects = glob($base.'/objects/sha256/*/*');
        $this->assertCount(1,$objects,'Expected single stored object');
        putenv('SNAPPY_FAKE_DUMP_CONST');
    }

    public function testSecondSnapshotReusesObject(): void {
        putenv('SNAPPY_FAKE_DUMP_CONST=1');
        [$out1,$code1,$ctx] = $this->runInProcess('snapshot create --hash-store -m first');
        $this->assertSame(0,$code1,$out1);
        [$out2,$code2] = $this->runInProcess('snapshot create --hash-store -m second');
        $this->assertSame(0,$code2,$out2);
        $tokens1 = array_values(array_filter(explode(' ', trim($out1))));
        $tokens2 = array_values(array_filter(explode(' ', trim($out2))));
        $uid1 = end($tokens1); $uid2 = end($tokens2);
        $base = $ctx->registry->local_base_path();
        $m1 = $this->manifest($base,$uid1); $m2 = $this->manifest($base,$uid2);
        $h1 = $m1['files'][0]['object_hash']; $h2 = $m2['files'][0]['object_hash'];
        $this->assertSame($h1,$h2,'Object hash should be identical for deterministic dump');
        $objects = glob($base.'/objects/sha256/*/*');
        $this->assertCount(1,$objects,'Should still be single object after second snapshot');
        putenv('SNAPPY_FAKE_DUMP_CONST');
    }
}
