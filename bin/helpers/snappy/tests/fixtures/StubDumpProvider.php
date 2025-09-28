<?php
// Test-only stub dump provider (not automatically wired) retained for future explicit injection if needed.
// Currently the integration flow relies on SNAPPY_FAKE_DUMP + FakeDumpProvider; this class exists per T10.2 step 1.
declare(strict_types=1);

namespace Snappy\Tests\Fixtures;

use Snappy\Snapshot\DumpProviderInterface;
use Snappy\Snapshot\DumpResult;

class StubDumpProvider implements DumpProviderInterface {
    public function supports(array $context): bool { return ($context['type'] ?? null) === 'sql'; }
    public function dump(string $uid, string $targetDir, array $options = []): DumpResult {
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $file = rtrim($targetDir,'/').'/stub_backup.sql';
        file_put_contents($file, "-- stub dump for $uid\nSELECT 42;\n");
        return new DumpResult([[ 'name' => 'stub_backup.sql', 'path' => $file ]], [ 'engine' => 'stub', 'version' => '1.0-test' ]);
    }
}

