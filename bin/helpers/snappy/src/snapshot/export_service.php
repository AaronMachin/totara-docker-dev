<?php
namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;

/**
 * Streaming snapshot export to deterministic tar(.gz) artifact.
 */
class export_service {
    private snapshot_manager $manager;
    private integrity_service $integrity;
    private int $chunkSize = 65536;

    public function __construct(snapshot_manager $manager) {
        $this->manager = $manager;
        $this->integrity = new integrity_service();
    }

    /**
     * Export snapshot uid (or prefix) to tar.gz.
     * @param array{out_dir?:string,stdout?:bool,no_gzip?:bool} $options
     * @return array{uid:string,artifact_path?:string,artifact_sha256:string,manifest_sha256:string,file_count:int}
     */
    public function export(string $uidOrPrefix, array $options = []): array {
        $uid = $this->manager->resolve_uid($uidOrPrefix,'local');
        if ($uid==='') { throw new ValidationException('Snapshot not uniquely resolved: '.$uidOrPrefix); }
        $snapDir = $this->manager->local_path($uid);
        $manifestPath = $snapDir.'/manifest-v2.json';
        if(!is_file($manifestPath)) { throw new ValidationException('manifest-v2.json missing for snapshot '.$uid); }
        $manifestRaw = @file_get_contents($manifestPath);
        if($manifestRaw===false) { throw new ValidationException('failed to read manifest'); }
        $manifestArr = @json_decode($manifestRaw,true);
        if(!is_array($manifestArr)) { throw new ValidationException('invalid manifest json'); }
        $manifestSha = $this->integrity->hashManifest($manifestArr);
        $manifestSize = strlen($manifestRaw);
        // Build file list from manifest entries (names) and recompute hashes.
        $payloadFiles = [];
        foreach (($manifestArr['files']??[]) as $entry) { $name=$entry['name']??''; if($name==='') continue; $payloadFiles[] = $name; }
        sort($payloadFiles, SORT_STRING);
        $filesMeta = [];
        $lines = [];
        $lines[] = 'MANIFEST manifest-v2.json '.$manifestSha.' '.$manifestSize;
        foreach ($payloadFiles as $fname) {
            $diskPath = $snapDir.'/'.$fname;
            if(!is_file($diskPath)) { throw new ValidationException('missing snapshot file '.$fname); }
            $size = filesize($diskPath) ?: 0;
            $hash = $this->integrity->hashFile($diskPath);
            $filesMeta[] = ['path'=>'files/'.$fname,'size_bytes'=>$size,'sha256'=>$hash];
            $lines[] = 'FILE files/'.$fname.' '.$hash.' '.$size;
        }
        $artifactSha = $this->integrity->artifactLinesHash($lines);
        $exportJson = [
            'schema_version'=>1,
            'source_uid'=>$uid,
            'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'manifest_sha256'=>$manifestSha,
            'files'=>$filesMeta,
            'artifact_sha256'=>$artifactSha,
        ];
        $exportJsonStr = json_encode($exportJson, JSON_PRETTY_PRINT) ?: '{}';
        // Determine output
        $stdout = !empty($options['stdout']);
        $noGzip = !empty($options['no_gzip']);
        $ext = $noGzip?'.tar':'.tar.gz';
        $outDir = rtrim($options['out_dir'] ?? $snapDir, '/');
        if(!$stdout) { if(!is_dir($outDir)) { @mkdir($outDir,0777,true); } if(!is_dir($outDir)) { throw new ValidationException('cannot create out dir'); } }
        $artifactPath = $stdout?null:($outDir.'/'.$uid.$ext);
        $tmpPath = $stdout?null:($artifactPath.'.tmp'.bin2hex(random_bytes(3)));
        $fh = null; $isGzip = !$noGzip;
        if($stdout) {
            $fh = $isGzip?gzopen('php://output','wb6'):fopen('php://output','wb');
        } else {
            $fh = $isGzip?gzopen($tmpPath,'wb6'):fopen($tmpPath,'wb');
        }
        if(!$fh) { throw new ValidationException('open output failed'); }
        try {
            // Write tar stream into $fh (possibly gzip wrapped)
            $write = function(string $data) use ($fh,$isGzip) { if($data==='') return; if($isGzip) { gzwrite($fh,$data); } else { fwrite($fh,$data); } };
            // Helper to write one entry from string or file path
            $this->writeTarEntry('manifest-v2.json',$manifestSize,function() use ($manifestRaw){ return $manifestRaw; },$write);
            $this->writeTarEntry('export.json',strlen($exportJsonStr),function() use ($exportJsonStr){ return $exportJsonStr; },$write);
            foreach ($payloadFiles as $fname) {
                $diskPath = $snapDir.'/'.$fname; $size = filesize($diskPath)?:0;
                $this->writeTarEntry('files/'.$fname,$size,function() use ($diskPath){ return @file_get_contents($diskPath); },$write,true,$diskPath);
            }
            // two zero blocks
            $write(str_repeat("\0",1024));
        } finally {
            if($isGzip) { @gzclose($fh); } else { @fclose($fh); }
        }
        if(!$stdout) {
            @rename($tmpPath,$artifactPath);
            if(!is_file($artifactPath)) { throw new ValidationException('failed to finalize artifact'); }
        }
        return [ 'uid'=>$uid,'artifact_path'=>$artifactPath,'artifact_sha256'=>$artifactSha,'manifest_sha256'=>$manifestSha,'file_count'=>count($payloadFiles) ];
    }

