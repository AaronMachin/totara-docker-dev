<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\command;
use Snappy\Cli\context;
use Snappy\Util\color;
use Snappy\Snapshot\remote_snapshot_cache;

class listing implements command {
    public function name(): string {
        return 'list';
    }

    public function description(): string {
        return 'List snapshots';
    }

    public function run(array $args, context $ctx): int {
        $full = false;
        $limit = 100;
        $remote_csv = null;
        $since = null;
        $before = null;
        $parallel = true;
        $live = false; // new flag
        foreach ($args as $arg) {
            if ($arg === '--full' || $arg === '--full-message') {
                $full = true;
            } elseif (str_starts_with($arg, '--limit=')) {
                $limit = (int) substr($arg, 8);
            } elseif (str_starts_with($arg, '--remote=')) {
                $remote_csv = substr($arg, 9);
            } elseif (str_starts_with($arg, '--since=')) {
                $since = $this->parse_time(substr($arg, 8));
            } elseif (str_starts_with($arg, '--before=')) {
                $before = $this->parse_time(substr($arg, 9));
            } elseif ($arg === '--no-parallel') {
                $parallel = false;
            } elseif ($arg === '--live') {
                $live = true;
            } else {
                fwrite(STDERR, "unknown option: $arg\n");
                return 1;
            }
        }
        $all_remotes = $ctx->registry->names();
        $selected = $all_remotes;
        if ($remote_csv !== null) {
            if ($remote_csv === '') {
                $selected = $all_remotes;
            } else {
                $parts = array_filter(array_map('trim', explode(',', $remote_csv)), 'strlen');
                $selected = [];
                foreach ($parts as $p) {
                    if (!$ctx->registry->has($p)) {
                        fwrite(STDERR, "unknown remote/store: $p\n");
                        return 2;
                    }
                    $selected[] = $p;
                }
                $selected = array_values(array_unique($selected));
            }
        }
        usort($selected, function ($a, $b) {
            if ($a === 'local' && $b !== 'local') {
                return -1;
            }
            if ($b === 'local' && $a !== 'local') {
                return 1;
            }
            return strcmp($a, $b);
        });
        $cacheInfo = [];
        $staleRemotes = [];
        foreach ($selected as $r) {
            if ($r === 'local') { continue; }
            if ($live) {
                $cacheInfo[$r] = 'live';
                continue;
            }
            $c = $ctx->cache->load($r);
            if ($c) {
                $ageIso = $c['fetched_at'];
                $ageHuman = remote_snapshot_cache::human_age($ageIso);
                $isStale = remote_snapshot_cache::is_stale($ageIso, $ctx->registry);
                if ($isStale) { $staleRemotes[] = $r; }
                // Color stale in bold red
                if ($isStale && \Snappy\Util\color::enabled()) {
                    $ageHuman = "\033[1;31m" . $ageHuman . "\033[0m";
                }
                $cacheInfo[$r] = $ageHuman;
            } else {
                $cacheInfo[$r] = 'no-cache';
            }
        }
        $rows = [];
        foreach ($selected as $remote) {
            if ($remote === 'local') {
                $localRows = $ctx->manager->list('local', $full, $limit);
                foreach ($localRows as $rrow) {
                    $rows[$rrow['uid']] = $rows[$rrow['uid']] ?? $rrow;
                    $rows[$rrow['uid']]['locations'] = isset($rows[$rrow['uid']]['locations']) ? $rows[$rrow['uid']]['locations'] . ', local' : 'local';
                }
                continue;
            }
            if ($live) {
                $liveRows = $ctx->manager->list($remote, $full, $limit, true); // bypass cache
                foreach ($liveRows as $rrow) {
                    $uid = $rrow['uid'];
                    $rows[$uid] = $rows[$uid] ?? $rrow;
                    $rows[$uid]['locations'] = isset($rows[$uid]['locations']) ? $rows[$uid]['locations'] . ', ' . $remote : $remote;
                }
                continue;
            }
            $c = $ctx->cache->load($remote);
            if (!$c) { continue; }
            $snapshots = $c['snapshots'];
            $count = 0;
            foreach ($snapshots as $uid => $row) {
                if ($count >= $limit) break;
                $msg = (string)($row['message'] ?? '');
                if (!$full) { $msg = preg_split('/\r?\n/', $msg, 2)[0] ?? ''; } else { $msg = preg_replace('/\r?\n+/', ' | ', $msg); }
                if (!isset($rows[$uid])) {
                    $rows[$uid] = [
                        'uid' => $uid,
                        'created' => $row['created'] ?? '',
                        'type' => $row['type'] ?? '',
                        'message' => $msg,
                        'locations' => $remote,
                    ];
                } else {
                    $locs = explode(',', $rows[$uid]['locations']);
                    if (!in_array($remote, array_map('trim',$locs), true)) {
                        $rows[$uid]['locations'] .= ', ' . $remote;
                    }
                }
                $count++;
            }
        }
        $rows = array_values($rows);
        // Apply time filters if specified
        if ($since || $before) {
            $rows = array_filter($rows, function($r) use ($since,$before){
                $created = strtotime($r['created'] ?? '') ?: 0;
                if ($since && $created < $since) return false;
                if ($before && $created > $before) return false;
                return true;
            });
            $rows = array_values($rows);
        }
        usort($rows, fn($a,$b)=>strcmp($b['created'],$a['created']));
        if (count($rows) > $limit) { $rows = array_slice($rows,0,$limit); }

        $sourceLine = ($live?"Snapshots (live mode; bypassing cache)":"Snapshots (cached remotes: " . implode(', ', array_map(function($r) use ($cacheInfo){
            return $r . '[' . ($cacheInfo[$r] ?? 'n/a') . ']';
        }, array_filter($selected, fn($r)=>$r!=='local'))) . ")");
        echo $sourceLine . "\n";
        if (!$live) {
            if ($staleRemotes) {
                echo "Stale caches: " . implode(', ', $staleRemotes) . ". Refresh with: tsnap fetch --stale-only --remote=" . implode(',', $staleRemotes) . "\n";
            }
        }
        if ($live) {
            echo "(Use without --live to use cached metadata; adjust max age via config option cache_max_age_seconds)\n";
        }
        if (!$rows) { echo "(none)\n"; return 0; }
        $w_uid=3;$w_created=7;$w_type=4;$w_locations=9;foreach($rows as $r){$w_uid=max($w_uid,strlen($r['uid']));$w_created=max($w_created,strlen($r['created']));$w_type=max($w_type,strlen($r['type']));$w_locations=max($w_locations,strlen($r['locations']));}
        printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %-{$w_locations}s  %s\n", 'UID','CREATED','TYPE','LOCATIONS','MESSAGE');
        foreach($rows as $r){$m=$r['message'];if(!$full && strlen($m)>120){$m=substr($m,0,117).'...';}$coloredLoc=$this->color_and_pad_locations($r['locations'],$w_locations);printf("%-{$w_uid}s  %-{$w_created}s  %-{$w_type}s  %s  %s\n",$r['uid'],$r['created'],$r['type'],$coloredLoc,$m);}return 0;
    }

    private function color_and_pad_locations(string $raw, int $width): string {
        $parts = array_map('trim', explode(',', $raw));
        $coloredParts = array_map(fn($n) => color::remote($n), $parts);
        $colored = implode(', ', $coloredParts);
        $printableLen = strlen(implode(', ', $parts));
        if ($printableLen < $width) {
            $colored .= str_repeat(' ', $width - $printableLen);
        }
        return $colored;
    }

    private function parse_time(string $expr): ?int {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        if (preg_match('/^(\d+)([smhd])$/i', $expr, $m)) {
            $n = (int) $m[1];
            $u = strtolower($m[2]);
            $sec = 0;
            switch ($u) {
                case 's':
                    $sec = $n;
                    break;
                case 'm':
                    $sec = $n * 60;
                    break;
                case 'h':
                    $sec = $n * 3600;
                    break;
                case 'd':
                    $sec = $n * 86400;
                    break;
            }
            return time() - $sec;
        }
        $ts = strtotime($expr);
        return $ts ?: null;
    }
}
