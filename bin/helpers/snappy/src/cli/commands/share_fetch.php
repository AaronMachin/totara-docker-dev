<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_registry;

class share_fetch extends base_command {
    public function name(): string { return 'share.fetch'; }
    public function description(): string { return 'Fetch a snapshot via single-use share token'; }
    public function usage(): string { return 'Usage: tsnap share fetch <token>\nResolves token, marks it used, outputs snapshot path & uid.'; }
    public function examples(): array { return ['tsnap share fetch AbCdEf...']; }

    public function run(array $args, context $ctx): int {
        $raw = null; foreach ($args as $a) { if ($a !== '' && $a[0] !== '-') { $raw = $a; break; } }
        if ($raw === null) { $ctx->out->error('share token required', 1); return 1; }
        // Basic format sanity (base64url-ish) optional
        if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $raw)) { $ctx->out->error('invalid token format', 2); return 2; }
        $reg = new share_registry($ctx->registry->local_base_path());
        $record = $reg->consume($raw);
        if (!$record) { $ctx->out->error('invalid, expired, or already used token', 3); return 3; }
        $uid = $record['uid'] ?? '';
        if ($uid === '') { $ctx->out->error('token record missing uid', 4); return 4; }
        $path = $ctx->manager->local_path($uid);
        if (!is_dir($path)) { $ctx->out->error('snapshot not found locally for uid ' . $uid, 5); return 5; }
        $ctx->out->info('UID: ' . $uid);
        $ctx->out->info('Path: ' . $path);
        $ctx->out->json(['uid'=>$uid,'path'=>$path]);
        return 0;
    }
}

