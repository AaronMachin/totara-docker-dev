<?php
namespace Snappy\Remote;

use Snappy\Snapshot\remote_registry;
use Snappy\Storage\storage;
use Snappy\Snapshot\integrity_service;
use Snappy\Support\Exception\ValidationException;
use Snappy\Util\snapshot_uid;
use Throwable;

/** Remote snapshot pull (download) service */
class remote_pull_service {
    private remote_registry $registry; private integrity_service $integrity; private int $chunkSize = 65536;
    public function __construct(remote_registry $registry){ $this->registry=$registry; $this->integrity=new integrity_service(); }

    /**
     * @param array{uid_strategy?:string,uid-strategy?:string,force?:bool,progress?:bool,out_dir?:string} $options
     * @return array{success:bool,original_uid:string,final_uid:string,files:int,bytes:int,verified:bool,provenance_path?:string}
     */
    public function pull(string $remoteName, string $uidOrPrefix, array $options=[]): array {
        $uidOrPrefix = trim($uidOrPrefix); if($uidOrPrefix===''){ throw new ValidationException('uid or prefix required'); }
        if(!$this->registry->has($remoteName)) { throw new ValidationException('unknown remote'); }
        $storage = $this->registry->storage($remoteName);
        $resolvedUid = $this->resolveUid($storage,$uidOrPrefix);
        if($resolvedUid===''){ throw new ValidationException('snapshot not found for prefix'); }
        $uidStrategy = strtolower(trim($options['uid_strategy'] ?? $options['uid-strategy'] ?? 'keep'));
        if($uidStrategy==='k') $uidStrategy='keep'; if($uidStrategy==='n') $uidStrategy='new';
        if(!in_array($uidStrategy,['keep','new'],true)) { throw new ValidationException('invalid uid_strategy'); }
        $force = (bool)($options['force'] ?? false);
        $progress = (bool)($options['progress'] ?? false);
        $outDirOverride = isset($options['out_dir']) && $options['out_dir']!=='' ? rtrim($options['out_dir'],'/') : null;
        $baseLocal = $outDirOverride ?: (rtrim($this->registry->local_base_path(),'/').'/snaps');
        if(!is_dir($baseLocal)) { @mkdir($baseLocal,0777,true); }
        $tempBase = rtrim($this->registry->local_base_path(),'/').'/tmp'; if(!is_dir($tempBase)) { @mkdir($tempBase,0777,true); }
        $tempDir = $tempBase.'/pull_'.bin2hex(random_bytes(5)); @mkdir($tempDir,0777,true);
        $cleanup = function() use ($tempDir){ $this->recursive_delete($tempDir); };
        $manifest = null; $manifestRaw = null; $meta = null; $hasManifest=false; $expectedChecksums=[]; $filesList=[]; $verified=true; $originalManifestSha='';
        try {
            // Try manifest-v2.json first
            try { $manifestRaw = $storage->read_object('snaps/'.$resolvedUid.'/manifest-v2.json'); $manifest=@json_decode($manifestRaw,true); if(is_array($manifest)) { $hasManifest=true; $expectedChecksums = (array)($manifest['checksums']['files'] ?? []); $originalManifestSha = $this->integrity->hashManifest($manifest); } } catch(Throwable $e) { /* ignore missing */ }
            if(!$hasManifest){ // try meta.json
                try { $metaRaw = $storage->read_object('snaps/'.$resolvedUid.'/meta.json'); $meta=@json_decode($metaRaw,true); } catch(Throwable $e) { /* ignore */ }
            }
            if(!$hasManifest && !$meta){ throw new ValidationException('remote snapshot missing manifest and meta'); }
            if($hasManifest){
                // derive file list from manifest files entries (top-level names)
                foreach(($manifest['files']??[]) as $f){ $name=$f['name']??''; if($name!==''){ $filesList[]=$name; } }
            } else { // meta only, need to list objects under snaps/uid/
                $objects = $storage->list_objects('snaps/'.$resolvedUid.'/',1000);
                foreach($objects as $obj){ $key=$obj['key']??''; if(str_ends_with($key,'/')) continue; $prefix='snaps/'.$resolvedUid.'/'; if(!str_starts_with($key,$prefix)) continue; $name=substr($key,strlen($prefix)); if($name==='manifest-v2.json'||$name==='meta.json'||$name==='') continue; if(str_contains($name,'/')) continue; $filesList[]=$name; }
            }
            sort($filesList,SORT_STRING);
            if(!$filesList) { throw new ValidationException('no files to download'); }
            // Download files
            $totalBytes=0; $fileCount=0; $downloadedChecksums=[];
            foreach($filesList as $name){
                $remoteKey='snaps/'.$resolvedUid.'/'.$name; $dest=$tempDir.'/'.$name; $dir=dirname($dest); if(!is_dir($dir)) { @mkdir($dir,0777,true); }
                $storage->get_object($remoteKey,$dest);
                $hash=$this->integrity->hashFile($dest); $downloadedChecksums[$name]=$hash; $size=filesize($dest)?:0; $totalBytes+=$size; $fileCount++;
                if($progress){ fwrite(STDERR,'download '.$name.' '.$size."\n"); }
                if($hasManifest){
                    $exp = $expectedChecksums[$name] ?? null; if($exp===null){ $verified=false; throw new ValidationException('unexpected file '.$name); }
                    if(strtolower($exp)!==strtolower($hash)){ $verified=false; throw new ValidationException('checksum mismatch for '.$name); }
                }
            }
            // Write manifest if absent (synthesize)
            if(!$hasManifest){
                $manifest=[ 'schema_version'=>2,'uid'=>$resolvedUid,'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>$meta['type']??($meta['snapshot_type']??''),'message'=>$meta['message']??'','files'=>[],'checksums'=>['algo'=>'sha256','files'=>$downloadedChecksums],'size_total_bytes'=>$totalBytes,'compression'=>['enabled'=>false],'provenance'=>['source'=>'remote-meta-v1','source_remote'=>$remoteName,'remote_source_version'=>1] ];
                foreach($filesList as $name){ $size=filesize($tempDir.'/'.$name)?:0; $manifest['files'][]=['name'=>$name,'size_bytes'=>$size,'compressed'=>str_ends_with($name,'.gz'),'stored_inline'=>true]; }
                $this->write_json_atomic($tempDir.'/manifest-v2.json',$manifest);
                $hasManifest=true; $originalManifestSha=$this->integrity->hashManifest($manifest); $verified=true; // we computed ourselves
            } else { // persist manifest as downloaded
                $this->write_raw_file($tempDir.'/manifest-v2.json',$manifestRaw);
            }
            // Determine final uid (uid strategy)
            $finalUid=$resolvedUid; $provenancePath=null;
            if($uidStrategy==='new'){
                do { $finalUid=snapshot_uid::generate(); } while(is_dir($baseLocal.'/'.$finalUid));
                // rewrite manifest uid
                $manPath=$tempDir.'/manifest-v2.json'; $raw=@file_get_contents($manPath); if($raw!==false){ $data=@json_decode($raw,true); if(is_array($data)){ $data['uid']=$finalUid; $this->write_json_atomic($manPath,$data); }}
                $prov=['original_uid'=>$resolvedUid,'strategy'=>'new','source_remote'=>$remoteName,'imported_uid'=>$finalUid,'imported_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'source_type'=>$hasManifest?'manifest-v2':'meta-v1']; if($originalManifestSha!==''){ $prov['original_manifest_sha256']=$originalManifestSha; }
                $provenancePath=$tempDir.'/import_provenance_remote.json'; $this->write_json_atomic($provenancePath,$prov);
            }
            $finalPath=$baseLocal.'/'.$finalUid; if(is_dir($finalPath)) { if($uidStrategy==='keep' && !$force){ throw new ValidationException('target exists'); } if($force && $uidStrategy==='keep'){ $this->recursive_delete($finalPath); } }
            if(!@rename($tempDir,$finalPath)){ throw new ValidationException('promote failed'); }
            return ['success'=>true,'original_uid'=>$resolvedUid,'final_uid'=>$finalUid,'files'=>$fileCount,'bytes'=>$totalBytes,'verified'=>$verified] + ($uidStrategy==='new'? ['provenance_path'=>'import_provenance_remote.json'] : []);
        } catch(Throwable $e){ $cleanup(); if($e instanceof ValidationException){ throw $e; } throw new ValidationException('pull failed: '.$e->getMessage()); }
    }

    private function resolveUid(storage $storage,string $prefix): string {
        $prefix = strtolower($prefix);
        if(strlen($prefix)>=8) { // attempt direct directory check via listing for full prefix guess
            $objs=$storage->list_objects('snaps/'.$prefix.'/',1);
            if($objs){ return $prefix; }
        }
        $objs = $storage->list_objects('snaps/',1000);
        $candidates=[];
        foreach($objs as $o){
            $key=$o['key']??''; if(!str_starts_with($key,'snaps/')) continue; $rest=substr($key,6);
            $pos=strpos($rest,'/'); if($pos===false) continue; $uid=substr($rest,0,$pos);
            // Accept wider UID pattern: starts with alnum; allows dash; length 8-80
            if(!preg_match('/^[a-z0-9][a-z0-9\-]{7,79}$/i',$uid)) continue;
            if(!in_array($uid,$candidates,true)) $candidates[]=$uid; }
        $matches=[]; foreach($candidates as $c){ if(str_starts_with(strtolower($c),$prefix)) $matches[]=$c; }
        if(!$matches) return '';
        if(count($matches)===1) return $matches[0];
        foreach($matches as $c){ if(strtolower($c)===$prefix) return $c; }
        throw new ValidationException('ambiguous prefix');
    }

    private function write_json_atomic(string $path,array $data): void { $tmp=$path.'.tmp'; file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT)); @rename($tmp,$path); }
    private function write_raw_file(string $path,string $raw): void { $tmp=$path.'.tmp'; file_put_contents($tmp,$raw); @rename($tmp,$path); }
    private function recursive_delete(string $dir): void { if(!is_dir($dir)) return; $it=@scandir($dir); if(!$it){ @rmdir($dir); return; } foreach($it as $e){ if($e==='.'||$e==='..') continue; $p=$dir.'/'.$e; if(is_dir($p)) $this->recursive_delete($p); else @unlink($p); } @rmdir($dir); }
}
