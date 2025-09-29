<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Remote\remote_lister;
use Snappy\Support\Exception\ValidationException;

class snapshot_list extends base_command {
    public function name(): string { return 'snapshot.list'; }
    public function description(): string { return 'List local snapshots or remote when --remote provided'; }
    public function usage(): string { return 'Usage: tsnap snapshot list [--full] [--limit=N] [--remote <name>]\nLists snapshots (most recent first).'; }
    public function examples(): array { return [ 'tsnap snapshot list --limit=20', 'tsnap snapshot list --full', 'tsnap snapshot list --remote prod --limit=10' ]; }

    public function run(array $args, context $ctx): int {
        $full=false; $limit=100; $remote=null; $parsed=[];
        for($i=0;$i<count($args);$i++) { $a=$args[$i]; if($a==='--full'){ $full=true; continue; } if(str_starts_with($a,'--limit=')){ $limit=max(1,(int)substr($a,8)); continue; } if($a==='--remote'){ $remote=$args[$i+1]??null; $i++; continue; } if(str_starts_with($a,'--remote=')){ $remote=substr($a,9); continue; } $parsed[]=$a; }
        if($remote!==null && $remote==='local'){ $remote='local'; }
        if($remote!==null && !$ctx->registry->has($remote)) { $ctx->out->error('unknown remote',64); return 64; }
        if($remote===null) {
            $rows = $ctx->manager->list('local', $full, $limit);
            if (!$rows) { $ctx->out->info('(none)'); $ctx->out->json(['snapshots'=>[],'full'=>$full,'limit'=>$limit]); return 0; }
            if (count($rows)>$limit) { $rows = array_slice($rows,0,$limit); }
            $headers=['UID','CREATED','TYPE','MESSAGE']; $table=[];
            foreach ($rows as $r) { $msg=$r['message']; if(!$full && strlen($msg)>120){ $msg=substr($msg,0,117).'...'; } $table[] = [$r['uid'],$r['created'],$r['type'],$msg]; }
            $ctx->out->table($headers,$table);
            $ctx->out->json(['snapshots'=>$rows,'full'=>$full,'limit'=>$limit]);
            return 0;
        }
        // Remote mode
        if($remote==='local'){ $ctx->out->error('use local listing without --remote',64); return 64; }
        $lister = new remote_lister();
        try { $res = $lister->enumerate($ctx->registry,$remote,$limit,$full); } catch(ValidationException $ve) { $ctx->out->error($ve->getMessage(),64); return 64; }
        $rows = $res['rows']; $errors=$res['errors']; $candidates=$res['candidates'];
        if(!$rows) { if($errors>0 && $candidates>0){ $ctx->out->error('no readable snapshots (all entries failed)',65); $ctx->out->json(['remote'=>$remote,'snapshots'=>[],'full'=>$full,'limit'=>$limit,'errors'=>$errors,'candidates'=>$candidates]); return 65; } $ctx->out->info('(none)'); $ctx->out->json(['remote'=>$remote,'snapshots'=>[],'full'=>$full,'limit'=>$limit,'errors'=>$errors,'candidates'=>$candidates]); return 0; }
        $hasSize=false; foreach($rows as $r){ if(isset($r['size_bytes'])){ $hasSize=true; break; } }
        $headers=['REMOTE','UID','CREATED','TYPE']; if($hasSize){ $headers[]='SIZE'; } $headers[]='MESSAGE'; $table=[];
        foreach($rows as $r){ $msg=$r['message']; if(!$full && strlen($msg)>120){ $msg=substr($msg,0,117).'...'; } $row=[$remote,$r['uid'],$r['created'],$r['type']]; if($hasSize){ $row[] = isset($r['size_bytes'])?(string)$r['size_bytes']:''; } $row[]=$msg; $table[]=$row; }
        $ctx->out->table($headers,$table);
        $ctx->out->json(['remote'=>$remote,'snapshots'=>$rows,'full'=>$full,'limit'=>$limit,'errors'=>$errors,'candidates'=>$candidates]);
        return 0;
    }
}
