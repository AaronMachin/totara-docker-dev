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
    public function supports(array $context): bool { return ($context['type'] ?? null) === 'sql'; }

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
        $ext = pathinfo($candidate, PATHINFO_EXTENSION) ?: '';
        $metadata = [ 'engine' => 'tdb', 'version' => $version ];
        if ($ext !== '') { $metadata['db_type'] = $ext; }
        // Expose file as backup.sql inside snapshot (normalised naming).
        return new DumpResult([
            ['name' => 'backup.sql', 'path' => $candidate],
        ], $metadata);
    }

    public function apply(string $snapshotDir, array $options = []): DumpApplyResult {
        if (!is_dir($snapshotDir)) { throw new ProcessFailedException('snapshot directory missing'); }
        $registry = $options['registry'] ?? null; $cfg = $registry? $registry->config_manager(): null;
        $backupPath = ''; if ($cfg) { $backupPath = $cfg->get('options.backup_path', '') ?: ''; }
        if ($backupPath === '') { // fallback to env like dump()
            $envPath = env::get('SNAPPY_TDB_BACKUP_PATH',''); if ($envPath) { $backupPath = $envPath; }
        }
        if ($backupPath === '') { throw new ValidationException('backup_path not configured for apply'); }
        if (!is_dir($backupPath)) { @mkdir($backupPath,0777,true); }
        if (!is_dir($backupPath)) { throw new ValidationException('cannot create backup_path'); }
        // Read manifest/meta for db_type
        $dbType = ''; $manifestFile = rtrim($snapshotDir,'/').'/manifest-v2.json'; $metaFile = rtrim($snapshotDir,'/').'/meta.json'; $raw = null;
        if (is_file($manifestFile)) { $raw = @json_decode(@file_get_contents($manifestFile),true); if (!is_array($raw) && is_file($metaFile)) { $raw = @json_decode(@file_get_contents($metaFile),true); } }
        elseif (is_file($metaFile)) { $raw = @json_decode(@file_get_contents($metaFile),true); }
        if (is_array($raw)) { $dumpMeta = $raw['dump_metadata'] ?? []; if (is_array($dumpMeta)) { $dbType = (string)($dumpMeta['db_type'] ?? ''); } }
        if ($dbType === '') { $dbType = 'sql'; }
        $srcGz = rtrim($snapshotDir,'/').'/backup.sql.gz'; $src = rtrim($snapshotDir,'/').'/backup.sql';
        $chosen = null; $compressed = false; if (is_file($srcGz)) { $chosen = $srcGz; $compressed = true; } elseif (is_file($src)) { $chosen = $src; }
        if (!$chosen) { throw new ProcessFailedException('backup file missing (expected backup.sql or backup.sql.gz)'); }
        $alias = 'snappyrestore_'.bin2hex(random_bytes(4)); $target = $backupPath.'/'.$alias.'.'.$dbType; $temp = $target.'.tmp'; $bytes = 0;
        if ($compressed) {
            $success = false; if (function_exists('gzopen')) { $in = @gzopen($chosen,'rb'); $out = @fopen($temp,'wb'); if ($in && $out) { while (!gzeof($in)) { $c = @gzread($in,8192); if ($c === false) break; $l = strlen($c); if ($l > 0) { $bytes += $l; fwrite($out,$c); } } gzclose($in); fclose($out); $success = is_file($temp); } }
            if (!$success) { $gunzip = trim((string)@shell_exec('command -v gunzip 2>/dev/null')) ?: 'gunzip'; $runner = new process_runner(); $res = $runner->run([$gunzip,'-c',$chosen]); if ($res->exitCode === 0) { file_put_contents($temp,$res->stdout); $bytes = strlen($res->stdout); $success = true; } }
            if (!$success) { throw new ProcessFailedException('decompression failed for apply'); }
        } else {
            $in = @fopen($chosen,'rb'); $out = @fopen($temp,'wb'); if (!$in || !$out) { throw new ProcessFailedException('unable to open dump for apply'); } while (!feof($in)) { $c = @fread($in,8192); if ($c === false) break; $l = strlen($c); if ($l > 0) { $bytes += $l; fwrite($out,$c); } } fclose($in); fclose($out);
        }
        if (!@rename($temp,$target)) { @unlink($temp); throw new ProcessFailedException('failed to stage restore file'); }
        $tdb = trim(env::get('SNAPPY_TDB_BIN', 'tdb')) ?: 'tdb';
        $runner = new process_runner();
        $cmd = [$tdb,'restore','--alias',$alias];
        $result = $runner->run($cmd, ['TDB_BACKUP_PATH'=>$backupPath]);
        if ($result->exitCode !== 0) { throw new ProcessFailedException('restore failed (exit '.$result->exitCode.')', $result, $cmd); }
        return new DumpApplyResult($bytes,[basename($target)],['engine'=>'tdb','alias'=>$alias,'db_type'=>$dbType]);
    }
}
