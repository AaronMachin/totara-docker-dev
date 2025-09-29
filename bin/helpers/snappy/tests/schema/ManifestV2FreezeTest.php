<?php

declare(strict_types=1);

namespace Snappy\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Snappy\Util\canonical_json;

final class ManifestV2FreezeTest extends TestCase
{
    private const EXAMPLE_PATH = __DIR__ . '/../../schema/manifest_v2.example.json';
    private const GOLDEN_HASH = 'b4633d20c4dafd4f61bf16dab400923dfbb93554fe623e1a3379b0b7f51ad3c7';

    public function testManifestExampleGoldenHash(): void
    {
        $raw = file_get_contents(self::EXAMPLE_PATH);
        $this->assertNotFalse($raw, 'Example manifest must be readable.');
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $canon = canonical_json::encode($decoded);
        $hash = hash('sha256', $canon);
        $this->assertSame(
            self::GOLDEN_HASH,
            $hash,
            "Manifest v2 example canonical SHA-256 hash changed. If this schema change is intentional: 1) Update docs/schema_change.md with rationale & new fields. 2) Recompute hash via compute_manifest_hash.php (or local script) and update GOLDEN_HASH in this test. 3) Commit with ticket referencing schema change."
        );
    }
}
