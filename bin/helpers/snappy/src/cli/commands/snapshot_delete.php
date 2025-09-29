<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\ProcessFailedException;

class snapshot_delete extends base_command {
    public function name(): string { return 'snapshot.delete'; }
    public function description(): string { return 'Delete a local snapshot by UID or unique prefix'; }
    public function usage(): string { return 'Usage: tsnap snapshot delete <uid|prefix>'; }
    public function examples(): array { return ['tsnap snapshot delete 1234abcd','tsnap snapshot delete deadbeefcaf (prefix)']; }

    public function run(array $args, context $ctx): int {
        if (count($args) < 1) { $ctx->out->error('missing <uid|prefix>',64); return 64; }
        $token = trim($args[0]); if($token===''){ $ctx->out->error('empty uid/prefix',64); return 64; }
        // Determine matches (cannot rely on resolve_uid to distinguish ambiguity)
        $all = array_map(fn($r)=>$r['uid'],$ctx->manager->list('local', false, 1000));
        $matches = [];
        foreach ($all as $u) { if (str_starts_with($u,$token)) { $matches[]=$u; } }
        if (in_array($token,$all,true)) { $matches = [$token]; } // exact wins
        if (count($matches) === 0) { $ctx->out->error('snapshot not found',2); return 2; }
        if (count($matches) > 1) { $ctx->out->error('ambiguous prefix',64); return 64; }
        $uid = $matches[0];
        try {
            $info = $ctx->manager->delete($uid);
            if ($info === false) { $ctx->out->error('snapshot not found',2); return 2; }
            $bytes = (int)($info['bytes'] ?? 0);
            $ctx->out->info('deleted ' . $uid . ($bytes>0? ' (' . $bytes . ' bytes)':'') );
            $payload = ['deleted_uid'=>$uid,'index_pruned'=>true]; if($bytes>0){ $payload['size_bytes']=$bytes; }
            $ctx->out->json($payload);
            return 0;
        } catch (ProcessFailedException $pe) {
            $ctx->out->error($pe->getMessage(),1); return 1;
        } catch (ValidationException $ve) {
            $ctx->out->error($ve->getMessage(),2); return 2;
        } catch (\Throwable $t) {
            $ctx->out->error('delete failed: '.$t->getMessage(),1); return 1;
        }
    }
}

