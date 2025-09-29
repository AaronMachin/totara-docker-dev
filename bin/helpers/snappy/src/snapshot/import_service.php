<?php
namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;
use Snappy\Util\snapshot_uid;
use Throwable;

/**
 * Streaming snapshot import from tar(.gz) artifact produced by export_service.
 */
class import_service {
    private snapshot_manager $manager;
    private integrity_service $integrity;
    private int $chunkSize = 65536;

    public function __construct(snapshot_manager $manager) { $this->manager = $manager; $this->integrity = new integrity_service(); }

    /**
     * @param string $path Path to artifact or '-' for STDIN
     * @param array{uid_strategy?:string,register?:bool,out_dir?:string} $options
     * @return array{imported_uid:string,original_uid:string,strategy:string,artifact_sha256:string,manifest_sha256:string}
     */
    public function importArtifact(string $path, array $options = []): array {
        $uidStrategy = strtolower(trim($options['uid_strategy'] ?? $options['uid-strategy'] ?? 'keep'));
        if($uidStrategy==='k') $uidStrategy='keep'; if($uidStrategy==='n') $uidStrategy='new';
        if(!in_array($uidStrategy,['keep','new'],true)){ throw new ValidationException('invalid uid_strategy'); }
        $register = $options['register'] ?? true;
        $destBaseOverride = isset($options['out_dir']) && $options['out_dir'] !== '' ? rtrim($options['out_dir'],'/') : null;
        $isStdIn = $path === '-';
        $workingFile = $isStdIn ? $this->captureStdIn() : $path;
        if(!is_file($workingFile)) { throw new ValidationException('artifact not found'); }
        $gzip = $this->isGzip($workingFile);
        $state = $this->extractAndValidate($workingFile,$gzip);
        $originalUid = $state['manifest']['uid'] ?? '';
        if($originalUid==='') { throw new ValidationException('manifest missing uid'); }
        $importedUid = $originalUid;
        if($uidStrategy==='keep') {
            if(is_dir($this->manager->local_path($originalUid))) { $this->cleanup($state['temp_dir']); throw new ValidationException('snapshot uid already exists: '.$originalUid); }
        } else { // new
            do { $importedUid = snapshot_uid::generate(); } while (is_dir($this->manager->local_path($importedUid)));
            // rewrite manifest uid
            $state['manifest']['uid'] = $importedUid;
            $this->rewriteManifestUid($state['temp_dir'].'/manifest-v2.json',$importedUid);
        }
        // adjust final destination handling
        $baseDest = rtrim($this->manager->registry()->local_base_path(),'/').'/snaps';
        if(isset($destBaseOverride) && $destBaseOverride){ $baseDest = rtrim($destBaseOverride,'/'); }
        if(!is_dir($baseDest)) { @mkdir($baseDest,0777,true); }
        $finalDir = $baseDest.'/'.$importedUid;
        if(is_dir($finalDir)) { $this->cleanup($state['temp_dir']); throw new ValidationException('target directory exists'); }
        // atomic promotion
        if(!@rename($state['temp_dir'],$finalDir)) { $this->cleanup($state['temp_dir']); throw new ValidationException('failed to promote snapshot directory'); }
        if($uidStrategy==='new') { $this->writeProvenance($finalDir,[
            'original_uid'=>$originalUid,
            'original_manifest_sha256'=>$state['manifest_sha256'],
            'original_artifact_sha256'=>$state['artifact_sha256'],
            'imported_uid'=>$importedUid,
            'imported_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'strategy'=>'new'
        ]); }
        // best-effort index update (no failure bubbling)
        try { $ref = new \ReflectionObject($this->manager); if($ref->hasProperty('indexManager')){ $p=$ref->getProperty('indexManager'); $p->setAccessible(true); $idx=$p->getValue($this->manager); if($register && $idx){ $idx->addOrUpdate($importedUid); } } } catch(Throwable $e) {}
        return [ 'imported_uid'=>$importedUid,'original_uid'=>$originalUid,'strategy'=>$uidStrategy,'artifact_sha256'=>$state['artifact_sha256'],'manifest_sha256'=>$state['manifest_sha256'] ];
    }

