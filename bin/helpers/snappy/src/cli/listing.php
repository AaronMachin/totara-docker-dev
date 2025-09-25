<?php
namespace Snappy\Cli;

use Snappy\Util\time;

class listing implements command {
    public function name(): string { return 'list'; }
    public function description(): string { return 'List local and/or remote snapshots'; }

    public function run(array $args, context $ctx): int {
        $prefix = '';
        $limit = 100;
        $full = false;
        $local_only = false;
        $remote_only = false;
        foreach ($args as $arg) {
            if ($arg === '--full-message') {
                $full = true;
            } elseif ($arg === '--local-only') {
                $local_only = true;
            } elseif ($arg === '--remote-only') {
                $remote_only = true;
            } elseif (str_starts_with($arg, '--prefix=')) {
                $prefix = substr($arg, 9);
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = (int)substr($arg, 8);
            } else {
                fwrite(STDERR, "unknown option: $arg\n");
                return 1;
            }
        }
        if ($remote_only) {
            $local_only = false;
        }
        if (!$remote_only) {
            echo "Local snapshots:\n";
            $local_rows = $ctx->manager->list_local($full);
            if (!$local_rows) {
                echo "(none)\n";
            } else {
                $w_uid = 3; $w_created = 7; $w_type = 4;
                foreach ($local_rows as $r) {
                    $w_uid = max($w_uid, strlen($r['uid']));
                    $w_created = max($w_created, strlen($r['created']));
                    $w_type = max($w_type, strlen($r['type']));
                }
                printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n", 'UID', 'CREATED', 'TYPE', 'MESSAGE');
                foreach ($local_rows as $r) {
                    $m = $r['message'];
                    if (strlen($m) > 120) { $m = substr($m, 0, 117) . '...'; }
                    printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s\n", $r['uid'], $r['created'], $r['type'], $m);
                }
            }
            echo "\n";
        }
        if ($local_only) { return 0; }
        $remote = $ctx->manager->list_remote($prefix, $limit, $full);
        $age = time::human_age($remote['retrieved_at']);
        echo 'Remote snapshots (' . ($remote['fetched'] ? 'fresh' : 'cached ' . $age) . ") prefix='" . ($prefix ?: '/') . "' limit=$limit:\n";
        $rows = $remote['rows'];
        if (!$rows) { echo "(none)\n"; return 0; }
        $w_uid = 3; $w_created = 7; $w_type = 4; $w_local = 5;
        foreach ($rows as $r) {
            $w_uid = max($w_uid, strlen($r['uid']));
            $w_created = max($w_created, strlen($r['created']));
            $w_type = max($w_type, strlen($r['type']));
        }
        printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %-{$w_local}s  %s\n", 'UID', 'CREATED', 'TYPE', 'LOCAL', 'MESSAGE');
        foreach ($rows as $r) {
            $m = $r['message'];
            if (strlen($m) > 120) { $m = substr($m, 0, 117) . '...'; }
            printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %-{$w_local}s  %s\n", $r['uid'], $r['created'], $r['type'], $r['local'], $m);
        }
        return 0;
    }
}

