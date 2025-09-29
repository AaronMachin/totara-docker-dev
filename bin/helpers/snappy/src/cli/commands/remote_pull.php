<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command; use Snappy\Cli\context;
use Snappy\Remote\remote_pull_service;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnappyException;
use Snappy\Support\Exception\ExitCodes;
use Throwable;

class remote_pull extends base_command {
    public function name(): string { return 'remote.pull'; }
    public function description(): string { return 'Pull (download) a snapshot directory from a remote into local snapshots'; }
    public function usage(): string { return 'Usage: tsnap remote pull <remote> <uid|prefix> [--uid-strategy=keep|new] [--force] [--progress] [--out-dir=DIR]\nDownloads remote snapshot snaps/<uid>/ into local snapshot store verifying integrity when manifest available.'; }
    public function examples(): array { return [ 'tsnap remote pull prod abcd1234', 'tsnap remote pull prod abcd --uid-strategy=new', 'tsnap remote pull prod abcdef --force --progress' ]; }

    public function run(array $args, context $ctx): int {
        $remote = $args[0] ?? ''; $token = $args[1] ?? '';
        if($remote===''||$token===''){ $ctx->out->error('remote and uid|prefix required',64); return 64; }
        $uidStrategy='keep'; $force=false; $progress=false; $outDir=null;
        for($i=2;$i<count($args);$i++){ $a=$args[$i]; if($a==='--force'){ $force=true; continue; } if($a==='--progress'){ $progress=true; continue; } if(str_starts_with($a,'--uid-strategy=')){ $uidStrategy=substr($a,15); continue; } if(str_starts_with($a,'--out-dir=')){ $outDir=substr($a,10); continue; } }
        $svc = new remote_pull_service($ctx->registry);
        try { $res=$svc->pull($remote,$token,['uid_strategy'=>$uidStrategy,'force'=>$force,'progress'=>$progress && !$ctx->out->isJson(),'out_dir'=>$outDir]); }
        catch(ValidationException $ve){ $code=ExitCodes::codeFor($ve); $ctx->out->error($ve->getMessage(),$code); return $code; }
        catch(SnappyException $se){ $code=ExitCodes::codeFor($se); $ctx->out->error($se->getMessage(),$code); return $code; }
        catch(Throwable $t){ $ctx->out->error('pull failed: '.$t->getMessage(), ExitCodes::UNKNOWN); return ExitCodes::UNKNOWN; }
        $ctx->out->info('pulled snapshot '.$res['final_uid'].' from '.$remote.' (original '.$res['original_uid'].') files='.$res['files'].' bytes='.$res['bytes'].' verified='.( $res['verified']?'yes':'no').' strategy='.(($res['original_uid']===$res['final_uid'])?'keep':'new'));
        $ctx->out->json(['action'=>'remote_pull','remote'=>$remote,'uid'=>$res['original_uid'],'final_uid'=>$res['final_uid'],'files'=>$res['files'],'bytes'=>$res['bytes'],'verified'=>$res['verified']] + (isset($res['provenance_path'])?['provenance_path'=>$res['provenance_path']]:[]));
        return 0;
    }
}

