<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;

class gc_objects extends base_command {
    public function name(): string { return 'gc.objects'; }
    public function description(): string { return 'Garbage collect unreferenced content-addressed objects (dry-run by default)'; }
    public function usage(): string { return "Usage: tsnap gc objects [--apply] [--limit=N]\nScans all manifest-v2.json files to determine referenced object hashes then lists or deletes unreferenced files under objects/sha256."; }
    public function examples(): array { return [ 'tsnap gc objects', 'tsnap gc objects --apply', 'tsnap gc objects --limit=20' ]; }

    public function metadata(): array { $m = parent::metadata(); $m['group'] = 'Maintenance'; return $m; }

    public function run(array $args, context $ctx): int {
        $apply = false; $limit = 50; // default show up to 50 orphan hashes in dry-run output
        foreach ($args as $a) {
            if ($a === '--apply') { $apply = true; }
            elseif (preg_match('~^--limit=(\d+)~',$a,$m)) { $limit = (int)$m[1]; }
        }
        if ($limit < 1) { $limit = 1; }
        $base = rtrim($ctx->registry->local_base_path(), '/');
        $snapsDir = $base . '/snaps';
        $objectsDir = $base . '/objects/sha256';
        $referenced = [];
        $manifestsScanned = 0; $manifestsSkipped = 0; $manifestsErrored = 0;
        if (is_dir($snapsDir)) {
            $entries = @scandir($snapsDir) ?: [];
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') { continue; }
                $manDir = $snapsDir . '/' . $e;
                if (!is_dir($manDir)) { continue; }
                $man = $manDir . '/manifest-v2.json';
                if (!is_file($man)) { $manifestsSkipped++; continue; }
                $rawJson = @file_get_contents($man);
                if ($rawJson === false || $rawJson === '') { $manifestsErrored++; continue; }
                $raw = @json_decode($rawJson, true);
                if (!is_array($raw)) { $manifestsErrored++; continue; }
                $files = $raw['files'] ?? [];
                if (!is_array($files)) { $manifestsSkipped++; continue; }
                $manifestsScanned++;
                foreach ($files as $f) {
                    if (is_array($f) && isset($f['object_hash']) && is_string($f['object_hash']) && $f['object_hash'] !== '') {
                        $referenced[$f['object_hash']] = true;
                    }
                }
            }
        }
        $referencedCount = count($referenced);
        $candidates = [];
        $totalObjects = 0; $totalBytes = 0; $reclaimBytes = 0;
        if (is_dir($objectsDir)) {
            $prefixDirs = @scandir($objectsDir) ?: [];
            foreach ($prefixDirs as $pfx) {
                if ($pfx === '.' || $pfx === '..') { continue; }
                $pDir = $objectsDir . '/' . $pfx;
                if (!is_dir($pDir)) { continue; }
                $files = @scandir($pDir) ?: [];
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') { continue; }
                    $full = $pDir . '/' . $file;
                    if (!is_file($full)) { continue; }
                    // basic validation: hex 64
                    if (!preg_match('/^[a-f0-9]{64}$/', $file)) { continue; }
                    $totalObjects++;
                    $size = filesize($full) ?: 0; $totalBytes += $size;
                    if (!isset($referenced[$file])) { $candidates[] = [ 'hash'=>$file, 'path'=>$full, 'size'=>$size ]; $reclaimBytes += $size; }
                }
            }
        } else {
            if (!$apply) { $ctx->out->info('No objects directory; nothing to collect'); }
            $ctx->out->json([
                'action'=>'gc.objects','dry_run'=>!$apply,'referenced'=>0,'total_objects'=>0,'orphans'=>0,'removed'=>0,
                'bytes_total'=>0,'bytes_reclaimable'=>0,'manifests_scanned'=>0,'manifests_skipped'=>0,'manifests_error'=>0
            ]);
            return 0;
        }
        usort($candidates, fn($a,$b)=>$b['size'] <=> $a['size']);
        if (!$candidates) {
            $ctx->out->info('No orphan objects found (referenced='.$referencedCount.', total='.$totalObjects.')');
            $ctx->out->json([
                'action'=>'gc.objects','dry_run'=>!$apply,'referenced'=>$referencedCount,'total_objects'=>$totalObjects,
                'orphans'=>0,'removed'=>0,'bytes_total'=>$totalBytes,'bytes_reclaimable'=>0,
                'manifests_scanned'=>$manifestsScanned,'manifests_skipped'=>$manifestsSkipped,'manifests_error'=>$manifestsErrored
            ]);
            return 0;
        }
        if (!$apply) {
            $ctx->out->info('Dry run: would delete '.count($candidates).' object(s); showing up to '.$limit.' by size desc:');
            $show = array_slice($candidates, 0, $limit);
            foreach ($show as $c) { $ctx->out->info('  '.$c['hash'].' ('.$c['size'].' bytes)'); }
            $ctx->out->info('Run with --apply to perform deletion. Avoid concurrent snapshot creation during GC.');
            $ctx->out->json([
                'action'=>'gc.objects','dry_run'=>true,'referenced'=>$referencedCount,'total_objects'=>$totalObjects,
                'orphans'=>count($candidates),'candidate_hashes'=>array_map(fn($c)=>$c['hash'], $show),
                'bytes_total'=>$totalBytes,'bytes_reclaimable'=>$reclaimBytes,
                'manifests_scanned'=>$manifestsScanned,'manifests_skipped'=>$manifestsSkipped,'manifests_error'=>$manifestsErrored
            ]);
            return 0;
        }
        // Apply deletions
        $ctx->out->info('Applying GC of '.count($candidates).' orphan object(s). Avoid concurrent snapshot creation during GC.');
        $removed = 0; $errors = 0; $error_hashes = [];
        foreach ($candidates as $c) {
            $ok = @unlink($c['path']);
            if ($ok || !is_file($c['path'])) { $removed++; }
            else { $errors++; $error_hashes[] = $c['hash']; }
        }
        $ctx->out->info('Removed '.$removed.' orphan object(s); reclaimed '.$reclaimBytes.' bytes.');
        if ($errors) { $ctx->out->info($errors.' deletion error(s).'); }
        $ctx->out->json([
            'action'=>'gc.objects','dry_run'=>false,'referenced'=>$referencedCount,'total_objects'=>$totalObjects,
            'orphans'=>count($candidates),'removed'=>$removed,'errors'=>$errors,'error_hashes'=>$error_hashes,
            'bytes_total'=>$totalBytes,'bytes_reclaimable'=>$reclaimBytes,'bytes_reclaimed'=>$reclaimBytes,
            'manifests_scanned'=>$manifestsScanned,'manifests_skipped'=>$manifestsSkipped,'manifests_error'=>$manifestsErrored
        ]);
        return $errors ? 2 : 0;
    }
}
