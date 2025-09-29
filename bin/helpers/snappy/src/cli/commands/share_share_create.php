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
    public function usage(): string { return 'Usage: tsnap share create <snapshot-uid|prefix> [--listen=HOST:PORT|:PORT] [--ttl=SECONDS] [--max=N] [--multi] [--no-auto-export] [--lan-only] [--debug]\nStarts a minimal HTTP server exposing the snapshot export artifact at /artifact. By default will attempt ngrok TCP tunnel for internet sharing unless --lan-only specified. Use --debug to print diagnostic lines.'; }
    public function examples(): array { return ['tsnap share create abc123','tsnap share create abc --listen=0.0.0.0:0 --ttl=600 --multi --max=5']; }
    public function metadata(): array { return ['name'=>$this->name(),'group'=>'Share','description'=>$this->description(),'usage'=>$this->usage(),'examples'=>$this->examples()]; }

    public function run(array $args, context $ctx): int {
        $uid=''; $listen=':0'; $ttl=300; $max=1; $multi=false; $noAuto=false; $lanOnly=false; $debugFlag=false;
        foreach ($args as $a) {
            if ($uid==='') { if(!str_starts_with($a,'--')) { $uid=$a; continue; } }
            if (str_starts_with($a,'--listen=')) { $listen=substr($a,9); }
            elseif (str_starts_with($a,'--ttl=')) { $ttl=(int)substr($a,6); }
            elseif (str_starts_with($a,'--max=')) { $max=(int)substr($a,6); }
            elseif ($a==='--multi') { $multi=true; }
            elseif ($a==='--no-auto-export') { $noAuto=true; }
            elseif ($a==='--lan-only') { $lanOnly=true; }
            elseif ($a==='--debug') { $debugFlag=true; }
        }
        // config based debug fallback
        if(!$debugFlag){ $debugFlag = (bool)$ctx->config->get('options.debug', false); }
        if ($uid==='') { $ctx->out->error('snapshot uid/prefix required',64); return 64; }
        $svc = new share_create_service($ctx->manager);
        try {
            $res = $svc->serve($uid,[ 'listen'=>$listen,'ttl'=>$ttl,'max'=>$max,'multi'=>$multi,'no_auto_export'=>$noAuto,'lan_only'=>$lanOnly,'config'=>$ctx->config,'debug'=>$debugFlag ], function(array $payload) use ($ctx,$debugFlag){
                $encoded = payload_builder::encode($payload);
                if($debugFlag){
                    $dbg=$payload['debug']??[]; foreach($dbg as $d){ $ctx->out->info('[debug] '.$d); }
                }
                $ctx->out->info('share server listening '.$payload['h'].':'.$payload['p']);
                if(!empty($payload['tunnel']) && $payload['tunnel']==='ngrok'){ $ctx->out->info('tunnel: ngrok'); }
                if(!empty($payload['tunnel_failed'])){ $ctx->out->info('tunnel setup failed; falling back to direct host/port'); }
                $ctx->out->info('encoded: '.$encoded);
                $ctx->out->info('import cmd: tsnap share import '.$encoded);
                $json=['encoded'=>$encoded,'verification_code'=>$payload['code'],'tunnel'=>$payload['tunnel']??'none']; if(!empty($payload['tunnel_failed'])){ $json['tunnel_failed']=true; } if($debugFlag){ $json['debug']=$payload['debug']??[]; }
                $ctx->out->json($json);
            });
            $ctx->out->info('share server done downloads='.$res['downloads']);
        } catch (ValidationException $ve) { $ctx->out->error($ve->getMessage(),2); return 2; }
        catch (\Throwable $e) { $ctx->out->error('share create failed: '.$e->getMessage(),1); return 1; }
        return 0;
    }
}
