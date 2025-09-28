<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class remote_list extends base_command {
    public function name(): string { return 'remote.list'; }
    public function description(): string { return 'List configured snapshot remotes'; }
    public function usage(): string { return 'Usage: tsnap remote list'; }
    public function examples(): array { return [
        'tsnap remote list',
    ]; }

    public function run(array $args, context $ctx): int {
        $remotes = $ctx->registry->list();
        if (isset($remotes['local'])) { unset($remotes['local']); }
        if (!$remotes) { $ctx->out->info('(none)'); $ctx->out->json(['remotes'=>[]]); return 0; }
        $headers = ['NAME','TYPE','CREATED','DETAILS'];
        $rows = [];
        foreach ($remotes as $name=>$meta) {
            $type=$meta['type']??''; $details='';
            if($type==='local'){ $details=$meta['path']??''; }
            elseif($type==='s3'){ $cfg=$meta['config']??[]; $details=($cfg['endpoint']??'').'/'.($cfg['bucket']??''); }
            $rows[] = [$name,$type,$meta['created']??'',$details];
        }
        $ctx->out->table($headers,$rows);
        $ctx->out->json(['remotes'=>$remotes]);
        return 0;
    }
}
