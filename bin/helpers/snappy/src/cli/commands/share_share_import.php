<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command; use Snappy\Cli\context; use Snappy\Share\share_import_service; use Snappy\Support\Exception\ValidationException; use Throwable;

class share_share_import extends base_command {
    public function name(): string { return 'share.import'; }
    public function description(): string { return 'Import a snapshot from a peer share encoded payload'; }
    public function usage(): string { return 'Usage: tsnap share import <encoded-payload> [--uid-strategy=keep|new]\nDownloads artifact from peer ephemeral server, verifies integrity, imports snapshot.'; }
    public function examples(): array { return ['tsnap share import ABCDxyz123','tsnap share import ABC --uid-strategy=new']; }
    public function metadata(): array { return ['name'=>$this->name(),'group'=>'Share','description'=>$this->description(),'usage'=>$this->usage(),'examples'=>$this->examples()]; }

    public function run(array $args, context $ctx): int {
        $encoded=''; $uidStrategy='new';
        foreach ($args as $a){ if($encoded===''){ if(!str_starts_with($a,'--')){ $encoded=$a; continue; } }
            if(str_starts_with($a,'--uid-strategy=')){ $uidStrategy=substr($a,15); }
        }
        if($encoded===''){ $ctx->out->error('encoded payload required',64); return 64; }
        $svc = new share_import_service($ctx->manager);
        try { $res=$svc->import($encoded,['uid_strategy'=>$uidStrategy]); }
        catch(ValidationException $ve){ $ctx->out->error($ve->getMessage(),2); return 2; }
        catch(Throwable $e){ $ctx->out->error('share import failed: '.$e->getMessage(),1); return 1; }
        $ctx->out->info('imported snapshot '.$res['imported_uid'].' (verification '.$res['verification_code'].')');
        $ctx->out->json($res);
        return 0;
    }
}

