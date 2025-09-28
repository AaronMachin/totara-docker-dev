<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Extend new base for CLI tests
final class DispatcherExitCodesTest extends AbstractCliTestCase
{
    public function testValidationExceptionMapsToExitCode2(): void
    {
        $out = $this->runCli('remote add local s3 --endpoint=http://x --bucket=b --region=us-east-1 --key=k --secret=s', $code);
        $this->assertSame(2, $code, 'Expected exit code 2 for ValidationException');
        $this->assertStringContainsString('ERROR(2):', $out);
        $this->assertStringContainsString('Cannot redefine reserved remote', $out);
    }

    public function testRemoteCodecDecodeFailure(): void
    {
        $out = $this->runCli('pull --share=@@@INVALID@@@', $code); // invalid base64
        $this->assertSame(4, $code, 'Expected exit code 4 for RemoteException decode failure');
        $this->assertStringContainsString('ERROR(4):', $out);
    }
}
