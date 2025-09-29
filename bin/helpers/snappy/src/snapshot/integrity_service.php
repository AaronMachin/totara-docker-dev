<?php

namespace Snappy\Snapshot;

use Snappy\Util\canonical_json;

class VerificationResult {
    public bool $ok; /** @var array<string,array{expected:string,actual:string}> */ public array $failures;
    public function __construct(bool $ok, array $failures) { $this->ok = $ok; $this->failures = $failures; }
}

class integrity_service {
    private int $chunkSize = 8192;

    public function hashFile(string $path): string {
        if (!is_file($path)) { return ''; }
        $fh = @fopen($path, 'rb');
        if (!$fh) { return ''; }
        try { return $this->hashStream($fh); } finally { fclose($fh); }
    }

    /** Hash an already-open readable stream resource. Does not rewind. */
    public function hashStream($stream): string {
        $ctx = hash_init('sha256');
        while (!feof($stream)) {
            $buf = fread($stream, $this->chunkSize);
            if ($buf === '' || $buf === false) { continue; }
            hash_update($ctx, $buf);
        }
        return hash_final($ctx);
    }

    /** Canonical manifest hash using canonical_json utility */
    public function hashManifest(array $manifest): string {
        $json = canonical_json::encode($manifest);
        return hash('sha256', $json);
    }

    /** Hash set of artifact lines (ordered) joined by \n (no trailing newline). */
    public function artifactLinesHash(array $lines): string {
        $payload = implode("\n", $lines);
        return hash('sha256', $payload);
    }

    /** @param array<string,string> $expected map of filename=>expected sha256 */
    public function verifyFiles(array $expected, string $baseDir): VerificationResult {
        $failures = [];
        foreach ($expected as $file => $hash) {
            $path = rtrim($baseDir, '/').'/'.$file;
            $actual = $this->hashFile($path);
            if ($actual === '' || strtolower($actual) !== strtolower($hash)) {
                $failures[$file] = ['expected'=>$hash,'actual'=>$actual];
            }
        }
        return new VerificationResult(count($failures) === 0, $failures);
    }

    /** Return short verification code from full sha256 hex (grouped base32, 4-4-4-4-4). */
    public function shortVerificationCode(string $sha256Hex): string {
        $bin = @hex2bin($sha256Hex);
        if ($bin === false) { $bin = ''; }
        $b32 = $this->base32Encode($bin); // full base32
        $short = substr($b32, 0, 20); // first 20 chars
        return implode('-', str_split($short, 4));
    }

    private function base32Encode(string $data): string {
        if ($data === '') return '';
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567'; // lowercase RFC4648
        $out = '';
        $buffer = 0; $bitsLeft = 0;
        $len = strlen($data);
        for ($i=0;$i<$len;$i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $index = ($buffer >> $bitsLeft) & 0x1F;
                $out .= $alphabet[$index];
            }
        }
        if ($bitsLeft > 0) {
            $index = ($buffer << (5 - $bitsLeft)) & 0x1F;
            $out .= $alphabet[$index];
        }
        return $out; // no padding
    }
}

