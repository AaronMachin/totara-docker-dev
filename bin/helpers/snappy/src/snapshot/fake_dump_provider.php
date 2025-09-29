<?php
namespace Snappy\Snapshot;

use Snappy\Support\Exception\ProcessFailedException;

/**
 * Fake dump provider used in tests when SNAPPY_FAKE_DUMP env variable is set.
 * Produces a deterministic backup.sql without running external processes.
 */
class FakeDumpProvider implements DumpProviderInterface {
    public function supports(array $context): bool { return ($context['type'] ?? null) === 'sql'; }
    public function dump(string $uid, string $targetDir, array $options = []): DumpResult {
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $file = rtrim($targetDir,'/').'/backup.sql';
        $const = getenv('SNAPPY_FAKE_DUMP_CONST');
        if ($const !== false && $const !== '') {
            // constant content for dedupe tests
            $content = "-- fake deterministic dump\nSELECT 1;\n";
        } else {
            $content = "-- fake dump for $uid\nSELECT 1;\n";
        }
        file_put_contents($file, $content);
        return new DumpResult([[ 'name' => 'backup.sql', 'path' => $file ]], [ 'engine' => 'fake', 'version' => '1.0' ]);
    }

    public function apply(string $snapshotDir, array $options = []): DumpApplyResult {
        if (!is_dir($snapshotDir)) { throw new ProcessFailedException('snapshot directory missing'); }
        $fail = getenv('SNAPPY_FAKE_DUMP_APPLY_FAIL');
        if ($fail !== false && $fail !== '') { throw new ProcessFailedException('simulated apply failure'); }
        $fileGz = rtrim($snapshotDir,'/').'/backup.sql.gz';
        $file = rtrim($snapshotDir,'/').'/backup.sql';
        $path = null; $isGz=false;
        if (is_file($fileGz)) { $path = $fileGz; $isGz=true; }
        elseif (is_file($file)) { $path = $file; }
        if (!$path) { throw new ProcessFailedException('backup file missing'); }
        $bytes=0; if ($isGz) {
            if (function_exists('gzopen')) { $h=@gzopen($path,'rb'); if(!$h){ throw new ProcessFailedException('cannot open gzip'); } while(!gzeof($h)){ $c=@gzread($h,8192); if($c===false) break; $bytes+=strlen($c); } gzclose($h); }
            else { throw new ProcessFailedException('gzip not available for test'); }
        } else { $h=@fopen($path,'rb'); if(!$h){ throw new ProcessFailedException('cannot open dump'); } while(!feof($h)){ $c=@fread($h,8192); if($c===false) break; $bytes+=strlen($c);} fclose($h); }
        return new DumpApplyResult($bytes, [basename($path)], ['engine'=>'fake']);
    }
}
