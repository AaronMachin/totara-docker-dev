<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Util\editor;
use Snappy\Support\Exception\ValidationException;
use Throwable;

class snapshot_create extends base_command {
    public function name(): string { return 'snapshot.create'; }
    public function description(): string { return 'Create a snapshot (snapshot create)'; }
    public function usage(): string { return 'Usage: tsnap snapshot create [-m <message>] [--type=sql] [--compress] [--keep-failed]\nCreates a snapshot. If -m omitted an editor will open.'; }
    public function examples(): array { return ['tsnap snapshot create -m "initial load"','tsnap snapshot create --compress -m "before upgrade"']; }

    public function run(array $args, context $ctx): int {
        $type = 'sql'; $message = ''; $compress = false; $keepFailed = false;
        for ($i=0;$i<count($args);$i++) {
            $arg = $args[$i];
            if (str_starts_with($arg,'--type=')) { $type = substr($arg,7); }
            elseif ($arg==='-m' && isset($args[$i+1])) { $message = $args[$i+1]; $i++; }
            elseif ($arg==='--compress') { $compress = true; }
            elseif ($arg==='--keep-failed') { $keepFailed = true; }
        }
        if ($message==='') {
            $tpl = "\n\n# Enter snapshot message (lines starting with # ignored)\n# Abort with empty message.\n";
            $message = editor::acquire($tpl);
            if ($message==='') { $ctx->out->error('snapshot message required', 4); return 4; }
        }
        try { $uid = $ctx->manager->create($type,$message,'local',$compress,$keepFailed); }
        catch (ValidationException $ve) { throw $ve; }
        catch (Throwable $e) { $ctx->out->error('create failed: '.$e->getMessage(), 1); return 1; }
        $ctx->out->info("created snapshot $uid");
        $ctx->out->json(['uid'=>$uid,'type'=>$type,'compressed'=>$compress,'keep_failed'=>$keepFailed]);
        return 0;
    }
}
