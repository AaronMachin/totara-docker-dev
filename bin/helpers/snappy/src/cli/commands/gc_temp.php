<?php
/**
 * Snappy\Cli\Commands\gc_temp
 *
 * Clean temporary snapshot/import dirs and orphan export temp files
 *
 * Usage: tsnap gc temp [--dry-run] [--hours=N]
 * Removes: (a) tmp/<dir> older than N hours (default 24) and (b) *.tmp export artifacts older than 1h under snaps/.
 *
 * Examples:
 * - tsnap gc temp
 * - tsnap gc temp --dry-run
 * - tsnap gc temp --hours=12
 */

declare(strict_types=1);

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class gc_temp extends base_command {
    public function name(): string { return 'gc.temp'; }
    public function description(): string { return 'Clean temporary snapshot/import dirs and orphan export temp files'; }
    public function usage(): string { return "Usage: tsnap gc temp [--dry-run] [--hours=N]\nRemoves: (a) tmp/<dir> older than N hours (default 24) and (b) *.tmp export artifacts older than 1h under snaps/."; }
    public function examples(): array { return [ 'tsnap gc temp', 'tsnap gc temp --dry-run', 'tsnap gc temp --hours=12' ]; }
    public function metadata(): array { $m = parent::metadata(); $m['group']='Maintenance'; return $m; }

    public function run(array $args, context $ctx): int {
        $dry = false; $hours = 24; foreach($args as $a){ if($a==='--dry-run') $dry=true; elseif(preg_match('~^--hours=(\d+)~',$a,$m)) { $hours=(int)$m[1]; } }
        if($hours<1) { $hours=24; }
        $base = rtrim($ctx->registry->local_base_path(),'/');
        $tmpDir = $base.'/tmp'; $snapDir=$base.'/snaps';
        $now = time(); $limitSecs = $hours*3600; $removedDirs=[]; $removedFiles=[]; $candidateDirs=[]; $candidateFiles=[]; $skippedDirs=[];
        if(is_dir($tmpDir)) {
            $entries = @scandir($tmpDir) ?: [];
            foreach ($entries as $e) {
                if($e==='.'||$e==='..') continue;
                $full = $tmpDir.'/'.$e;
                if(!is_dir($full)) continue;
                $mt = @filemtime($full) ?: 0; $age = $now - $mt;
                if($age >= $limitSecs) {
                    $candidateDirs[] = $full;
                    if(!$dry) {
                        if($this->recursiveDelete($full)) { $removedDirs[] = $full; }
                        else { $skippedDirs[] = $full; }
                    }
                }
            }
        }
        // Orphan export temp files: *.tmp under each snapshot directory (depth 1) older than 1h
        $fileLimitSecs = 3600;
        if(is_dir($snapDir)) {
            $snaps = @scandir($snapDir) ?: [];
            foreach ($snaps as $s) {
                if($s==='.'||$s==='..' || $s==='index.json') continue;
                $sdir = $snapDir.'/'.$s; if(!is_dir($sdir)) continue;
                $files = @scandir($sdir) ?: [];
                foreach ($files as $f) {
                    if($f==='.'||$f==='..') continue;
                    if(!str_ends_with($f,'.tmp')) continue;
                    $full = $sdir.'/'.$f; if(!is_file($full)) continue;
                    $mt = @filemtime($full) ?: 0; $age = $now - $mt;
                    if($age >= $fileLimitSecs) {
                        $candidateFiles[] = $full;
                        if(!$dry) { if(@unlink($full)) { $removedFiles[] = $full; } }
                    }
                }
            }
        }
        $ctx->out->info(($dry?'[dry-run] ':'').'temp dirs eligible: '.count($candidateDirs));
        $ctx->out->info(($dry?'[dry-run] ':'').'orphan export temp files eligible: '.count($candidateFiles));
        if(!$dry){ $ctx->out->info('removed temp dirs: '.count($removedDirs)); $ctx->out->info('removed temp files: '.count($removedFiles)); }
        $ctx->out->json([
            'action'=>'gc.temp','dry_run'=>$dry,'hours'=>$hours,
            'eligible_temp_dirs'=>array_values($candidateDirs),
            'eligible_temp_files'=>array_values($candidateFiles),
            'removed_temp_dirs'=>$dry?[]:$removedDirs,
            'removed_temp_files'=>$dry?[]:$removedFiles,
        ]);
        return 0;
    }

    private function recursiveDelete(string $dir): bool { if(!is_dir($dir)) return false; $it=@scandir($dir); if(!$it) return @rmdir($dir); foreach($it as $e){ if($e==='.'||$e==='..') continue; $p=$dir.'/'.$e; if(is_dir($p)) { $this->recursiveDelete($p); } else { @unlink($p); } } return @rmdir($dir); }
}
