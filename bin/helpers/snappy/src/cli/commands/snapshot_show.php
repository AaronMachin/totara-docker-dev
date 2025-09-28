<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_show extends base_command {
    public function name(): string { return 'snapshot.show'; }
    public function description(): string { return 'Show snapshot manifest/details'; }
    public function usage(): string { return 'Usage: tsnap snapshot show <uid|prefix> [--remote=NAME]\nDisplays snapshot details from local (default) or remote.'; }
    public function examples(): array { return [
        'tsnap snapshot show a1b2c3',
        'tsnap snapshot show a1b2 --remote=prod',
    ]; }

    public function run(array $args, context $ctx): int {
        $token = null; $remote = 'local';
        foreach ($args as $a) {
            if (str_starts_with($a,'--remote=')) { $remote = substr($a,9); }
            elseif ($token === null && !str_starts_with($a,'--')) { $token = $a; }
        }
        if (!$token) { $ctx->out->error('uid or unique prefix required', 1); return 1; }
        if (!$ctx->registry->has($remote)) { $ctx->out->error("unknown remote: $remote", 2); return 2; }
        $uid = $ctx->manager->resolve_uid($token, $remote);
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$token' in $remote", 3); return 3; }
        $manifest = $ctx->manager->read_manifest($remote, $uid);
        if (!$manifest) { $ctx->out->error("manifest not found for $uid ($remote)", 4); return 4; }
        $tagsArr = $manifest['tags'] ?? [];
        $tagsLine = 'TAGS:       ' . ($tagsArr ? implode(', ', $tagsArr) : '(none)');
        // Text mode lines
        $lines = [
            'UID:        '.($manifest['uid'] ?? $uid),
            'REMOTE:     '.$remote,
            'CREATED:    '.($manifest['created'] ?? ($manifest['created_utc'] ?? '')),
            'TYPE:       '.($manifest['snapshot_type'] ?? ($manifest['type'] ?? '')),
            $tagsLine,
            'MESSAGE:',
            (string)($manifest['message'] ?? ''),
        ];
        $files = $manifest['files'] ?? [];
        if ($files) { $lines[] = 'FILES ('.count($files).'):'; }
        foreach ($files as $f) {
            if (is_array($f)) {
                $name = $f['name'] ?? '?'; $size = $f['size_bytes'] ?? 0; $comp = !empty($f['compressed']) ? ' [compressed]' : '';
                $lines[] = "  $name ($size bytes)$comp";
            } else { $lines[] = '  '.$f; }
        }
        foreach ($lines as $l) { $ctx->out->info($l); }
        $ctx->out->json(['remote'=>$remote,'manifest'=>$manifest]);
        return 0;
    }
}
