<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_registry;

class share_create extends base_command {
    public function name(): string { return 'share.create'; }
    public function description(): string { return 'Create a one-time share token for a snapshot'; }
    public function usage(): string { return 'Usage: tsnap share create <uid|prefix> [--expire=1h]\nCreates a single-use share token (stored hashed).'; }
    public function examples(): array { return ['tsnap share create a1b2c3','tsnap share create a1b2c3 --expire=30m']; }

    public function run(array $args, context $ctx): int {
        $tokenArg = null; $expireSpec = '24h';
        foreach ($args as $a) {
            if ($a !== '' && $a[0] !== '-') { $tokenArg = $a; continue; }
            if (str_starts_with($a,'--expire=')) { $expireSpec = substr($a,9); }
        }
        if ($tokenArg === null) { $ctx->out->error('snapshot uid or unique prefix required', 1); return 1; }
        $uid = $ctx->manager->resolve_uid($tokenArg, 'local');
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$tokenArg' (local)", 3); return 3; }
        $ttl = $this->parseExpire($expireSpec);
        if ($ttl <= 0) { $ctx->out->error('invalid --expire specification', 2); return 2; }
        $registry = new share_registry($ctx->registry->local_base_path());
        // Generate collision-resistant token (base64url without padding)
        $raw = $this->generateToken($registry);
        $hash = hash('sha256', $raw);
        $manifest = $ctx->manager->read_manifest('local', $uid) ?? [];
        $message = (string)($manifest['message'] ?? '');
        $firstLine = $message === '' ? '' : preg_split('/\r?\n/', $message, 2)[0];
        $tags = is_array($manifest['tags'] ?? null) ? array_values($manifest['tags']) : [];
        $created = gmdate('c');
        $expires = gmdate('c', time() + $ttl);
        $record = [
            'token_hash' => $hash,
            'uid' => $uid,
            'created_utc' => $created,
            'expires_utc' => $expires,
            'used_utc' => null,
            'meta' => [ 'tags' => $tags, 'message_first_line' => $firstLine ],
        ];
        $registry->add($record);
        $ctx->out->info('Snapshot UID: ' . $uid);
        $ctx->out->info('Share token (store securely; shown once): ' . $raw);
        $ctx->out->info('Expires: ' . $expires);
        $ctx->out->json(['uid'=>$uid,'share_token'=>$raw,'expires_utc'=>$expires]);
        return 0;
    }

    private function generateToken(share_registry $reg): string {
        // Loop until collision-free (extremely unlikely to loop >1)
        for ($i=0;$i<5;$i++) {
            $raw = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            if (!$reg->hasHash(hash('sha256',$raw))) { return $raw; }
        }
        // Fallback include random uniqid if pathological collisions
        return rtrim(strtr(base64_encode(random_bytes(24) . uniqid('', true)), '+/', '-_'), '=');
    }

    private function parseExpire(string $spec): int {
        $spec = trim($spec);
        if ($spec === '') { return 0; }
        if (preg_match('/^(\d+)([mhd])?$/i', $spec, $m)) {
            $n = (int)$m[1]; $unit = strtolower($m[2] ?? 's');
            return match($unit) {
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                default => $n, // seconds
            };
        }
        return 0; // invalid
    }
}
