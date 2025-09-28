<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Throwable;

class doctor_run extends base_command {
    public function name(): string { return 'doctor.run'; }
    public function description(): string { return 'Run system health diagnostics (config, index, disk, remotes, integrity)'; }
    public function usage(): string { return 'Usage: tsnap doctor run'; }
    public function examples(): array { return ['tsnap doctor run','tsnap doctor run --json']; }

    public function run(array $args, context $ctx): int {
        $checks = [];
        $fail = false;
        // 1. Config parse & validation
        $cfgStatus = 'PASS'; $cfgDetail = 'ok';
        try { $v = $ctx->config->validate(); if ($v['errors']) { $cfgStatus='FAIL'; $cfgDetail = implode('; ', $v['errors']); } elseif ($v['warnings']) { $cfgStatus='WARN'; $cfgDetail = implode('; ', $v['warnings']); } }
        catch (Throwable $e) { $cfgStatus='FAIL'; $cfgDetail='exception: '.substr($e->getMessage(),0,120); }
        if ($cfgStatus==='FAIL') { $fail = true; }
        $checks[] = ['Config',$cfgStatus,$cfgDetail];

        // 2. Index parse (no auto rebuild). Missing -> WARN, Corrupt -> FAIL
        $indexPath = rtrim($ctx->registry->local_base_path(),'/').'/snaps/index.json';
        $idxStatus = 'PASS'; $idxDetail = 'ok';
        if (!is_file($indexPath)) { $idxStatus='WARN'; $idxDetail='missing'; }
        else {
            $raw = @file_get_contents($indexPath); $data = @json_decode((string)$raw,true);
            if (!is_array($data) || (($data['version']??null)!==1) || !isset($data['snapshots']) || !is_array($data['snapshots'])) {
                $idxStatus='FAIL'; $idxDetail='corrupt';
            }
        }
        if ($idxStatus==='FAIL') { $fail = true; }
        $checks[] = ['Index',$idxStatus,$idxDetail];

        // 3. Free disk space (>200MB)
        $diskStatus='PASS'; $diskDetail='';
        $threshold = 200*1024*1024; $base = $ctx->registry->local_base_path();
        $free = @disk_free_space($base); if ($free===false) { $diskStatus='WARN'; $diskDetail='unknown'; }
        else { $diskDetail = $this->humanBytes($free).' free'; if ($free < $threshold) { $diskStatus='FAIL'; $diskDetail .= ' (<200MB)'; } }
        if ($diskStatus==='FAIL') { $fail=true; }
        $checks[] = ['Disk',$diskStatus,$diskDetail];

        // 4. Remote connectivity (list first object). Skip local. Enforce 3s timeout.
        $remotes = $ctx->registry->names(); sort($remotes);
        foreach ($remotes as $r) {
            if ($r==='local') { continue; }
            $rStatus='PASS'; $rDetail='ok';
            $timedOut = false; $hadPcntl = function_exists('pcntl_signal') && function_exists('pcntl_alarm');
            if ($hadPcntl) {
                if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); }
                pcntl_signal(SIGALRM, function() use (&$timedOut) { $timedOut = true; });
                @pcntl_alarm(3);
            }
            try {
                $st = $ctx->registry->storage($r);
                if ($timedOut) { throw new \RuntimeException('timeout'); }
                $st->list_objects('snaps/',1);
                if ($timedOut) { throw new \RuntimeException('timeout'); }
            } catch (Throwable $e) {
                if ($timedOut) { $rStatus='FAIL'; $rDetail='timeout'; }
                else { $rStatus='FAIL'; $rDetail=substr($e->getMessage(),0,100); }
            } finally {
                if ($hadPcntl) { @pcntl_alarm(0); pcntl_signal(SIGALRM, SIG_DFL); }
            }
            if ($rStatus==='FAIL') { $fail=true; }
            $checks[] = ['Remote:'.$r,$rStatus,$rDetail];
        }

        // 5. Random snapshot integrity (checksum)
        $snapRows = $ctx->manager->list('local', false, 500);
        $intStatus='SKIP'; $intDetail='no snapshots';
        if ($snapRows) {
            $pick = $snapRows[random_int(0, count($snapRows)-1)]['uid'];
            try { $ctx->manager->verify_local($pick); $intStatus='PASS'; $intDetail='verified '.$pick; }
            catch (Throwable $e) { $intStatus='FAIL'; $intDetail='uid '.$pick.' '.$e->getMessage(); }
        }
        if ($intStatus==='FAIL') { $fail=true; }
        $checks[] = ['Integrity',$intStatus,$intDetail];

        // Output
        if (!$ctx->out->isJson()) {
            $ctx->out->info('System diagnostics:');
            $ctx->out->table(['Check','Status','Detail'],$checks);
            $summary = $this->summarize($checks);
            $ctx->out->info('Summary: '.json_encode($summary));
        } else {
            $ctx->out->json(['diagnostics'=>$this->formatDiagnostics($checks),'summary'=>$this->summarize($checks)]);
        }
        return $fail ? 2 : 0;
    }

    private function formatDiagnostics(array $rows): array {
        $out = [];
        foreach ($rows as $r) { $out[] = ['check'=>$r[0],'status'=>$r[1],'detail'=>$r[2]]; }
        return $out;
    }
    private function summarize(array $rows): array {
        $counts = ['PASS'=>0,'FAIL'=>0,'WARN'=>0,'SKIP'=>0];
        foreach ($rows as $r) { if (isset($counts[$r[1]])) { $counts[$r[1]]++; } }
        return $counts;
    }
    private function humanBytes(int $bytes): string {
        $units=['B','KB','MB','GB','TB']; $i=0; $val=$bytes; while ($val>=1024 && $i<count($units)-1) { $val/=1024; $i++; }
        return ($i===0? (string)$val : number_format($val,2)).' '.$units[$i];
    }
}
