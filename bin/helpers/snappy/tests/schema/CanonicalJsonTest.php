<?php

declare(strict_types=1);

namespace Snappy\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Snappy\Util\canonical_json;

final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeysAreSortedRecursively(): void
    {
        $input = [
            'z' => 1,
            'a' => [ 'b' => 2, 'a' => 1 ],
            'm' => [ 'k' => ['y' => 2, 'x' => 1], 'a' => 5 ],
        ];
        $encoded = canonical_json::encode($input);
        $this->assertSame(
            '{"a":{"a":1,"b":2},"m":{"a":5,"k":{"x":1,"y":2}},"z":1}',
            $encoded,
            'Keys should be sorted lexicographically at every object level.'
        );
    }

    public function testArrayOrderPreserved(): void
    {
        $input = [
            [ 'b' => 2, 'a' => 1 ],
            [ 'c' => 3, 'b' => 2 ],
        ];
        $encoded = canonical_json::encode($input);
        $this->assertSame('[{"a":1,"b":2},{"b":2,"c":3}]', $encoded);
    }

    public function testNumericLikeStringKeysSortedLexicographically(): void
    {
        $input = [ '10' => 'ten', '2' => 'two', '1' => 'one' ];
        $encoded = canonical_json::encode($input);
        // Lexicographic order: '1', '10', '2'
        $this->assertSame('{"1":"one","10":"ten","2":"two"}', $encoded);
    }

    public function testWhitespaceAndKeyOrderDifferencesNormalizeSame(): void
    {
        $base = '{"b":1, "a": {"y":2, "x":1}}';
        $variant = "{\n  \"a\": {\n    \"x\": 1,\n    \"y\": 2\n  },\n  \"b\": 1\n}"; // different key order & whitespace
        $canon1 = canonical_json::encode(json_decode($base, true));
        $canon2 = canonical_json::encode(json_decode($variant, true));
        $this->assertSame($canon1, $canon2, 'Canonicalization should eliminate key order & whitespace differences.');
    }
}

