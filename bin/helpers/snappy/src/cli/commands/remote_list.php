<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class remote_list extends base_command {
    public function name(): string { return 'remote.list'; }
    public function description(): string { return 'List configured remotes (credentials redacted)'; }
    public function usage(): string { return 'Usage: tsnap remote list [--json]\nShows configured remotes excluding the built-in local.'; }
    public function examples(): array { return [ 'tsnap remote list', 'tsnap remote list --json' ]; }

    public function run(array $args, context $ctx): int {
        $remotes = $ctx->registry->list();
        if (isset($remotes['local'])) { unset($remotes['local']); }
        if (!$remotes) { $ctx->out->info('(none)'); $ctx->out->json(['remotes'=>[]]); return 0; }
        // Stable sort by name
        ksort($remotes, SORT_NATURAL | SORT_FLAG_CASE);
        $headers = ['NAME','ENDPOINT','BUCKET','REGION','KEY','SECRET','PATHSTYLE'];
        $rows = [];
        $jsonOut = [];
        foreach ($remotes as $name => $meta) {
            $cfg = $meta['config'] ?? [];
            $endpoint = (string)($cfg['endpoint'] ?? '');
            $bucket = (string)($cfg['bucket'] ?? '');
            $region = (string)($cfg['region'] ?? '');
            $key = $cfg['key'] ?? '';
            $keyDisp = $key !== '' ? substr($key,0,4) : '';
            $secretDisp = (isset($cfg['secret']) && $cfg['secret']!=='') ? '********' : '';
            $pathStyle = !empty($cfg['path_style']) ? 'yes' : 'no';
            $rows[] = [$name,$endpoint,$bucket,$region,$keyDisp,$secretDisp,$pathStyle];
            $jsonOut[$name] = [
                'endpoint'=>$endpoint,
                'bucket'=>$bucket,
                'region'=>$region,
                'path_style'=>!empty($cfg['path_style']),
            ];
        }
        $ctx->out->table($headers,$rows);
        $ctx->out->json(['remotes'=>$jsonOut]);
        return 0;
    }
}
