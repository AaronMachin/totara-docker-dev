<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnapshotNotFoundException;

class snapshot_tag extends base_command {
    public function name(): string { return 'snapshot.tag'; }
    public function description(): string { return 'Add or remove a tag from a local snapshot'; }
    public function usage(): string { return "Usage: tsnap snapshot tag <uid|prefix> <add|remove> <tag>\nTag regex: ^[a-z0-9][a-z0-9_-]{0,31}$ (lowercase)."; }
    public function examples(): array { return [
        'tsnap snapshot tag a1b2c3 add release_2025_09',
        'tsnap snapshot tag a1b2 remove release_2025_09',
    ]; }

    public function run(array $args, context $ctx): int {
        if (count($args) < 3) { $ctx->out->error('args: <uid|prefix> <add|remove> <tag>', 1); return 1; }
        [$token,$action,$tag] = [$args[0],$args[1],$args[2]];
        if ($action !== 'add' && $action !== 'remove') { $ctx->out->error('action must be add or remove',1); return 1; }
        $tagTrim = trim($tag);
        if ($tagTrim === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $tagTrim)) {
            $ctx->out->info('invalid tag format');
            $ctx->out->error('invalid tag format', 4);
            return 4;
        }
        $uid = $ctx->manager->resolve_uid($token, 'local');
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$token'", 3); return 3; }
        try {
            if ($action === 'add') {
                $added = $ctx->manager->add_tag($uid, $tag);
                $manifest = $ctx->manager->read_manifest('local',$uid) ?? [];
                $tags = $manifest['tags'] ?? [];
                $ctx->out->info($added ? "tag added: $tag" : "tag exists: $tag");
                $ctx->out->json(['uid'=>$uid,'action'=>'add','tag'=>$tag,'added'=>$added,'tags'=>$tags]);
                return 0;
            } else {
                $removed = $ctx->manager->remove_tag($uid, $tag);
                $manifest = $ctx->manager->read_manifest('local',$uid) ?? [];
                $tags = $manifest['tags'] ?? [];
                $ctx->out->info($removed ? "tag removed: $tag" : "tag not present: $tag");
                $ctx->out->json(['uid'=>$uid,'action'=>'remove','tag'=>$tag,'removed'=>$removed,'tags'=>$tags]);
                return 0;
            }
        } catch (ValidationException $ve) {
            $ctx->out->error($ve->getMessage(), 4); return 4;
        } catch (SnapshotNotFoundException $snf) {
            $ctx->out->error($snf->getMessage(), 2); return 2;
        }
    }
}
