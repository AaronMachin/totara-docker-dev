<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class prune_run extends base_command {
    public function name(): string { return 'prune.run'; }
    public function description(): string { return 'Prune local snapshots by keeping the most recent N'; }
    public function usage(): string { return 'Usage: tsnap prune run [--keep-last=N|--keep=N] [--max-age=DURATION] [--apply]\nDry-run by default. Keeps newest N unprotected snapshots; also deletes unprotected older than max-age. Protected tags: baseline,protected.'; }
    public function examples(): array { return [
        'tsnap prune run --keep-last=50',
        'tsnap prune run --max-age=30d',
        'tsnap prune run --keep-last=10 --max-age=7d --apply',
    ]; }

    public function run(array $args, context $ctx): int {
        $keepLast = null; $apply = false; $aliasKeepUsed = false; $maxAgeSpec = null; $maxAgeSeconds = null;
        foreach ($args as $a) {
            if (preg_match('~^--keep-last=(\d+)~',$a,$m)) { $keepLast = (int)$m[1]; }
            elseif (preg_match('~^--keep=(\d+)~',$a,$m)) { $keepLast = (int)$m[1]; $aliasKeepUsed = true; }
            elseif ($a==='--apply') { $apply = true; }
            elseif (str_starts_with($a,'--max-age=')) { $maxAgeSpec = substr($a,10); }
        }
        if ($keepLast === null) { $keepLast = 20; }
        if ($keepLast < 0) { $keepLast = 0; }
        // Back-compat: --keep (alias) implies immediate apply unless --apply explicitly false (not supported) – no extra legacy branching
        if ($aliasKeepUsed && !$apply) { $apply = true; }
        if ($maxAgeSpec !== null && $maxAgeSpec !== '') {
            if (!preg_match('/^(\d+)([smhd]?)$/',$maxAgeSpec,$mm)) { $ctx->out->info('invalid max-age format'); $ctx->out->error('invalid max-age format',2); return 2; }
            $num = (int)$mm[1]; $unit=$mm[2]; $mult = match($unit){'m'=>60,'h'=>3600,'d'=>86400,default=>1}; $maxAgeSeconds = $num*$mult; }
        $index = $ctx->index->load(); if (!$index) { $ctx->index->rebuild(); $index = $ctx->index->load(); }
        if ($maxAgeSeconds !== null) { $ctx->index->rebuild(); $index = $ctx->index->load(); }
        $entries = $index['snapshots'] ?? [];
        usort($entries, fn($a,$b)=>strcmp(($b['created_utc']??''),($a['created_utc']??'')));
        $protectedTags=['baseline','protected']; $protected=[]; $unprotected=[];
        foreach ($entries as $e){
            $tags=$e['tags']??[]; $prot=false; foreach($tags as $t){ if(in_array($t,$protectedTags,true)){ $prot=true; break; } }
            if ($prot) { $protected[] = $e; } else { $unprotected[] = $e; }
        }
        // Determine age candidates (subset of unprotected)
        $ageCandidates=[]; $now=time(); if ($maxAgeSeconds!==null){ foreach($unprotected as $e){ $ts=strtotime($e['created_utc']??''); if($ts && $now-$ts > $maxAgeSeconds){ $ageCandidates[]=$e; } } }
        // keep-last candidates
        if ($keepLast===0) { $keepCandidates = $unprotected; }
        else { $keepCandidates = count($unprotected)>$keepLast ? array_slice($unprotected,$keepLast) : []; }
        // UNION of keep-last and age candidates (by uid)
        $map = [];
        foreach ([$keepCandidates,$ageCandidates] as $set) { foreach ($set as $row) { $map[$row['uid']] = $row; } }
        $candidates = array_values($map);
        if (!$candidates) {
            $keptCount = min($keepLast, count($unprotected));
            $ctx->out->info('Nothing to prune (have '.count($entries).', keep='.$keepLast.( $maxAgeSeconds!==null ? (' max-age='.$maxAgeSpec):'' ).')');
            $ctx->out->json([
                'action'=>'prune','dry_run'=>!$apply,'requested_keep_last'=>$keepLast,'max_age_spec'=>$maxAgeSpec,
                'total'=>count($entries),'protected'=>count($protected),'candidates'=>0,'deleted'=>0,'kept'=>$keptCount
            ]);
            return 0;
        }
        $candidateUids = array_map(fn($r)=>$r['uid'],$candidates);
        if (!$apply) {
            $keptCount = min($keepLast, count($unprotected));
            $ctx->out->info('Dry run: would delete '.count($candidateUids).' snapshot(s):'); foreach ($candidateUids as $u) { $ctx->out->info('  '.$u); }
            $ctx->out->json([
                'action'=>'prune','dry_run'=>true,'requested_keep_last'=>$keepLast,'max_age_spec'=>$maxAgeSpec,'max_age_seconds'=>$maxAgeSeconds,
                'total'=>count($entries),'protected'=>count($protected),
                'keep_last_candidate_count'=>count($keepCandidates),'age_candidate_count'=>count($ageCandidates),
                'candidates'=>count($candidateUids),'candidate_uids'=>$candidateUids,
                'kept'=>$keptCount,'deleted'=>0
            ]);
            return 0;
        }
        // Apply deletions
        $base = $ctx->registry->local_base_path(); $deleted=0;$errors=0;$error_uids=[];
        foreach ($candidateUids as $uid) {
            $dir=$base.'/snaps/'.$uid;
            if (!is_dir($dir)) { $deleted++; continue; }
            $ok = $this->recursiveDelete($dir);
            if (!$ok && !is_dir($dir)) { $ok = true; }
            if ($ok) { $deleted++; $ctx->index->remove($uid); }
            else { $errors++; $error_uids[]=$uid; }
        }
        $keptCount = ($keepLast===0) ? 0 : max(0, count($unprotected) - $deleted);
        $ctx->out->info('Deleted '.$deleted.' snapshot(s). Kept last '.$keepLast.( $maxAgeSeconds!==null?(' max-age='.$maxAgeSpec):'' ).'.'); if ($errors) { $ctx->out->info($errors.' deletion error(s).'); }
        $ctx->out->json([
            'action'=>'prune','dry_run'=>false,'requested_keep_last'=>$keepLast,'max_age_spec'=>$maxAgeSpec,'max_age_seconds'=>$maxAgeSeconds,
            'total_before'=>count($entries),'protected'=>count($protected),'deleted'=>$deleted,'errors'=>$errors,'error_uids'=>$error_uids,
            'keep_last_candidate_count'=>count($keepCandidates),'age_candidate_count'=>count($ageCandidates),
            'kept'=>$keptCount
        ]);
        return $errors ? 2 : 0;
    }

    private function recursiveDelete(string $dir): bool {
        $items = @scandir($dir); if(!$items) return false; foreach($items as $it){ if($it==='.'||$it==='..') continue; $p = $dir.'/'.$it; if(is_dir($p)) $this->recursiveDelete($p); else @unlink($p);} return @rmdir($dir);
    }
}
