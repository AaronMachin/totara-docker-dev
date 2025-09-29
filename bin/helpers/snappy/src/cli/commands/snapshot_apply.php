<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Snapshot\DumpProviderResolver;
use Snappy\Support\Exception\ValidationException;

class snapshot_apply extends base_command {
    public function name(): string { return 'snapshot.apply'; }
    public function description(): string { return 'Apply (restore) a local SQL snapshot to the developer database'; }
    public function usage(): string { return 'Usage: tsnap snapshot apply <uid|prefix>'; }
    public function examples(): array { return [ 'tsnap snapshot apply a1b2c3d4', 'tsnap apply a1b2 (root alias)' ]; }

    public function run(array $args, context $ctx): int {
        if (count($args) < 1) { $ctx->out->error('missing <uid|prefix>',64); return 64; }
        $token = trim($args[0]); if($token===''){ $ctx->out->error('empty uid/prefix',64); return 64; }
        // Compute matches manually (cannot rely on resolve_uid for ambiguity detection)
        $uids = array_map(fn($r)=>$r['uid'],$ctx->manager->list('local', false, 1000));
        $matches = [];
        foreach ($uids as $u) { if (str_starts_with($u,$token)) { $matches[] = $u; } }
        if (in_array($token,$uids,true)) { $matches = [$token]; }
        if (count($matches) === 0) { $ctx->out->error('snapshot not found',2); return 2; }
        if (count($matches) > 1) { $ctx->out->error('ambiguous prefix',64); return 64; }
        $uid = $matches[0];
        $manifest = $ctx->manager->read_manifest('local',$uid);
        if(!$manifest){ $ctx->out->error('manifest not found',2); return 2; }
        $type = $manifest['snapshot_type'] ?? ($manifest['type'] ?? '');
        if ($type !== 'sql') { $ctx->out->error('unsupported snapshot type',2); return 2; }
        $resolver = new DumpProviderResolver();
        try {
            $provider = $resolver->resolve(['type'=>'sql']);
            $snapshotDir = $ctx->manager->local_path($uid);
            $res = $provider->apply($snapshotDir, ['registry'=>$ctx->registry]);
        } catch (ValidationException $ve) { $ctx->out->error($ve->getMessage(),2); return 2; }
        // Let ProcessFailedException bubble to framework (exit 5) per spec.
        $bytes = $res->bytes(); $files = $res->files();
        $ctx->out->info('applied '.$uid.' ('.$bytes.' bytes)');
        $ctx->out->json(['applied_uid'=>$uid,'applied_bytes'=>$bytes,'files_used'=>$files,'command_version'=>1]);
        return 0;
    }
}

