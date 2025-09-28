<?php
namespace Snappy\Snapshot;

/**
 * Fake dump provider used in tests when SNAPPY_FAKE_DUMP env variable is set.
 * Produces a deterministic backup.sql without running external processes.
 */
class FakeDumpProvider implements DumpProviderInterface {
    public function supports(array $context): bool { return ($context['type'] ?? null) === 'sql'; }
    public function dump(string $uid, string $targetDir, array $options = []): DumpResult {
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $file = rtrim($targetDir,'/').'/backup.sql';
        file_put_contents($file, "-- fake dump for $uid\nSELECT 1;\n");
        return new DumpResult([[ 'name' => 'backup.sql', 'path' => $file ]], [ 'engine' => 'fake', 'version' => '1.0' ]);
    }
}

