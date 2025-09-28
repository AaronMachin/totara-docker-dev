<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class remote extends base_command {
    public function name(): string { return 'remote'; }
    public function description(): string { return 'Manage snapshot remotes (list, add, remove)'; }
    public function usage(): string { return "Usage:\n  tsnap remote list\n  tsnap remote add <name> s3 --endpoint=URL --bucket=NAME --region=REGION --key=KEY --secret=SECRET [--path-style]\n  tsnap remote remove <name>\nManage snapshot remotes. 'list' shows configured remotes. 'add' registers a new remote. 'remove' deletes it."; }
    public function examples(): array { return ['tsnap remote list','tsnap remote add origin s3 --endpoint=https://s3.example --bucket=mybucket --region=us-east-1 --key=AKIA... --secret=...','tsnap remote remove origin']; }

    public function run(array $args, context $ctx): int {
        $sub = $args[0] ?? '';
        if ($sub === '' || in_array($sub, ['-h','--help'])) { $this->display_help(); return 1; }
        array_shift($args);
        return match($sub) {
            'list' => $this->doList($ctx),
            'add' => $this->doAdd($args,$ctx),
            'remove','rm' => $this->doRemove($args,$ctx),
            default => (function() use ($ctx) { $ctx->out->error('unknown remote subcommand', 1); $this->display_help(); return 1; })(),
        };
    }

    private function doList(context $ctx): int {
        $remotes = $ctx->registry->list();
        if (!$remotes) { $ctx->out->info('(none)'); $ctx->out->json(['remotes'=>[]]); return 0; }
        $headers = ['NAME','TYPE','CREATED','DETAILS'];
        $rows = [];
        foreach ($remotes as $name=>$meta) {
            $type=$meta['type'];
            $details='';
            if($type==='local'){ $details=$meta['path']??''; }
            elseif($type==='s3'){ $cfg=$meta['config']??[]; $details=($cfg['endpoint']??'').'/'.($cfg['bucket']??''); }
            $rows[] = [$name,$type,$meta['created']??'',$details];
        }
        $ctx->out->table($headers, $rows);
        $ctx->out->json(['remotes'=>$remotes]);
        return 0;
    }

    private function doAdd(array $args, context $ctx): int {
        $name = $args[0] ?? ''; $type = $args[1] ?? '';
        if ($name==='' || $type==='') { $ctx->out->error('remote add requires <name> <type>', 1); $this->display_help(); return 1; }
        array_shift($args); array_shift($args);
        $config=[]; foreach ($args as $arg){ if(!str_starts_with($arg,'--')) continue; if(str_starts_with($arg,'--endpoint=')) $config['endpoint']=substr($arg,11); elseif(str_starts_with($arg,'--bucket=')) $config['bucket']=substr($arg,9); elseif(str_starts_with($arg,'--region=')) $config['region']=substr($arg,9); elseif(str_starts_with($arg,'--key=')) $config['key']=substr($arg,6); elseif(str_starts_with($arg,'--secret=')) $config['secret']=substr($arg,9); elseif($arg==='--path-style') $config['path_style']=true; }
        $ctx->registry->add($name,$type,$config); // may throw mapped exception
        $ctx->out->info("added remote $name ($type)");
        $ctx->out->json(['action'=>'add','name'=>$name,'type'=>$type,'config'=>$config]);
        return 0;
    }

    private function doRemove(array $args, context $ctx): int {
        $name = $args[0] ?? ''; if ($name===''){ $ctx->out->error('remote remove requires <name>', 1); return 1; }
        $ctx->registry->remove($name); // may throw mapped exception
        $ctx->out->info("removed remote $name");
        $ctx->out->json(['action'=>'remove','name'=>$name]);
        return 0;
    }
}
