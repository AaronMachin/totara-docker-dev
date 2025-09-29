<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_create_service;
use Snappy\Share\payload_builder;
use Snappy\Support\Exception\ValidationException;
use Throwable;

class share_share_create extends base_command {
    public function name(): string { return 'share.create'; }
    public function description(): string { return 'Serve an exported snapshot artifact via ephemeral HTTP for peer copy/paste import'; }
    public function usage(): string { return 'Usage: tsnap share create <snapshot-uid|prefix> [--listen=HOST:PORT|:PORT] [--ttl=SECONDS] [--max=N] [--multi] [--no-auto-export]\nStarts a minimal HTTP server exposing the snapshot export artifact at /artifact for limited time and downloads.'; }
    public function examples(): array { return ['tsnap share create abc123','tsnap share create abc --listen=0.0.0.0:0 --ttl=600 --multi --max=5']; }
    public function metadata(): array { return ['name'=>$this->name(),'group'=>'Share','description'=>$this->description(),'usage'=>$this->usage(),'examples'=>$this->examples()]; }

    public function run(array $args, context $ctx): int {
        $uid=''; $listen=':0'; $ttl=300; $max=1; $multi=false; $noAuto=false;
        foreach ($args as $a) {
            if ($uid==='') { if(!str_starts_with($a,'--')) { $uid=$a; continue; } }
            if (str_starts_with($a,'--listen=')) { $listen=substr($a,9); }
            elseif (str_starts_with($a,'--ttl=')) { $ttl=(int)substr($a,6); }
            elseif (str_starts_with($a,'--max=')) { $max=(int)substr($a,6); }
            elseif ($a==='--multi') { $multi=true; }
            elseif ($a==='--no-auto-export') { $noAuto=true; }
        }
        if ($uid==='') { $ctx->out->error('snapshot uid/prefix required',64); return 64; }
        $svc = new share_create_service($ctx->manager);
        try {
            $res = $svc->serve($uid,[ 'listen'=>$listen,'ttl'=>$ttl,'max'=>$max,'multi'=>$multi,'no_auto_export'=>$noAuto ], function(array $payload) use ($ctx){
                $encoded = payload_builder::encode($payload);
                $ctx->out->info('share server listening '.$payload['h'].':'.$payload['p']);
                $ctx->out->info('encoded: '.$encoded);
                $ctx->out->info('import cmd: tsnap share import '.$encoded);
                $ctx->out->json(['encoded'=>$encoded,'verification_code'=>$payload['code']]);
            });
            $ctx->out->info('share server done downloads='.$res['downloads']);
        } catch (ValidationException $ve) { $ctx->out->error($ve->getMessage(),2); return 2; }
        catch (Throwable $e) { $ctx->out->error('share create failed: '.$e->getMessage(),1); return 1; }
        return 0;
    }
}
