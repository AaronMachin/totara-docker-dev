<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Util\editor;
use Throwable;

class snap extends base_command {
    public function name(): string { return 'snap'; }
    public function description(): string { return 'Create a snapshot'; }
    public function usage(): string { return 'Usage: tsnap snap [-m <message>] [--type=sql] [--compress] [--keep-failed]\nCreates a snapshot. If -m omitted an editor will open.'; }
    public function examples(): array { return ['tsnap snap -m "initial load"','tsnap snap --type=sql -m "before upgrade"','tsnap snap --compress -m "with compression"','tsnap snap --keep-failed -m "debug failing dump"']; }

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
            if ($message==='') { fwrite(STDERR,"snapshot message required\n"); return 4; }
        }
        try { $uid = $ctx->manager->create($type,$message,'local',$compress,$keepFailed); }
        catch (Throwable $e) { fwrite(STDERR,'create failed: '.$e->getMessage()."\n"); return 1; }
        echo "created snapshot $uid\n"; return 0;
    }
}
