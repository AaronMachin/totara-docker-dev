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
        if ($token === '') { fwrite(STDERR, "uid or unique prefix required\n"); return 1; }
        $uid = $ctx->manager->resolve_uid($token, 'local');
        if ($uid === '') { fwrite(STDERR, "no or ambiguous match for '$token'\n"); return 3; }
        try { $ctx->manager->verify_local($uid); }
        catch (SnapshotNotFoundException $e) { fwrite(STDERR,'verify failed: '.$e->getMessage()."\n"); return 3; }
        echo "verified $uid OK\n"; return 0;
    }
}
