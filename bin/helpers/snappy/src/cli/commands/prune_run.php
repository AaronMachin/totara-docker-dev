<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class prune_run extends base_command {
    public function name(): string { return 'prune.run'; }
    public function description(): string { return 'Prune local snapshots by keeping the most recent N'; }
    public function usage(): string { return 'Usage: tsnap prune run [--keep-last=N|--keep=N] [--apply]\nDry-run by default. Keeps newest N unprotected snapshots. Protected tags: baseline,protected.'; }
    public function examples(): array { return [
        'tsnap prune run --keep-last=50',
        'tsnap prune run --keep=10 --apply',
    ]; }

    public function run(array $args, context $ctx): int {
        $keepLast = null; $apply = false; $legacyKeep = null;
        foreach ($args as $a) {
            if (preg_match('~^--keep-last=(\d+)~',$a,$m)) { $keepLast = (int)$m[1]; }
            elseif (preg_match('~^--keep=(\d+)~',$a,$m)) { $legacyKeep = (int)$m[1]; }
            elseif ($a==='--apply') { $apply = true; }
        }
        if ($keepLast === null) { $keepLast = $legacyKeep !== null ? $legacyKeep : 20; }
        if ($keepLast < 0) { $keepLast = 0; }
        // Load index for tag + created info
        $index = $ctx->index->load();
        if (!$index) { $ctx->index->rebuild(); $index = $ctx->index->load(); }
        $entries = $index['snapshots'] ?? [];
        // Normalize rows: uid, created_utc, tags
        usort($entries, fn($a,$b)=>strcmp(($b['created_utc']??''), ($a['created_utc']??'')));
        $protectedTags = ['baseline','protected'];
        $protected = [];
        $unprotected = [];
        foreach ($entries as $e) {
            $tags = $e['tags'] ?? [];
            $isProt = false; foreach ($tags as $t) { if (in_array($t,$protectedTags,true)) { $isProt = true; break; } }
            if ($isProt) { $protected[] = $e; } else { $unprotected[] = $e; }
        }
        // Determine deletion candidates among unprotected beyond keepLast
        $candidates = [];
        if (count($unprotected) > $keepLast) { $candidates = array_slice($unprotected, $keepLast); }
        if (!$candidates) {
            $ctx->out->info('Nothing to prune (have '.count($entries).', keep='.$keepLast.')');
            $ctx->out->json(['action'=>'prune','dry_run'=>!$apply,'requested_keep_last'=>$keepLast,'total'=>count($entries),'protected'=>count($protected),'candidates'=>0,'deleted'=>0]);
            return 0;
        }
        $candidateUids = array_map(fn($r)=>$r['uid'],$candidates);
        if (!$apply) {
            $ctx->out->info('Dry run: would delete '.count($candidateUids).' snapshot(s):');
            foreach ($candidateUids as $u) { $ctx->out->info('  '.$u); }
            $ctx->out->json(['action'=>'prune','dry_run'=>true,'requested_keep_last'=>$keepLast,'total'=>count($entries),'protected'=>count($protected),'candidates'=>count($candidateUids),'candidate_uids'=>$candidateUids]);
            return 0;
        }
        // Apply deletions
        $base = $ctx->registry->local_base_path();
        $deleted = 0; $errors = 0; $error_uids = [];
        foreach ($candidateUids as $uid) {
            $dir = $base . '/snaps/' . $uid;
            if (is_dir($dir)) {
                if ($this->recursiveDelete($dir)) { $deleted++; $ctx->index->remove($uid); }
                else { $errors++; $error_uids[] = $uid; }
            }
        }
        $ctx->out->info('Deleted '.$deleted.' snapshot(s). Kept last '.$keepLast.'.');
        if ($errors) { $ctx->out->info($errors.' deletion error(s).'); }
        $ctx->out->json(['action'=>'prune','dry_run'=>false,'requested_keep_last'=>$keepLast,'total_before'=>count($entries),'protected'=>count($protected),'deleted'=>$deleted,'errors'=>$errors,'error_uids'=>$error_uids]);
        return $errors ? 2 : 0;
    }

    private function recursiveDelete(string $dir): bool {
        $items = @scandir($dir); if(!$items) return false; foreach($items as $it){ if($it==='.'||$it==='..') continue; $p = $dir.'/'.$it; if(is_dir($p)) $this->recursiveDelete($p); else @unlink($p);} return @rmdir($dir);
    }
}
