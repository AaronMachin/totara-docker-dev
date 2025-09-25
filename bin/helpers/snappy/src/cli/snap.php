<?php
namespace Snappy\Cli;

use Snappy\Util\editor;
use Throwable;

class snap implements command {
    public function name(): string { return 'snap'; }
    public function description(): string { return 'Create a snapshot'; }

    public function run(array $args, context $ctx): int {
        $type = 'sql';
        $message = '';
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif ($arg === '-m' && isset($args[$i + 1])) {
                $message = $args[$i + 1];
                $i++;
            }
        }
        if ($message === '') {
            $tpl = "\n\n# Enter snapshot message (lines starting with # ignored)\n# Abort with empty message.\n";
            $message = editor::acquire($tpl);
            if ($message === '') {
                fwrite(STDERR, "snapshot message required\n");
                return 4;
            }
        }
        try {
            $uid = $ctx->manager->create($type, $message);
        } catch (Throwable $e) {
            fwrite(STDERR, 'create failed: ' . $e->getMessage() . "\n");
            return 1;
        }
        echo "created snapshot $uid\n";
        return 0;
    }
}

