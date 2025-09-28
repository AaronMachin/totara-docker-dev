<?php

namespace Snappy\Snapshot;

use Snappy\Support\Process\process_runner;
use Snappy\Util\env;
use Snappy\Support\Exception\ProcessFailedException;
use Snappy\Support\Exception\ValidationException;

/**
 * Default dump provider using the local `tdb` helper binary (Totara dev DB tool).
 */
class TdbDumpProvider implements DumpProviderInterface {
    public function supports(array $context): bool {
        return ($context['type'] ?? null) === 'sql';
    }

    public function dump(string $uid, string $targetDir, array $options = []): DumpResult {
        // Registry (for config) optionally passed in context options['registry'].
        $registry = $options['registry'] ?? null;
        $cfg = $registry ? $registry->config_manager() : null;
        $backupPath = '';
        if ($cfg) { $backupPath = $cfg->get('options.backup_path', '') ?: ''; }
        $tdb = trim(env::get('SNAPPY_TDB_BIN', 'tdb')) ?: 'tdb';
        $runner = new process_runner();
        $command = [$tdb, 'backup', '--alias', $uid];
        $result = $runner->run($command);
        if ($result->exitCode !== 0) {
            $lines = preg_split('/\r?\n/', $result->stderr) ?: [];
            $first = array_slice($lines, 0, 10);
            $truncated = implode("\n", $first);
            if (count($lines) > 10) { $truncated .= "\n... (stderr truncated)"; }
            $msg = 'Database backup process failed (exit code ' . $result->exitCode . ") for alias $uid";
            if ($truncated !== '') { $msg .= ":\n" . $truncated; }
            throw new ProcessFailedException($msg, $result, $command);
        }
        // Locate dump artifact produced by tdb in configured backup path.
        $candidate = '';
        if ($backupPath && is_dir($backupPath)) {
            $matches = glob(rtrim($backupPath, '/') . '/' . $uid . '.*');
            if ($matches) { $candidate = $matches[0]; }
        }
        if (!$candidate || !is_file($candidate)) {
            throw new ProcessFailedException('Could not locate database backup for uid ' . $uid . ' in ' . $backupPath . ' (backup may have failed)', $result);
        }
        // Prepare metadata (engine + version best-effort).
        $version = '';
        $verResult = $runner->run([$tdb, '--version']);
        if ($verResult->exitCode === 0) {
            $line = trim(explode("\n", $verResult->stdout)[0] ?? '');
            if ($line !== '') { $version = $line; }
        }
        $metadata = [ 'engine' => 'tdb', 'version' => $version ];
        // Expose file as backup.sql inside snapshot (normalised naming).
        return new DumpResult([
            ['name' => 'backup.sql', 'path' => $candidate],
        ], $metadata);
    }
}
