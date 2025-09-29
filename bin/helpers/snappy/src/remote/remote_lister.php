<?php
namespace Snappy\Remote;

use Snappy\Snapshot\remote_registry;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\RemoteException;
use Throwable;

/** Lightweight remote snapshot lister (manifest first, meta fallback) */
class remote_lister {
    /**
     * Enumerate remote snapshots.
     * @return array{rows:array<int,array<string,mixed>>, errors:int, candidates:int}
     */
    public function enumerate(remote_registry $registry, string $remote, int $limit, bool $full): array {
        if(!$registry->has($remote)) { throw new ValidationException('unknown remote'); }
        if($remote==='local') { throw new ValidationException('remote mode requires non-local remote'); }
        $storage = $registry->storage($remote);
        $overscan = max(20 * $limit, $limit); // rough cap
        $objects = [];
        try { $objects = $storage->list_objects('snaps/', $overscan); } catch(Throwable $e) { throw new ValidationException('remote list failed: '.$e->getMessage()); }
        $uids = [];
        foreach ($objects as $o) {
            $key = $o['key'] ?? '';
            if(!str_starts_with($key,'snaps/')) continue;
            $rest = substr($key,6);
            $pos = strpos($rest,'/'); if($pos===false) continue;
            $uid = substr($rest,0,$pos);
            if(!preg_match('/^[a-z0-9][a-z0-9\-]{7,79}$/i',$uid)) continue;
            if(!in_array($uid,$uids,true)) { $uids[]=$uid; }
            if(count($uids) >= $limit*3) { /* arbitrary early break to avoid huge scans */ }
        }
        // Sort uids to provide deterministic order before metadata fetch (we will resort by created later)
        sort($uids, SORT_STRING|SORT_FLAG_CASE);
        $rows = []; $errors = 0;
        foreach ($uids as $uid) {
            if(count($rows) >= $limit) { break; }
            $meta = null; $manifest = null; $raw = null; $source='';
            try {
                try { $raw = $storage->read_object('snaps/'.$uid.'/manifest-v2.json'); $manifest = @json_decode($raw,true); if(is_array($manifest)) { $source='manifest'; } else { $manifest=null; } } catch(Throwable $e) { /* continue to meta */ }
                if(!$manifest) {
                    try { $raw = $storage->read_object('snaps/'.$uid.'/meta.json'); $meta = @json_decode($raw,true); if(is_array($meta)) { $source='meta'; } } catch(Throwable $e) { /* missing */ }
                }
                if(!$manifest && !$meta) { $errors++; continue; }
                $created = '';
                $type = '';
                $message = '';
                $size = null;
                if($manifest) {
                    $created = (string)($manifest['created_utc'] ?? ($manifest['created'] ?? ''));
                    $type = (string)($manifest['snapshot_type'] ?? ($manifest['type'] ?? ''));
                    $message = (string)($manifest['message'] ?? '');
                    if(isset($manifest['size_total_bytes'])) { $size = (int)$manifest['size_total_bytes']; }
                } else {
                    $created = (string)($meta['created_utc'] ?? ($meta['created'] ?? ''));
                    $type = (string)($meta['snapshot_type'] ?? ($meta['type'] ?? ''));
                    $message = (string)($meta['message'] ?? '');
                }
                // Normalize literal backslash-n sequences to real newlines for consistency
                if(str_contains($message,'\\n')) { $message = str_replace('\\n', "\n", $message); }
                if(!$full) { $message = preg_split('/\r?\n/', $message, 2)[0] ?? ''; }
                else { $message = trim(str_replace("\n",' | ',$message)); }
                if($size===null && $source==='meta') { // attempt crude size sum
                    try { $list = $storage->list_objects('snaps/'.$uid.'/', 500); $total=0; foreach($list as $obj){ $k=$obj['key']??''; if($k===''||str_ends_with($k,'/')) continue; if(str_ends_with($k,'manifest-v2.json')||str_ends_with($k,'meta.json')) continue; $total += (int)($obj['size']??0); } if($total>0){ $size=$total; } } catch(Throwable $e) { /* ignore */ }
                }
                $rows[] = ['uid'=>$uid,'created'=>$created,'type'=>$type,'message'=>$message] + ($size!==null?['size_bytes'=>$size]:[]);
            } catch(RemoteException $re) { $errors++; continue; }
            catch(Throwable $t) { $errors++; continue; }
        }
        // sort rows by created desc
        usort($rows, fn($a,$b)=>strcmp($b['created']??'', $a['created']??''));
        if(count($rows) > $limit) { $rows = array_slice($rows,0,$limit); }
        return ['rows'=>$rows,'errors'=>$errors,'candidates'=>count($uids)];
    }
}
