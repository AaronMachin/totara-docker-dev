<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class snapshot_list extends base_command {
    public function name(): string { return 'snapshot.list'; }
    public function description(): string { return 'List snapshots (local or remote)'; }
    public function usage(): string { return 'Usage: tsnap snapshot list [--remote=NAME] [--full] [--limit=N] [--live] [--no-index] [--filter="expr"]\nLists snapshots; defaults to local.'; }
    public function examples(): array { return [
        'tsnap snapshot list --limit=20',
        'tsnap snapshot list --remote=prod --full',
        'tsnap snapshot list --filter="tag=alpha age<10m"',
    ]; }

    public function run(array $args, context $ctx): int {
        $opts = $this->parseArgsLocal($args);
        if ($opts['filter'] !== null && str_starts_with($opts['filter'], '"') && !str_ends_with($opts['filter'], '"')) {
            $filterIdx = null;
            for ($i=0;$i<count($args);$i++) { if (str_starts_with($args[$i],'--filter=')) { $filterIdx = $i; break; } }
            if ($filterIdx !== null) {
                $rebuild = $opts['filter'];
                for ($j=$filterIdx+1;$j<count($args);$j++) {
                    $rebuild .= ' ' . $args[$j];
                    if (str_ends_with($args[$j], '"')) { break; }
                }
                $opts['filter'] = $rebuild;
            }
        }
        if ($opts['filter'] !== null) {
            $f = $opts['filter'];
            if (str_starts_with($f,'"') && str_ends_with($f,'"') && strlen($f) >= 2) { $opts['filter'] = substr($f,1,-1); }
        }
        $remote = $opts['remote'] ?? 'local';
        if (!$ctx->registry->has($remote)) { $ctx->out->error("unknown remote: $remote", 2); return 2; }
        $filterExpr = $opts['filter'];
        $conditions = [];
        if ($filterExpr !== null && $filterExpr !== '') {
            try { $parser = new \Snappy\Snapshot\filter_parser(); $conditions = $parser->parse($filterExpr); }
            catch (\Snappy\Support\Exception\ValidationException $ve) { $ctx->out->info($ve->getMessage()); $ctx->out->error($ve->getMessage(), 2); return 2; }
        }
        $rows = $ctx->manager->list($remote, $opts['full'], $opts['limit']*10, $opts['live'] || ($remote==='local' && $opts['no_index']));
        // If using local index fast path we can enrich tags via direct index load (cheaper) else enrich on demand when tag condition present.
        if ($remote==='local' && $filterExpr && !$opts['live'] && !$opts['no_index']) {
            $idx = $ctx->index->load();
            if ($idx) {
                $map = [];
                foreach ($idx['snapshots'] as $row) { $map[$row['uid']] = $row; }
                foreach ($rows as &$r) {
                    if (isset($map[$r['uid']])) { $r['tags'] = $map[$r['uid']]['tags'] ?? []; $r['type'] = $map[$r['uid']]['type'] ?? ($r['type']??''); $r['created'] = $map[$r['uid']]['created_utc'] ?? ($r['created']??''); }
                }
                unset($r);
            }
        } elseif ($filterExpr && $remote==='local') {
            // Enrich only if tag filter present
            $needsTags = false; foreach ($conditions as $c){ if($c['field']==='tag'){ $needsTags=true; break; } }
            if ($needsTags) {
                foreach ($rows as &$r) { $m = $ctx->manager->read_manifest('local',$r['uid']); $r['tags'] = $m['tags'] ?? []; $r['type'] = $m['type'] ?? ($r['type']??''); $r['created'] = $m['created'] ?? ($r['created']??''); }
                unset($r);
            }
        }
        if ($conditions) {
            $parser ??= new \Snappy\Snapshot\filter_parser();
            $rows = array_values(array_filter($rows, fn($r)=>$parser->match($r,$conditions)));
        }
        if (!$rows) {
            $ctx->out->info('(none)');
            $ctx->out->json(['snapshots'=>[],'filter'=>$filterExpr]);
            return 0;
        }
        // Apply limit after filtering
        if (count($rows) > $opts['limit']) { $rows = array_slice($rows,0,$opts['limit']); }
        $full = $opts['full'];
        $headers = ['UID','CREATED','TYPE','MESSAGE'];
        $tableRows = [];
        foreach ($rows as $r) { $m=$r['message']; if(!$full && strlen($m)>120){$m=substr($m,0,117).'...';} $tableRows[] = [$r['uid'],$r['created'],$r['type'],$m]; }
        $ctx->out->table($headers, $tableRows);
        $ctx->out->json(['snapshots'=>$rows,'remote'=>$remote,'full'=>$full,'limit'=>$opts['limit'],'filter'=>$filterExpr]);
        return 0;
    }

    private function parseArgsLocal(array $argv): array {
        $out = ['remote'=>null,'full'=>false,'limit'=>100,'live'=>false,'no_index'=>false,'filter'=>null];
        foreach ($argv as $idx=>$a) {
            if ($a==='--full') { $out['full']=true; }
            elseif ($a==='--live') { $out['live']=true; }
            elseif ($a==='--no-index') { $out['no_index']=true; }
            elseif (str_starts_with($a,'--remote=')) { $out['remote']=substr($a,9); }
            elseif (str_starts_with($a,'--limit=')) { $out['limit']=max(1,(int)substr($a,8)); }
            elseif (str_starts_with($a,'--filter=')) { $out['filter']=substr($a,9); }
        }
        if ($out['remote']===null) { $out['remote']='local'; }
        return $out;
    }
}
