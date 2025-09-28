<?php
declare(strict_types=1);

require_once __DIR__ . '/InProcessCliTestCase.php';

final class SnapshotTagTest extends InProcessCliTestCase {
    private function extractUid(string $output): string {
        $line = trim($output);
        $pos = strrpos($line, ' ');
        return $pos !== false ? trim(substr($line, $pos+1)) : $line;
    }

    public function testAddAndRemoveTagLifecycle(): void {
        [$create,$code,$ctx] = $this->runInProcess('snapshot create -m tagtest');
        $this->assertSame(0,$code, $create);
        $uid = $this->extractUid($create);
        $this->assertNotEmpty($uid);

        // Add tag
        [$add,$addCode] = $this->runInProcess('snapshot tag ' . $uid . ' add release_1');
        $this->assertSame(0,$addCode, $add);
        $this->assertStringContainsString('tag added: release_1',$add);

        // Add duplicate (idempotent)
        [$addAgain,$addAgainCode] = $this->runInProcess('snapshot tag ' . $uid . ' add release_1');
        $this->assertSame(0,$addAgainCode, $addAgain);
        $this->assertStringContainsString('tag exists: release_1',$addAgain);

        // Show should list tag
        [$show,$showCode] = $this->runInProcess('snapshot show ' . $uid);
        $this->assertSame(0,$showCode, $show);
        $this->assertStringContainsString('TAGS:       release_1', $show);

        // Manifest file contains tags
        $manifestPath = $ctx->registry->local_base_path() . '/snaps/' . $uid . '/manifest-v2.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('tags',$manifest);
        $this->assertSame(['release_1'],$manifest['tags']);

        // Index reflects tag
        $index = $ctx->index->load();
        $this->assertIsArray($index);
        $entry = null; foreach ($index['snapshots'] as $row) { if (($row['uid']??'') === $uid) { $entry = $row; break; } }
        $this->assertNotNull($entry, 'index entry missing');
        $this->assertSame(['release_1'], $entry['tags']);

        // Remove tag
        [$remove,$removeCode] = $this->runInProcess('snapshot tag ' . $uid . ' remove release_1');
        $this->assertSame(0,$removeCode,$remove);
        $this->assertStringContainsString('tag removed: release_1',$remove);

        // Remove again (should be noop but success)
        [$remove2,$remove2Code] = $this->runInProcess('snapshot tag ' . $uid . ' remove release_1');
        $this->assertSame(0,$remove2Code,$remove2);
        $this->assertStringContainsString('tag not present: release_1',$remove2);

        // Show shows none
        [$show2,$show2Code] = $this->runInProcess('snapshot show ' . $uid);
        $this->assertSame(0,$show2Code,$show2);
        $this->assertStringContainsString('TAGS:       (none)', $show2);

        // Manifest updated (no tags or empty array)
        $manifest2 = json_decode((string)file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest2);
        $this->assertTrue(!isset($manifest2['tags']) || $manifest2['tags'] === []);

        // Index updated
        $index2 = $ctx->index->load();
        $entry2 = null; foreach ($index2['snapshots'] as $row) { if (($row['uid']??'') === $uid) { $entry2 = $row; break; } }
        $this->assertNotNull($entry2);
        $this->assertSame([], $entry2['tags']);
    }

    public function testInvalidTagFormat(): void {
        [$create,$code] = $this->runInProcess('snapshot create -m tagtest2');
        $this->assertSame(0,$code,$create);
        $uid = $this->extractUid($create);
        [$bad,$badCode] = $this->runInProcess('snapshot tag ' . $uid . ' add BAD');
        $this->assertSame(4,$badCode,$bad); // validation error
        $this->assertStringContainsString('invalid tag format', $bad);
    }
}