    /**
     * Write a tar entry.
     * @param callable():string $contentProvider (if streamingFile true, this ignored and we stream file in chunks)
     */
    private function writeTarEntry(string $name,int $size, callable $contentProvider, callable $write,bool $streamingFile=false, ?string $filePath=null): void {
        $header = $this->buildHeader($name,$size);
        $write($header);
        if($streamingFile && $filePath) {
            $in = @fopen($filePath,'rb'); if($in){ while(!feof($in)){ $buf=fread($in,$this->chunkSize); if($buf!==''&&$buf!==false){ $write($buf);} } fclose($in);} }
        else { $data = $contentProvider(); if(strlen($data)!==$size){ $data = substr($data,0,$size); } $write($data); }
        $pad = $size % 512; if($pad!==0){ $write(str_repeat("\0",512-$pad)); }
    }

    private function buildHeader(string $name,int $size): string {
        $nameBytes = substr($name,0,100);
        $mode = str_pad(decoct(0644),7,'0',STR_PAD_LEFT)."\0";
        $uid = str_pad('0',7,'0',STR_PAD_LEFT)."\0";
        $gid = str_pad('0',7,'0',STR_PAD_LEFT)."\0";
        $sizeOct = str_pad(decoct($size),11,'0',STR_PAD_LEFT)."\0";
        $mtime = str_pad(decoct(0),11,'0',STR_PAD_LEFT)."\0"; // stable
        $chksum = '        ';
        $typeflag = '0';
        $linkname = str_repeat("\0",100);
        $magic = 'ustar'; $magic = $magic."\0"; // 6 bytes (ustar\0)
        $version = '00';
        $uname = str_pad('root',32,"\0");
        $gname = str_pad('root',32,"\0");
        $devmajor = str_pad('',8,"\0");
        $devminor = str_pad('',8,"\0");
        $prefix = str_repeat("\0",155);
        $padding = str_repeat("\0",12);
        $header = str_pad($nameBytes,100,"\0").$mode.$uid.$gid.$sizeOct.$mtime.$chksum.$typeflag.$linkname.$magic.$version.$uname.$gname.$devmajor.$devminor.$prefix.$padding;
        // compute checksum
        $sum = 0; for($i=0;$i<512;$i++){ $sum += ord($header[$i]); }
        $chk = str_pad(decoct($sum),6,'0',STR_PAD_LEFT)."\0 ";
        $header = substr($header,0,148).$chk.substr($header,156);
        return $header;
    }
}

