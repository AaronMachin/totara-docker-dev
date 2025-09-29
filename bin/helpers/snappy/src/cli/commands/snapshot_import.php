<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Snapshot\import_service;
use Snappy\Support\Exception\ValidationException;
use Throwable;

class snapshot_import extends base_command {
    public function name(): string { return 'snapshot.import'; }
    public function description(): string { return 'Import a snapshot artifact (.tar.gz or .tar) into the local snapshot store'; }
    public function usage(): string { return 'Usage: tsnap snapshot import <artifact-path|-> [--uid-strategy=keep|new] [--out-dir=DIR] [--no-register]\nImports snapshot artifact created by snapshot.export. Use - to read from STDIN.'; }
    public function examples(): array { return ['tsnap snapshot import snapshot123.tar.gz','cat snapshot123.tar.gz | tsnap snapshot import - --uid-strategy=new']; }

    public function run(array $args, context $ctx): int {
        $artifact=''; $uidStrategy='keep'; $outDir=null; $register=true;
        foreach($args as $a){
            if($artifact==='') { if(!str_starts_with($a,'--')) { $artifact=$a; continue; } }
            if(str_starts_with($a,'--uid-strategy=')) { $uidStrategy=substr($a,15); }
            elseif(str_starts_with($a,'--out-dir=')) { $outDir=substr($a,10); }
            elseif($a==='--no-register') { $register=false; }
        }
        if($artifact==='') { $ctx->out->error('artifact path or - required',64); return 64; }
        $service = new import_service($ctx->manager);
        try { $res = $service->importArtifact($artifact,['uid_strategy'=>$uidStrategy,'register'=>$register,'out_dir'=>$outDir]); }
        catch(ValidationException $ve){ $ctx->out->error($ve->getMessage(),2); return 2; }
        catch(Throwable $e){ $ctx->out->error('import failed: '.$e->getMessage(),1); return 1; }
        $ctx->out->info('imported snapshot '.$res['imported_uid'].' (strategy='.$res['strategy'].')');
        $ctx->out->json($res);
        return 0;
    }
}

