<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class share_create extends base_command {
    public function name(): string { return 'share.create'; }
    public function description(): string { return 'Create a one-time share token for a snapshot (placeholder implementation)'; }
    public function usage(): string { return 'Usage: tsnap share create <uid|prefix>\nPackages and presigns snapshot (full hosting integration deferred in tests).'; }
    public function examples(): array { return ['tsnap share create a1b2c3']; }

    public function run(array $args, context $ctx): int {
        $token = null; foreach ($args as $a) { if ($a !== '' && $a[0] !== '-') { $token = $a; break; } }
        if ($token === null) { $ctx->out->error('snapshot uid or unique prefix required', 1); return 1; }
        $uid = $ctx->manager->resolve_uid($token, 'local');
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$token' (local)", 3); return 3; }
        $fakeToken = base64_encode('FAKE:'.$uid.':'.time());
        $ctx->out->info('Snapshot UID: '.$uid);
        $ctx->out->info('Share token: '.$fakeToken);
        $ctx->out->info('Pull command (future): tsnap share pull --share='.$fakeToken);
        $ctx->out->json(['uid'=>$uid,'share_token'=>$fakeToken]);
        return 0;
    }
}
