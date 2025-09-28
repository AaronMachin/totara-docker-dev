<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class prune_run extends base_command {
    public function name(): string { return 'prune.run'; }
    public function description(): string { return 'Prune local snapshots by keeping the most recent N'; }
    public function usage(): string { return 'Usage: tsnap prune run [--keep=N]\nRemoves oldest local snapshots retaining newest N (default 20).'; }

    public function run(array $args, context $ctx): int {
        $keep = 20; foreach ($args as $a) { if (preg_match('~^--keep=(\d+)~',$a,$m)) { $keep = max(0,(int)$m[1]); } }
        $rows = $ctx->manager->list('local', false, 100000, true); // bypass index to get all
        usort($rows, fn($a,$b)=>strcmp($b['created'],$a['created']));
        $count = count($rows);
        if ($count <= $keep) { echo "Nothing to prune (have $count, keep=$keep)\n"; return 0; }
        $toDelete = array_slice($rows, $keep);
        $base = $ctx->registry->local_base_path();
        $deleted = 0; $errors = 0;
        foreach ($toDelete as $r) {
            $dir = $base.'/snaps/'.$r['uid'];
            if (is_dir($dir)) { $this->recursiveDelete($dir) ? $deleted++ : $errors++; }
        }
        echo "Pruned $deleted snapshot(s); kept $keep.".($errors?" ($errors errors)":"")."\n";
        return $errors ? 2 : 0;
    }

    private function recursiveDelete(string $dir): bool {
        $items = @scandir($dir); if(!$items) return false; foreach($items as $it){ if($it==='.'||$it==='..') continue; $p = $dir.'/'.$it; if(is_dir($p)) $this->recursiveDelete($p); else @unlink($p);} return @rmdir($dir);
    }
}

