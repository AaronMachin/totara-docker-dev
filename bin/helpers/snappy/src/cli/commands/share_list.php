<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_registry;

class share_list extends base_command {
    public function name(): string { return 'share.list'; }
    public function description(): string { return 'List share tokens (hashed)'; }
    public function usage(): string { return 'Usage: tsnap share list\nLists single-use share tokens (hashed) with status.'; }
    public function examples(): array { return [ 'tsnap share list' ]; }

    public function run(array $args, context $ctx): int {
        $reg = new share_registry($ctx->registry->local_base_path());
        $tokens = $reg->list();
        $now = time();
        $rows = [];
        foreach ($tokens as $rec) {
            $hash = $rec['token_hash'] ?? '';
            $uid = $rec['uid'] ?? '';
            $expires = $rec['expires_utc'] ?? '';
            $used = $rec['used_utc'] ?? null;
            $status = 'active';
            $expTs = $expires ? strtotime($expires) : 0;
            if ($used) { $status = 'used'; }
            elseif ($expTs && $expTs < $now) { $status = 'expired'; }
            $rows[] = [
                'token_hash' => $hash,
                'uid' => $uid,
                'created_utc' => $rec['created_utc'] ?? '',
                'expires_utc' => $expires,
                'used_utc' => $used,
                'status' => $status,
                'meta' => $rec['meta'] ?? [],
            ];
        }
        if (!$rows) { $ctx->out->info('No share tokens'); }
        else {
            foreach ($rows as $r) {
                $ctx->out->info(substr($r['token_hash'],0,12) . '  ' . $r['uid'] . '  ' . $r['status'] . '  exp=' . $r['expires_utc']);
            }
        }
        $ctx->out->json(['shares'=>$rows,'count'=>count($rows)]);
        return 0;
    }
}
