<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Util\color;
use Snappy\Snapshot\remote_snapshot_cache;

class listing extends base_command {
    public function name(): string { return 'list'; }
    public function description(): string { return 'List snapshots'; }
    public function usage(): string { return 'Usage: tsnap list [--full|--full-message] [--limit=N] [--remote=name1,name2] [--since=EXPR] [--before=EXPR] [--no-parallel] [--live] [--no-index]
List snapshots across selected remotes. --since/--before accept strtotime expressions or relative (10m,2h,3d). Use --live to bypass cache. Use --no-index to force manifest scan for local.'; }
    public function examples(): array { return ['tsnap list','tsnap list --full --limit=20','tsnap list --remote=origin,backup --since=2d','tsnap list --live --remote=origin']; }

    public function run(array $args, context $ctx): int {
        [$opts, $errors] = $this->parseListArgs($args);
        if ($errors) { foreach ($errors as $e) { fwrite(STDERR, $e."\n"); } return 1; }
        $full = $opts['full'];
        $limit = $opts['limit'];
        $remoteCsv = $opts['remote'];
        $sinceExpr = $opts['since'];
        $beforeExpr = $opts['before'];
        $live = $opts['live'];
        $noIndex = $opts['no_index'];
        $sinceTs = $sinceExpr ? $this->parse_time($sinceExpr) : null;
        $beforeTs = $beforeExpr ? $this->parse_time($beforeExpr) : null;
        $selected = $this->selectRemotes($remoteCsv, $ctx);
        if ($selected === null) { return 2; }
        [$rows, $cacheInfo, $staleRemotes] = $this->collectRows($selected, $full, $limit, $live, $ctx, $noIndex);
        $rows = $this->applyTimeFilters($rows, $sinceTs, $beforeTs);
        $this->render($rows, $selected, $cacheInfo, $staleRemotes, $full, $limit, $live, $ctx);
        return 0;
    }

    private function parseListArgs(array $argv): array {
        $def = [
            'full' => ['flags'=>['--full','--full-message'],'type'=>'bool','default'=>false],
            'limit'=> ['prefix'=>'--limit=','type'=>'int','default'=>100],
            'remote'=>['prefix'=>'--remote=','type'=>'string','default'=>null],
            'since' =>['prefix'=>'--since=','type'=>'string','default'=>null],
            'before'=>['prefix'=>'--before=','type'=>'string','default'=>null],
            'live'  =>['flags'=>['--live'],'type'=>'bool','default'=>false],
            'no_index'=>['flags'=>['--no-index'],'type'=>'bool','default'=>false],
        ];
        $parsed = $this->parseArgs($argv, $def);
        return [$parsed['options'], $parsed['errors']];
    }

    private function selectRemotes(?string $remoteCsv, context $ctx): ?array {
        $all = $ctx->registry->names();
        if ($remoteCsv === null) { return $all; }
        if ($remoteCsv === '') { return $all; }
        $parts = array_filter(array_map('trim', explode(',', $remoteCsv)), 'strlen');
        $out = [];
        foreach ($parts as $p) {
            if (!$ctx->registry->has($p)) { fwrite(STDERR, "unknown remote/store: $p\n"); return null; }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function collectRows(array $selected, bool $full, int $limit, bool $live, context $ctx, bool $noIndex = false): array {
        usort($selected, function($a,$b){ if($a==='local'&&$b!=='local') return -1; if($b==='local'&&$a!=='local') return 1; return strcmp($a,$b); });
        $cacheInfo=[]; $staleRemotes=[]; $rows=[];
        foreach ($selected as $remote) {
            if ($remote==='local') {
                $localRows = $ctx->manager->list('local', $full, $limit, $noIndex); // pass noIndex as bypass flag
                foreach ($localRows as $rrow) { $rows[$rrow['uid']] = $rows[$rrow['uid']] ?? $rrow; $rows[$rrow['uid']]['locations'] = isset($rows[$rrow['uid']]['locations']) ? $rows[$rrow['uid']]['locations'].', local' : 'local'; }
                continue;
            }
            if ($live) {
                $liveRows = $ctx->manager->list($remote, $full, $limit, true);
                foreach ($liveRows as $rrow) { $uid=$rrow['uid']; $rows[$uid] = $rows[$uid] ?? $rrow; $rows[$uid]['locations'] = isset($rows[$uid]['locations']) ? $rows[$uid]['locations'].', '.$remote : $remote; }
                $cacheInfo[$remote]='live';
                continue;
            }
            $c = $ctx->cache->load($remote);
            if ($c) {
                $ageIso = $c['fetched_at'];
                $ageHuman = remote_snapshot_cache::human_age($ageIso);
                $isStale = remote_snapshot_cache::is_stale($ageIso,$ctx->registry);
                if ($isStale) { $staleRemotes[] = $remote; if (\Snappy\Util\color::enabled()) { $ageHuman="\033[1;31m".$ageHuman."\033[0m"; } }
                $cacheInfo[$remote] = $ageHuman;
                $count=0;
                foreach ($c['snapshots'] as $uid=>$row) {
                    if ($count >= $limit) { break; }
                    $msg = (string)($row['message'] ?? '');
                    if (!$full) { $msg = preg_split('/\r?\n/',$msg,2)[0] ?? ''; } else { $msg = preg_replace('/\r?\n+/',' | ',$msg); }
                    if (!isset($rows[$uid])) { $rows[$uid] = ['uid'=>$uid,'created'=>$row['created']??'','type'=>$row['type']??'','message'=>$msg,'locations'=>$remote]; }
                    else { $locs = array_map('trim', explode(',',$rows[$uid]['locations'])); if(!in_array($remote,$locs,true)) { $rows[$uid]['locations'] .= ', '.$remote; } }
                    $count++;
                }
            } else { $cacheInfo[$remote]='no-cache'; }
        }
        return [array_values($rows), $cacheInfo, $staleRemotes];
    }

    private function applyTimeFilters(array $rows, ?int $since, ?int $before): array {
        if (!$since && !$before) { return $rows; }
        $rows = array_filter($rows, function($r) use($since,$before){
            $created = strtotime($r['created'] ?? '') ?: 0;
            if ($since && $created < $since) { return false; }
            if ($before && $created > $before) { return false; }
            return true;
        });
        return array_values($rows);
    }

    private function render(array $rows, array $selected, array $cacheInfo, array $staleRemotes, bool $full, int $limit, bool $live, context $ctx): void {
        usort($rows, fn($a,$b)=>strcmp($b['created'],$a['created']));
        if (count($rows) > $limit) { $rows = array_slice($rows,0,$limit); }
        $sourceLine = ($live? 'Snapshots (live mode; bypassing cache)' : 'Snapshots (cached remotes: '.implode(', ', array_map(function($r) use($cacheInfo){ return $r.'['.($cacheInfo[$r]??'n/a').']'; }, array_filter($selected,fn($r)=>$r!=='local'))).')');
        echo $sourceLine."\n";
        if (!$live && $staleRemotes) { echo 'Stale caches: '.implode(', ',$staleRemotes).'. Refresh with: tsnap fetch --stale-only --remote='.implode(',',$staleRemotes)."\n"; }
        if ($live) { echo "(Use without --live to use cached metadata; adjust max age via config option cache_max_age_seconds)\n"; }
        if (!$rows) { echo "(none)\n"; return; }
        $w_uid=3;$w_created=7;$w_type=4;$w_locations=9; foreach($rows as $r){$w_uid=max($w_uid,strlen($r['uid']));$w_created=max($w_created,strlen($r['created']));$w_type=max($w_type,strlen($r['type']));$w_locations=max($w_locations,strlen($r['locations']));}
        printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %-{$w_locations}s  %s\n",'UID','CREATED','TYPE','LOCATIONS','MESSAGE');
        foreach($rows as $r){ $m=$r['message']; if(!$full && strlen($m)>120){$m=substr($m,0,117).'...';} $coloredLoc=$this->color_and_pad_locations($r['locations'],$w_locations); printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s  %s\n",$r['uid'],$r['created'],$r['type'],$coloredLoc,$m); }
    }

    private function color_and_pad_locations(string $raw,int $width): string {
        $parts = array_map('trim', explode(',',$raw)); $coloredParts = array_map(fn($n)=>color::remote($n),$parts); $colored = implode(', ',$coloredParts); $printableLen = strlen(implode(', ',$parts)); if($printableLen<$width){$colored.=str_repeat(' ',$width-$printableLen);} return $colored;
    }
    private function parse_time(string $expr): ?int {
        $expr = trim($expr); if ($expr==='') return null;
        if (preg_match('/^(\d+)([smhd])$/i',$expr,$m)) { $n=(int)$m[1]; $u=strtolower($m[2]); $sec=0; switch($u){case 's':$sec=$n;break;case 'm':$sec=$n*60;break;case 'h':$sec=$n*3600;break;case 'd':$sec=$n*86400;break;default:return null;} return time()-$sec; }
        $ts = strtotime($expr); return $ts?:null;
    }
}
