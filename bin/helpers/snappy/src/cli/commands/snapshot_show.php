<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_show extends base_command {
    public function name(): string { return 'snapshot.show'; }
    public function description(): string { return 'Show snapshot manifest/details'; }
    public function usage(): string { return 'Usage: tsnap snapshot show <uid|prefix> [--remote=NAME]\nDisplays snapshot details from local (default) or remote.'; }

    public function run(array $args, context $ctx): int {
        $token = null; $remote = 'local';
        foreach ($args as $a) {
            if (str_starts_with($a,'--remote=')) { $remote = substr($a,9); }
            elseif ($token === null && !str_starts_with($a,'--')) { $token = $a; }
        }
        if (!$token) { fwrite(STDERR, "uid or unique prefix required\n"); return 1; }
        if (!$ctx->registry->has($remote)) { fwrite(STDERR, "unknown remote: $remote\n"); return 2; }
        $uid = $ctx->manager->resolve_uid($token, $remote);
        if ($uid === '') { fwrite(STDERR, "no or ambiguous match for '$token' in $remote\n"); return 3; }
        $manifest = $ctx->manager->read_manifest($remote, $uid);
        if (!$manifest) { fwrite(STDERR, "manifest not found for $uid ($remote)\n"); return 4; }
        echo "UID:        {$manifest['uid']}\n";
        echo "REMOTE:     $remote\n";
        $created = $manifest['created'] ?? ($manifest['created_utc'] ?? '');
        echo "CREATED:    $created\n";
        $type = $manifest['snapshot_type'] ?? ($manifest['type'] ?? '');
        echo "TYPE:       $type\n";
        $msg = (string)($manifest['message'] ?? '');
        echo "MESSAGE:\n$msg\n";
        $files = $manifest['files'] ?? [];
        if ($files) {
            echo "FILES (".count($files)."):\n";
            foreach ($files as $f) {
                if (is_array($f)) {
                    $name = $f['name'] ?? '?'; $size = $f['size_bytes'] ?? 0; $comp = !empty($f['compressed']) ? ' [compressed]' : '';
                    echo "  $name ($size bytes)$comp\n";
                } else {
                    echo "  $f\n";
                }
            }
        }
        return 0;
    }
}

