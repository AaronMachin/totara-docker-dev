<?php
namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;

class metadata_manager {
    /** @var metadata_provider_interface[] */ private array $providers=[]; private snapshot_manager $snapManager;
    public function __construct(snapshot_manager $mgr, ?array $providers=null){ $this->snapManager=$mgr; if($providers!==null){ $this->providers=$providers; } else { $this->providers=$this->discover(); } }
    /** @return metadata_provider_interface[] */ private function discover(): array {
        $providers=[];
        // Hard-coded registration (extend here if adding more built-ins)
        if(class_exists(summary_metadata_provider::class)) { $providers[] = new summary_metadata_provider(); }
        return $providers; }
    /** Generate metadata files into snapshot directory (metadata/). */
    public function generate(string $uid): void {
        $dir = $this->snapManager->local_path($uid); if(!is_dir($dir)) throw new ValidationException('snapshot dir missing');
        $manifestPath=$dir.'/manifest-v2.json'; if(!is_file($manifestPath)) return; $raw=@file_get_contents($manifestPath); $manifest=@json_decode($raw,true); if(!is_array($manifest)) return;
        // Build file list + hashes from manifest (hashes are in checksums.files)
        $files=[]; foreach(($manifest['files']??[]) as $f){ $n=$f['name']??''; if($n!==''){ $files[]=$n; } }
        sort($files,SORT_STRING);
        $hashMap = (array)($manifest['checksums']['files'] ?? []);
        $artifactSha = ''; // unknown at creation time — providers must tolerate empty
        $ctx=['manifest'=>$manifest,'files'=>$files,'file_hashes'=>$hashMap,'artifact_sha256'=>$artifactSha];
        $metaDir=$dir.'/metadata'; if(!is_dir($metaDir)){ @mkdir($metaDir,0777,true); }
        foreach($this->providers as $provider){
            try { $out=$provider->generate($uid,$dir,$ctx); if(!is_array($out)) continue; foreach($out as $item){ $name=(string)($item['name']??''); $content=(string)($item['content']??''); if($name==='') continue; $safe=preg_replace('/[^A-Za-z0-9._-]/','_',$name); if($safe==='') continue; $target=$metaDir.'/'.$safe; $tmp=$target.'.tmp'.bin2hex(random_bytes(3)); file_put_contents($tmp,$content); @rename($tmp,$target); } } catch(\Throwable $e){ /* ignore provider failure */ }
        }
    }
}
