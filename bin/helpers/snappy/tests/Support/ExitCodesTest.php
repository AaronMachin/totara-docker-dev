<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Support\Exception\ExitCodes;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnapshotNotFoundException;
use Snappy\Support\Exception\RemoteException;
use Snappy\Support\Exception\ProcessFailedException;
use Snappy\Support\Exception\ConfigException;
use Snappy\Support\Exception\SnappyException;

final class ExitCodesTest extends TestCase
{
    public function testMapping(): void
    {
        self::assertSame(2, ExitCodes::codeFor(new ValidationException('v')));
        self::assertSame(3, ExitCodes::codeFor(new SnapshotNotFoundException('n')));
        self::assertSame(4, ExitCodes::codeFor(new RemoteException('r')));
        self::assertSame(5, ExitCodes::codeFor(new ProcessFailedException('p')));
        self::assertSame(6, ExitCodes::codeFor(new ConfigException('c')));
    }

    public function testUnknownSubclassFallsBack(): void
    {
        // Anonymous subclass not registered
        $anon = new class('x') extends SnappyException {};
        self::assertSame(ExitCodes::UNKNOWN, ExitCodes::codeFor($anon));
    }
}

