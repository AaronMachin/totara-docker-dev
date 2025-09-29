<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_list extends base_command {
    public function name(): string { return 'snapshot.list'; }
    public function description(): string { return 'List local snapshots'; }
    public function usage(): string { return 'Usage: tsnap snapshot list [--full] [--limit=N]\nLists local snapshots (most recent first).'; }
    public function examples(): array { return [ 'tsnap snapshot list --limit=20', 'tsnap snapshot list --full' ]; }

    public function run(array $args, context $ctx): int {
        $full=false; $limit=100;
        foreach ($args as $a) {
            if ($a==='--full') { $full=true; }
            elseif (str_starts_with($a,'--limit=')) { $limit = max(1,(int)substr($a,8)); }
        }
        $rows = $ctx->manager->list('local', $full, $limit);
        if (!$rows) { $ctx->out->info('(none)'); $ctx->out->json(['snapshots'=>[],'full'=>$full,'limit'=>$limit]); return 0; }
        if (count($rows)>$limit) { $rows = array_slice($rows,0,$limit); }
        $headers=['UID','CREATED','TYPE','MESSAGE']; $table=[];
        foreach ($rows as $r) { $msg=$r['message']; if(!$full && strlen($msg)>120){ $msg=substr($msg,0,117).'...'; } $table[] = [$r['uid'],$r['created'],$r['type'],$msg]; }
        $ctx->out->table($headers,$table);
        $ctx->out->json(['snapshots'=>$rows,'full'=>$full,'limit'=>$limit]);
        return 0;
    }
}
