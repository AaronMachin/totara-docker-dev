<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AbstractCliTestCase.php'; // ensure base class loaded before use

// Extend new base for CLI tests
final class DispatcherExitCodesTest extends AbstractCliTestCase
{
    public function testValidationExceptionMapsToExitCode2(): void
    {
        $out = $this->runCli('snapshot create --type=unknown -m test', $code);
        $this->assertSame(2, $code, 'Expected exit code 2 for ValidationException (unknown snapshot type)');
        $this->assertStringContainsString('ERROR(2):', $out);
        $this->assertStringContainsString('Unknown snapshot type', $out);
    }
}
