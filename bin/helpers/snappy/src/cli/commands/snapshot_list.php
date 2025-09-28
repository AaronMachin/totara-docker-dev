<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_list extends base_command {
    public function name(): string { return 'snapshot.list'; }
    public function description(): string { return 'List snapshots (local or remote)'; }
    public function usage(): string { return 'Usage: tsnap snapshot list [--remote=NAME] [--full] [--limit=N] [--live] [--no-index]\nLists snapshots; defaults to local.'; }

    public function run(array $args, context $ctx): int {
        $opts = $this->parseArgsLocal($args);
        $remote = $opts['remote'] ?? 'local';
        if (!$ctx->registry->has($remote)) { fwrite(STDERR, "unknown remote: $remote\n"); return 2; }
        $rows = $ctx->manager->list($remote, $opts['full'], $opts['limit'], $opts['live'] || ($remote==='local' && $opts['no_index']));
        if (!$rows) { echo "(none)\n"; return 0; }
        $full = $opts['full'];
        $w_uid=3;$w_created=7;$w_type=4; foreach($rows as $r){$w_uid=max($w_uid,strlen($r['uid']));$w_created=max($w_created,strlen($r['created']));$w_type=max($w_type,strlen($r['type']));}
        printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n",'UID','CREATED','TYPE','MESSAGE');
        foreach ($rows as $r) { $m=$r['message']; if(!$full && strlen($m)>120){$m=substr($m,0,117).'...';} printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n",$r['uid'],$r['created'],$r['type'],$m); }
        return 0;
    }

    private function parseArgsLocal(array $argv): array {
        $out = ['remote'=>null,'full'=>false,'limit'=>100,'live'=>false,'no_index'=>false];
        foreach ($argv as $a) {
            if ($a==='--full') { $out['full']=true; }
            elseif ($a==='--live') { $out['live']=true; }
            elseif ($a==='--no-index') { $out['no_index']=true; }
            elseif (str_starts_with($a,'--remote=')) { $out['remote']=substr($a,9); }
            elseif (str_starts_with($a,'--limit=')) { $out['limit']=max(1,(int)substr($a,8)); }
        }
        if ($out['remote']===null) { $out['remote']='local'; }
        return $out;
    }
}