    private function captureStdIn(): string { $tmp=sys_get_temp_dir().'/snappy_stdin_'.bin2hex(random_bytes(4)); $fh=fopen($tmp,'wb'); if(!$fh) throw new ValidationException('stdin temp open fail'); $in=fopen('php://stdin','rb'); if(!$in) throw new ValidationException('stdin not readable'); while(!feof($in)){ $buf=fread($in,$this->chunkSize); if($buf!==false && $buf!==''){ fwrite($fh,$buf); } } fclose($in); fclose($fh); return $tmp; }
    private function isGzip(string $file): bool { $fh=@fopen($file,'rb'); if(!$fh) return false; $b=@fread($fh,2); fclose($fh); return $b!==false && strlen($b)===2 && ord($b[0])===0x1f && ord($b[1])===0x8b; }

    /**
     * Extracts artifact into temp dir and validates hashes.
     * @return array{temp_dir:string,manifest:array,manifest_sha256:string,artifact_sha256:string}
     */
    private function extractAndValidate(string $file,bool $gzip): array {
        $tempDirBase = rtrim($this->manager->registry()->local_base_path(),'/').'/tmp';
        if(!is_dir($tempDirBase)) { @mkdir($tempDirBase,0777,true); }
        $tempDir = $tempDirBase.'/import_'.bin2hex(random_bytes(5)); @mkdir($tempDir,0777,true);
        $stream = $gzip? @gzopen($file,'rb') : @fopen($file,'rb'); if(!$stream){ $this->cleanup($tempDir); throw new ValidationException('open artifact failed'); }
        $close = function() use ($stream,$gzip){ if($gzip) @gzclose($stream); else @fclose($stream); };
        $entries = [];
        $filesHashes = []; // relative path => sha256
        $manifestRaw = null; $manifest = null; $exportRaw = null; $export = null;
        $manifestHeaderSize = 0; // size from header
        $manifestSeen=false; $exportSeen=false;
        try {
            while(true){
                $header = $this->readBlock($stream,$gzip); if($header===''){ $this->cleanup($tempDir); throw new ValidationException('truncated archive'); }
                if($this->isZeroBlock($header)) { // possible end (read next to confirm then break)
                    $peek = $this->readBlock($stream,$gzip); if($peek!=='' && !$this->isZeroBlock($peek)){ $this->cleanup($tempDir); throw new ValidationException('unexpected data after zero block'); } break; }
                $name = rtrim(strtok(substr($header,0,100),"\0"));
                if($name==='') { $this->cleanup($tempDir); throw new ValidationException('empty entry name'); }
                $outPath = $tempDir.'/'.$name; // compute early
                if(isset($entries[$name]) || is_file($outPath)) { $this->cleanup($tempDir); throw new ValidationException('duplicate entry '.$name); }
                $entries[$name]=true;
                $sizeOct = rtrim(substr($header,124,12),"\0 "); $size = octdec($sizeOct?:'0');
                $outDir=dirname($outPath); if(!is_dir($outDir)) @mkdir($outDir,0777,true);
                $remaining=$size; $hashCtx=hash_init('sha256'); $dataBuffer=''; $capture = ($name==='manifest-v2.json'||$name==='export.json'); $fhOut=@fopen($outPath,'wb'); if(!$fhOut){ $this->cleanup($tempDir); throw new ValidationException('write failure'); }
                while($remaining>0){ $toRead=min($remaining,$this->chunkSize); $chunk=$gzip?gzread($stream,$toRead):fread($stream,$toRead); if($chunk===false||$chunk===''){ $this->cleanup($tempDir); throw new ValidationException('truncated entry '.$name); } $remaining-=strlen($chunk); fwrite($fhOut,$chunk); hash_update($hashCtx,$chunk); if($capture){ $dataBuffer.=$chunk; } }
                fclose($fhOut);
                $fileHash = hash_final($hashCtx);
                if($name==='manifest-v2.json'){
                    if($manifestSeen){ $this->cleanup($tempDir); throw new ValidationException('duplicate entry manifest-v2.json'); }
                    $manifestSeen=true; $manifestRaw=$dataBuffer; $manifestHeaderSize=$size; $manifest=@json_decode($manifestRaw,true); if(!is_array($manifest)){ $this->cleanup($tempDir); throw new ValidationException('invalid manifest json'); }
                }
                elseif($name==='export.json'){
                    if($exportSeen){ $this->cleanup($tempDir); throw new ValidationException('duplicate entry export.json'); }
                    $exportSeen=true; $exportRaw=$dataBuffer; $export=@json_decode($exportRaw,true); if(!is_array($export)){ $this->cleanup($tempDir); throw new ValidationException('invalid export.json json'); }
                }
                elseif(str_starts_with($name,'files/')) { $filesHashes[substr($name,6)] = $fileHash; }
                // skip padding
                $pad = $size % 512; if($pad>0){ $skip = 512-$pad; $discard = $gzip?gzread($stream,$skip):fread($stream,$skip); if($discard===false || strlen($discard)!==$skip){ $this->cleanup($tempDir); throw new ValidationException('truncated padding'); } }
            }
        } finally { $close(); }
        if(!$manifest || !$export){ $this->cleanup($tempDir); throw new ValidationException('missing required entries'); }
        $manifestSha = $this->integrity->hashManifest($manifest); if($manifestSha !== ($export['manifest_sha256']??'')){ $this->cleanup($tempDir); throw new ValidationException('manifest hash mismatch'); }
        // Build lines using manifest file list
        $payloadNames = []; foreach(($manifest['files']??[]) as $f){ $n=$f['name']??''; if($n!==''){ $payloadNames[]=$n; } }
        sort($payloadNames,SORT_STRING);
        $lines=[]; $lines[]='MANIFEST manifest-v2.json '.$manifestSha.' '.$manifestHeaderSize;
        foreach($payloadNames as $n){ if(!isset($filesHashes[$n])){ $this->cleanup($tempDir); throw new ValidationException('missing file from artifact: '.$n); } $diskHash = $filesHashes[$n]; $size = filesize($tempDir.'/files/'.$n)?:0; $lines[]='FILE files/'.$n.' '.$diskHash.' '.$size; }
        $artifactSha = $this->integrity->artifactLinesHash($lines); if($artifactSha !== ($export['artifact_sha256']??'')){ $this->cleanup($tempDir); throw new ValidationException('artifact hash mismatch'); }
        // basic manifest sanity
        if(($export['source_uid']??'')!==$manifest['uid']) { /* acceptable when re-export? keep silent */ }
        return ['temp_dir'=>$tempDir,'manifest'=>$manifest,'manifest_sha256'=>$manifestSha,'artifact_sha256'=>$artifactSha];
    }

    private function rewriteManifestUid(string $path,string $newUid): void { $raw=@file_get_contents($path); if($raw===false) return; $data=@json_decode($raw,true); if(!is_array($data)) return; $data['uid']=$newUid; $tmp=$path.'.tmp'; file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT)); @rename($tmp,$path); }

    private function writeProvenance(string $dir,array $prov): void { $file=$dir.'/import_provenance.json'; $tmp=$file.'.tmp'; file_put_contents($tmp,json_encode($prov,JSON_PRETTY_PRINT)); @rename($tmp,$file); }

    private function readBlock($stream,bool $gzip): string { return $gzip? (string)gzread($stream,512) : (string)fread($stream,512); }
    private function isZeroBlock(string $blk): bool { if(strlen($blk)!==512) return false; return trim($blk,"\0")===''; }
    private function cleanup(string $dir): void { if(!is_dir($dir)) return; $it=@scandir($dir); if(!$it) { @rmdir($dir); return; } foreach($it as $e){ if($e==='.'||$e==='..') continue; $p=$dir.'/'.$e; if(is_dir($p)) { $this->cleanup($p); } else { @unlink($p); } } @rmdir($dir); }
}
