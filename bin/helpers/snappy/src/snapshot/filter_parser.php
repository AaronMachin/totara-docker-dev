<?php
namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;

class filter_parser {
    /** Parse filter string into array of conditions. Throws ValidationException on invalid syntax. */
    public function parse(string $filter): array {
        $filter = trim($filter);
        if ($filter === '') { return []; }
        $tokens = preg_split('/\s+/', $filter) ?: [];
        $conds = [];
        foreach ($tokens as $tok) {
            if ($tok === '') { continue; }
            if (preg_match('/^tag=([a-z0-9][a-z0-9_\-]{0,63})$/', $tok, $m)) { $conds[] = ['field'=>'tag','op'=>'=','value'=>$m[1]]; continue; }
            if (preg_match('/^type=([a-z0-9][a-z0-9_\-]{0,31})$/', $tok, $m)) { $conds[] = ['field'=>'type','op'=>'=','value'=>$m[1]]; continue; }
            if (preg_match('/^uid=([a-f0-9]{4,})$/', $tok, $m)) { $conds[] = ['field'=>'uid','op'=>'=','value'=>$m[1]]; continue; }
            if (preg_match('/^age([<>])(\d+)([smhd]?)$/', $tok, $m)) {
                $op = $m[1]; $num=(int)$m[2]; $suf=$m[3];
                $mult = match($suf){ 'm'=>60, 'h'=>3600, 'd'=>86400, default=>1 };
                $conds[] = ['field'=>'age','op'=>$op,'value'=>$num*$mult]; continue;
            }
            throw new ValidationException('invalid filter token: '.$tok);
        }
        return $conds;
    }

    /** Evaluate AND conditions */
    public function match(array $row, array $conditions): bool {
        if (!$conditions) { return true; }
        $now = time();
        foreach ($conditions as $c) {
            $f = $c['field']; $op = $c['op']; $val=$c['value'];
            switch ($f) {
                case 'tag':
                    $tags = $row['tags'] ?? []; if (!in_array($val, $tags, true)) { return false; } break;
                case 'type':
                    if (($row['type'] ?? '') !== $val) { return false; } break;
                case 'uid':
                    if (!str_starts_with($row['uid'] ?? '', $val)) { return false; } break;
                case 'age':
                    $created = $row['created'] ?? ''; $ts = strtotime($created); if ($ts === false) { return false; }
                    $age = $now - $ts; if ($age < 0) { $age = 0; }
                    if ($op === '<') { if (!($age < $val)) { return false; } }
                    else { if (!($age > $val)) { return false; } }
                    break;
            }
        }
        return true;
    }
}

