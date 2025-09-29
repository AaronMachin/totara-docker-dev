<?php
namespace Snappy\Snapshot;

/** Value object representing result of applying a dump. */
class DumpApplyResult {
    private int $bytesApplied; /** @var string[] */ private array $files; /** @var array<string,mixed> */ private array $metadata;
    /** @param string[] $files @param array<string,mixed> $metadata */
    public function __construct(int $bytesApplied, array $files, array $metadata = []) { $this->bytesApplied=max(0,$bytesApplied); $norm=[]; foreach($files as $f){ if(is_string($f)&&$f!==''){ $norm[]=$f; } } $this->files=$norm; $this->metadata=$metadata; }
    public function bytes(): int { return $this->bytesApplied; }
    /** @return string[] */ public function files(): array { return $this->files; }
    /** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }
}

