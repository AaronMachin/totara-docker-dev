<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Snapshot\export_service;
use Snappy\Support\Exception\ValidationException;
use Throwable;

class snapshot_export extends base_command {
    public function name(): string { return 'snapshot.export'; }
    public function description(): string { return 'Export a snapshot to a deterministic tar.gz artifact'; }
    public function usage(): string { return 'Usage: tsnap snapshot export <uid|prefix> [--out-dir=DIR] [--stdout] [--no-gzip]\nCreates <uid>.tar.gz (or .tar with --no-gzip) containing manifest-v2.json, export.json, and files/*.'; }
    public function examples(): array { return ['tsnap snapshot export 8f3a1d2c --out-dir=/tmp','tsnap snapshot export 8f3 --stdout > snap.tar.gz']; }

    public function run(array $args, context $ctx): int {
        $uidOrPrefix = '';$outDir=null;$stdout=false;$noGzip=false;
        foreach ($args as $a) {
            if ($uidOrPrefix==='') { if(!str_starts_with($a,'--')) { $uidOrPrefix=$a; continue; } }
            if (str_starts_with($a,'--out-dir=')) { $outDir=substr($a,10); }
            elseif ($a==='--stdout') { $stdout=true; }
            elseif ($a==='--no-gzip') { $noGzip=true; }
        }
        if ($uidOrPrefix==='') { $ctx->out->error('uid or prefix required',64); return 64; }
        $service = new export_service($ctx->manager);
        try { $result = $service->export($uidOrPrefix,['out_dir'=>$outDir,'stdout'=>$stdout,'no_gzip'=>$noGzip]); }
        catch (ValidationException $ve) { $ctx->out->error($ve->getMessage(),2); return 2; }
        catch (Throwable $e) { $ctx->out->error('export failed: '.$e->getMessage(),1); return 1; }
        if(!$stdout){ $ctx->out->info('exported snapshot '.$result['uid'].' -> '.$result['artifact_path']); }
        $ctx->out->json($result);
        return 0;
    }
}

