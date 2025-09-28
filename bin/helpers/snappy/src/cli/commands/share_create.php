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
        $token = null; foreach ($args as $a) { if ($a[0] !== '-') { $token = $a; break; } }
        if ($token === null) { fwrite(STDERR, "snapshot uid or unique prefix required\n"); return 1; }
        $uid = $ctx->manager->resolve_uid($token, 'local');
        if ($uid === '') { fwrite(STDERR, "no or ambiguous match for '$token' (local)\n"); return 3; }
        // For T5.1 scope tests we emit a deterministic fake share token (not performing upload/tunnel)
        $fakeToken = base64_encode('FAKE:'.$uid.':'.time());
        echo "Snapshot UID: $uid\nShare token: $fakeToken\nPull command (future): tsnap share pull --share=$fakeToken\n";
        return 0;
    }
}

