<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for CLI integration tests providing isolated temp config & snapshot roots
 * and helpers to invoke tsnap CLI with mapped exception exit codes.
 */
abstract class AbstractCliTestCase extends TestCase
{
    protected string $phpBin;
    protected string $cliEntry;
    protected string $tmpRoot;
    protected string $envPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phpBin = escapeshellcmd((string) PHP_BINARY);
        $this->cliEntry = escapeshellarg(__DIR__ . '/../../tsnap_cli.php');
        $this->tmpRoot = sys_get_temp_dir() . '/snappy_cli_test_' . bin2hex(random_bytes(6));
        $cfgDir = $this->tmpRoot . '/cfg';
        $snapDir = $this->tmpRoot . '/snaps';
        $provDir = $this->tmpRoot . '/prov';
        @mkdir($cfgDir, 0777, true);
        @mkdir($snapDir, 0777, true);
        @mkdir($provDir, 0777, true);
        $configFile = $cfgDir . '/config.json';
        $this->envPrefix = 'SNAPPY_CONFIG_FILE=' . escapeshellarg($configFile)
            . ' SNAPPY_SNAPSHOT_BASE=' . escapeshellarg($snapDir)
            . ' SNAPPY_PROVISIONAL_BASE=' . escapeshellarg($provDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    /**
     * Run the CLI with the given argument string. Returns combined stdout+stderr.
     * Exit code is passed back by reference.
     */
    protected function runCli(string $args, ?int &$exitCode = null, string $extraEnv = ''): string
    {
        $prefix = $this->envPrefix . ($extraEnv !== '' ? ' ' . trim($extraEnv) : '');
        $cmd = $prefix . ' ' . $this->phpBin . ' ' . $this->cliEntry . ' ' . $args . ' 2>&1';
        $lines = [];
        exec($cmd, $lines, $exitCode);
        return implode("\n", $lines);
    }

    /** Recursively remove directory. */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

