<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Support\Exception\SnapshotNotFoundException;

class verify_run extends base_command {
    public function name(): string { return 'verify.run'; }
    public function description(): string { return 'Verify integrity (checksums) of a local snapshot'; }
    public function usage(): string { return 'Usage: tsnap verify run <uid|prefix>'; }
    public function run(array $args, context $ctx): int {
        $token = $args[0] ?? '';
        if ($token === '') { $ctx->out->error('uid or unique prefix required', 1); return 1; }
        $uid = $ctx->manager->resolve_uid($token, 'local');
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$token'", 3); return 3; }
        try { $ctx->manager->verify_local($uid); }
        catch (SnapshotNotFoundException $e) { $ctx->out->error('verify failed: '.$e->getMessage(), 3); return 3; }
        $ctx->out->info("verified $uid OK");
        $ctx->out->json(['action'=>'verify','uid'=>$uid,'status'=>'ok']);
        return 0;
    }
}
