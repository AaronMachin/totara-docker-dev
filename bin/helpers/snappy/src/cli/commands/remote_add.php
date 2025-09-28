<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class remote_add extends base_command {
    public function name(): string { return 'remote.add'; }
    public function description(): string { return 'Add a snapshot remote (currently supports local/s3)'; }
    public function usage(): string { return 'Usage: tsnap remote add <name> <type> [--endpoint=URL --bucket=NAME --region=REG --key=K --secret=S --path-style]'; }

    public function run(array $args, context $ctx): int {
        $name = $args[0] ?? ''; $type = $args[1] ?? '';
        if ($name === '' || $type === '') { $ctx->out->error('remote add requires <name> <type>', 1); return 1; }
        $config = [];
        for ($i=2;$i<count($args);$i++) {
            $arg = $args[$i];
            if (!str_starts_with($arg,'--')) { continue; }
            if (str_starts_with($arg,'--endpoint=')) { $config['endpoint'] = substr($arg,11); }
            elseif (str_starts_with($arg,'--bucket=')) { $config['bucket'] = substr($arg,9); }
            elseif (str_starts_with($arg,'--region=')) { $config['region'] = substr($arg,9); }
            elseif (str_starts_with($arg,'--key=')) { $config['key'] = substr($arg,6); }
            elseif (str_starts_with($arg,'--secret=')) { $config['secret'] = substr($arg,9); }
            elseif ($arg==='--path-style') { $config['path_style'] = true; }
        }
        $ctx->registry->add($name,$type,$config); // may throw mapped exceptions upstream
        $ctx->out->info("added remote $name ($type)");
        $ctx->out->json(['action'=>'add','name'=>$name,'type'=>$type,'config'=>$config]);
        return 0;
    }
}

