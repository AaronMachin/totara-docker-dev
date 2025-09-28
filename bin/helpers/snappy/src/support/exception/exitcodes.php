<?php
namespace Snappy\Support\Exception;

use Throwable;

class ExitCodes {
    public const UNKNOWN = 99;

    private const MAP = [
        ValidationException::class => 2,
        SnapshotNotFoundException::class => 3,
        RemoteException::class => 4,
        ProcessFailedException::class => 5,
        ConfigException::class => 6,
    ];

    public static function codeFor(Throwable $e): int {
        foreach (self::MAP as $cls => $code) {
            if ($e instanceof $cls) { return $code; }
        }
        return self::UNKNOWN;
    }

    public static function all(): array { return self::MAP + ['<unknown>' => self::UNKNOWN]; }
}
