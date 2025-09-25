<?php
namespace Snappy\Cli;

use Snappy\Util\time;

class listing implements command {
    public function name(): string { return 'list'; }
    public function description(): string { return 'List snapshots from one or all remotes'; }

    public function run(array $args, context $ctx): int {
        $full = false;
        $limit = 100;
        $remote = 'local';
        $all = false;
        foreach ($args as $arg) {
            if ($arg === '--full' || $arg === '--full-message') { $full = true; }
            elseif (str_starts_with($arg, '--limit=')) { $limit = (int)substr($arg, 8); }
            elseif (str_starts_with($arg, '--remote=')) { $remote = substr($arg, 9); }
            elseif ($arg === '--all') { $all = true; }
            else {
                fwrite(STDERR, "unknown option: $arg\n");
                return 1;
            }
        }
        $remotes = $all ? $ctx->registry->names() : [$remote];
        $exit = 0;
        foreach ($remotes as $r) {
            if (!$ctx->registry->has($r)) {
                fwrite(STDERR, "unknown remote: $r\n");
                $exit = 2; continue;
            }
            echo "Remote '$r':\n";
            try { $rows = $ctx->manager->list($r, $full, $limit); }
            catch (\Throwable $e) { fwrite(STDERR, 'list failed: ' . $e->getMessage() . "\n"); $exit = 3; continue; }
            if (!$rows) { echo "(none)\n\n"; continue; }
            $w_uid = 3; $w_created = 7; $w_type = 4;
            foreach ($rows as $row) {
                $w_uid = max($w_uid, strlen($row['uid']));
                $w_created = max($w_created, strlen($row['created']));
                $w_type = max($w_type, strlen($row['type']));
            }
            printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n", 'UID','CREATED','TYPE','MESSAGE');
            foreach ($rows as $row) {
                $m = $row['message'];
                if (!$full && strlen($m) > 120) { $m = substr($m,0,117).'...'; }
                printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n", $row['uid'],$row['created'],$row['type'],$m);
            }
            echo "\n";
        }
        return $exit;
    }
}
