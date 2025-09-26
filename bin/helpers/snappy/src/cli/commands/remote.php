<?php

namespace Snappy\Cli\Commands;

use RuntimeException;
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
            default => (function() { fwrite(STDERR,"unknown remote subcommand\n"); $this->display_help(); return 1; })(),
        };
    }

    private function doList(context $ctx): int {
        $remotes = $ctx->registry->list();
        if (!$remotes) { echo "(none)\n"; return 0; }
        $w_name=4;$w_type=4;$w_created=7;
        foreach ($remotes as $name=>$meta){$w_name=max($w_name,strlen($name));$w_type=max($w_type,strlen($meta['type']??''));$w_created=max($w_created,strlen($meta['created']??''));}
        printf("%-{$w_name}s  %-{$w_type}s  %-{$w_created}s  %s\n",'NAME','TYPE','CREATED','DETAILS');
        foreach ($remotes as $name=>$meta){$type=$meta['type'];$details='';if($type==='local'){$details=$meta['path']??'';}elseif($type==='s3'){$cfg=$meta['config']??[];$details=($cfg['endpoint']??'').'/'.($cfg['bucket']??'');}printf("%-{$w_name}s  %-{$w_type}s  %-{$w_created}s  %s\n",$name,$type,$meta['created']??'',$details);} return 0;
    }

    private function doAdd(array $args, context $ctx): int {
        $name = $args[0] ?? ''; $type = $args[1] ?? '';
        if ($name==='' || $type==='') { fwrite(STDERR,"remote add requires <name> <type>\n"); $this->display_help(); return 1; }
        array_shift($args); array_shift($args);
        $config=[]; foreach ($args as $arg){ if(!str_starts_with($arg,'--')) continue; if(str_starts_with($arg,'--endpoint=')) $config['endpoint']=substr($arg,11); elseif(str_starts_with($arg,'--bucket=')) $config['bucket']=substr($arg,9); elseif(str_starts_with($arg,'--region=')) $config['region']=substr($arg,9); elseif(str_starts_with($arg,'--key=')) $config['key']=substr($arg,6); elseif(str_starts_with($arg,'--secret=')) $config['secret']=substr($arg,9); elseif($arg==='--path-style') $config['path_style']=true; }
        try { $ctx->registry->add($name,$type,$config); }
        catch (RuntimeException $e) { fwrite(STDERR,'add failed: '.$e->getMessage()."\n"); return 2; }
        echo "added remote $name ($type)\n"; return 0;
    }

    private function doRemove(array $args, context $ctx): int {
        $name = $args[0] ?? ''; if ($name===''){ fwrite(STDERR,"remote remove requires <name>\n"); return 1; }
        try { $ctx->registry->remove($name); }
        catch (RuntimeException $e) { fwrite(STDERR,'remove failed: '.$e->getMessage()."\n"); return 2; }
        echo "removed remote $name\n"; return 0;
    }
}
