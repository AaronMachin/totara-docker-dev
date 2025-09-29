<?php

namespace Snappy\Snapshot;

use Snappy\Util\snapshot_uid;
use Snappy\Support\Exception\ValidationException;
use Snappy\Support\Exception\SnapshotNotFoundException;
use Snappy\Support\Exception\ProcessFailedException;
use Snappy\Support\Process\process_runner;
use Throwable;

class snapshot_manager {
    private remote_registry $registry;
    private ?DumpProviderResolver $dumpResolver = null;
    private ?index_manager $indexManager = null;
    private ?integrity_service $integrity = null;

    public function __construct(remote_registry $registry) { $this->registry = $registry; }
    public function registry(): remote_registry { return $this->registry; }
    public function set_index(index_manager $index): void { $this->indexManager = $index; }

    public function create(string $type, string $message, string $remote = 'local', bool $compress = false): string {
        if ($remote !== 'local') { throw new ValidationException('Snapshots can only be created locally'); }
        $uid = snapshot_uid::generate();
        $meta = ['uid'=>$uid,'created'=>date('c'),'type'=>$type,'message'=>$message,'files'=>[],'file_checksums'=>[]];
        $started = microtime(true);
        $tempDir = $this->temp_snapshot_dir($uid);
        $finalDir = rtrim($this->registry->local_base_path(), '/') . '/snaps/' . $uid;
        @mkdir(dirname($finalDir).'/',0777,true);
        try {
            if ($type === 'sql') { $this->create_sql_backup($uid,$meta,$tempDir); }
            else { throw new ValidationException('Unknown snapshot type: '.$type); }
            $compressionInfo = null;
            if ($compress) { $compressionInfo = $this->apply_compression($uid,$meta,$tempDir); }
            if (!@rename($tempDir,$finalDir)) { throw new ProcessFailedException('Failed to promote snapshot directory'); }
            $manifest = $this->build_manifest_v2($uid,$type,$message,$meta,$compressionInfo);
            $this->write_manifest_v2($uid,$manifest);
            $this->write_meta('local',$uid,$meta);
            try { $this->indexManager?->addOrUpdate($uid); } catch (Throwable $e) {}
            return $uid;
        } catch (Throwable $e) {
            $this->write_dump_failure_log($tempDir,$uid,$e,$started,microtime(true));
            $this->recursive_delete($tempDir);
            throw $e;
        }
    }

    private function temp_snapshot_dir(string $uid): string { $base=rtrim($this->registry->local_base_path(),'/'); $dir=$base.'/tmp/'.$uid; if(!is_dir($dir)){@mkdir($dir,0777,true);} return $dir; }
    private function local_snapshot_dir(string $uid): string { $base=$this->registry->local_base_path(); $dir=$base.'/snaps/'.$uid; if(!is_dir($dir)){@mkdir($dir,0777,true);} return $dir; }

    private function integrity(): integrity_service { return $this->integrity ??= new integrity_service(); }

    private function create_sql_backup(string $uid, array &$meta, string $workDir): void {
        $provider = $this->dump_resolver()->resolve(['type'=>'sql']);
        $result = $provider->dump($uid,$workDir,['registry'=>$this->registry]);
        $files = $result->files(); if(!$files){ throw new ProcessFailedException('Dump provider produced no files'); }
        foreach ($files as $file){ $name=$file['name']; $src=$file['path']; $dest=$workDir.'/'.$name; if(!is_file($src)) { throw new ProcessFailedException('Dump missing file '.$src);} if($src!==$dest){ if(!@copy($src,$dest)){ throw new ProcessFailedException('Copy failed'); } } $meta['files'][]=$name; $meta['file_checksums'][$name]=$this->integrity()->hashFile($dest); }
        $dumpMeta=$result->metadata(); if($dumpMeta){ $meta['dump_metadata']=$dumpMeta; }
    }

    private function apply_compression(string $uid, array &$meta, string $workDir): ?array {
        $original=$workDir.'/backup.sql'; if(!is_file($original)) { return null; }
        $originalSize=filesize($original)?:0; $gzPath=$original.'.gz';
        $success=false; if(function_exists('gzopen')){ $in=@fopen($original,'rb'); $out=@gzopen($gzPath,'wb6'); if($in&&$out){ while(!feof($in)){ $c=fread($in,8192); if($c===false) break; if($c!==''){ gzwrite($out,$c);} } fclose($in); gzclose($out); $success=is_file($gzPath); } }
        if(!$success){ $runner=new process_runner(); $gzipBin=trim((string)@shell_exec('command -v gzip 2>/dev/null'))?:'gzip'; $test=@shell_exec($gzipBin . ' --version 2>/dev/null'); if($test){ $res=$runner->run([$gzipBin,'-c',$original]); if($res->exitCode===0){ file_put_contents($gzPath,$res->stdout); $success=true; } } }
        if(!$success||!is_file($gzPath)) { throw new ValidationException('Compression requested but no gzip capability available'); }
        $compressedSize=filesize($gzPath)?:0; $ratio=$originalSize>0?($compressedSize/$originalSize):0.0; $checksum=$this->integrity()->hashFile($gzPath);
        $newFiles=[]; foreach($meta['files'] as $f){ $newFiles[]=$f==='backup.sql'?'backup.sql.gz':$f; } $meta['files']=$newFiles; $newChecksums=[]; foreach($meta['file_checksums'] as $f=>$h){ if($f==='backup.sql') continue; $newChecksums[$f]=$h; } $newChecksums['backup.sql.gz']=$checksum; $meta['file_checksums']=$newChecksums; @unlink($original);
        return ['algo'=>'gzip','original_size_bytes'=>$originalSize,'compressed_size_bytes'=>$compressedSize,'ratio'=>$ratio];
    }

    public function list(string $remote='local', bool $full=false, int $limit=100): array {
        if ($remote !== 'local') { return []; }
        // Try index fast path when available and not requesting full message
        if(!$full && $this->indexManager){ $idx=$this->indexManager->load(); if($idx && isset($idx['snapshots'])) { $rows=[]; foreach($idx['snapshots'] as $row){ $rows[]=['uid'=>$row['uid'],'created'=>$row['created_utc']??'','type'=>$row['type']??'','message'=>$row['message_first']??'']; } usort($rows,fn($a,$b)=>strcmp($b['created'],$a['created'])); return array_slice($rows,0,$limit); } }
        $base=$this->registry->local_base_path().'/snaps'; if(!is_dir($base)) return [];
        $entries=@scandir($base)?:[]; $rows=[]; foreach($entries as $e){ if($e==='.'||$e==='..') continue; $man=$base.'/'.$e.'/manifest-v2.json'; $meta=$base.'/'.$e.'/meta.json'; $manifest=null; if(is_file($man)){ $manifest=@json_decode(@file_get_contents($man),true); } elseif(is_file($meta)){ $manifest=@json_decode(@file_get_contents($meta),true); }
            if(!is_array($manifest)) continue; $msg=(string)($manifest['message']??''); if(!$full){ $msg=preg_split('/\r?\n/',$msg,2)[0]??''; }
            $rows[]=['uid'=>$e,'created'=>$manifest['created']??($manifest['created_utc']??''),'type'=>$manifest['type']??($manifest['snapshot_type']??''),'message'=>$msg]; }
        usort($rows,fn($a,$b)=>strcmp($b['created'],$a['created'])); if(count($rows)>$limit){ $rows=array_slice($rows,0,$limit);} return $rows;
    }

    public function resolve_uid(string $partial, string $remote='local'): string { $partial=trim($partial); if($partial==='') return ''; $uids=array_map(fn($r)=>$r['uid'],$this->list('local',false,1000)); if(in_array($partial,$uids,true)) return $partial; $matches=[]; foreach($uids as $u){ if(str_starts_with($u,$partial)){ $matches[]=$u; } } return count($matches)===1?$matches[0]:''; }

    public function read_meta(string $remote,string $uid): ?array { if($remote!=='local') return null; $file=$this->local_snapshot_dir($uid).'/meta.json'; if(!is_file($file)) return null; $data=@json_decode(@file_get_contents($file),true); return is_array($data)?$data:null; }
    private function write_meta(string $remote,string $uid,array $meta): void { if($remote!=='local') throw new ValidationException('write_meta only local'); $file=$this->local_snapshot_dir($uid).'/meta.json'; file_put_contents($file,json_encode($meta,JSON_PRETTY_PRINT)); }

    private function build_manifest_v2(string $uid,string $type,string $message,array $meta,?array $compression=null): array { $dir=$this->local_snapshot_dir($uid); $files=[]; $total=0; foreach($meta['files'] as $name){ $path=$dir.'/'.$name; $size=is_file($path)?filesize($path):0; $isCompressed=str_ends_with($name,'.gz'); $entry=['name'=>$name,'size_bytes'=>$size,'compressed'=>$isCompressed,'stored_inline'=>true]; if($isCompressed && $compression){ $entry['compression_algo']=$compression['algo']??'gzip'; } $files[]=$entry; $total+=$size; } $checksums=['algo'=>'sha256','files'=>[]]; foreach(($meta['file_checksums']??[]) as $file=>$hash){ $checksums['files'][$file]=$hash; } $compressionBlock=['enabled'=>false]; if($compression){ $compressionBlock=['enabled'=>true,'algo'=>$compression['algo'],'original_size_bytes'=>$compression['original_size_bytes'],'compressed_size_bytes'=>$compression['compressed_size_bytes'],'ratio'=>$compression['ratio']]; }
        $argv = $GLOBALS['argv'] ?? []; $cmdLine=''; if($argv){ $parts=[]; foreach($argv as $a){ $parts[] = strpos($a,' ')!==false?escapeshellarg($a):$a; } $cmdLine=implode(' ',$parts); }
        $provenance=['command_line'=>$cmdLine,'host'=>(string)(gethostname()?:''),'user'=>(string)(get_current_user() ?: ''),'php_version'=>PHP_VERSION];
        return ['schema_version'=>2,'uid'=>$uid,'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'snapshot_type'=>$type,'message'=>$message,'files'=>$files,'checksums'=>$checksums,'size_total_bytes'=>$total,'compression'=>$compressionBlock,'provenance'=>$provenance]; }
    private function write_manifest_v2(string $uid,array $manifest): void { $dir=$this->local_snapshot_dir($uid); $target=$dir.'/manifest-v2.json'; $tmp=sys_get_temp_dir().'/snappy_manifest_v2_'.$uid.'_'.bin2hex(random_bytes(4)).'.json'; $json=json_encode($manifest,JSON_PRETTY_PRINT); if($json===false) throw new ValidationException('encode manifest'); file_put_contents($tmp,$json); @rename($tmp,$target); if(!is_file($target)) throw new ValidationException('write manifest'); }

    public function local_path(string $uid): string { return $this->registry->local_base_path().'/snaps/'.$uid; }
    public function read_manifest(string $remote,string $uid): ?array { if($remote!=='local') return null; $man=$this->local_path($uid).'/manifest-v2.json'; if(!is_file($man)) return null; $raw=@json_decode(@file_get_contents($man),true); return is_array($raw)?$raw:null; }

    private function dump_resolver(): DumpProviderResolver { return $this->dumpResolver ??= new DumpProviderResolver(); }

    private function write_dump_failure_log(string $tempDir,string $uid,\Throwable $e,float $started,float $finished): void { if(!is_dir($tempDir)) return; $logsDir=$tempDir.'/logs'; if(!is_dir($logsDir)){@mkdir($logsDir,0777,true);} $file=$logsDir.'/dump.log'; $lines=['SNAPPY DUMP FAILURE','uid: '.$uid,'started_at: '.date('c',(int)$started),'finished_at: '.date('c',(int)$finished),'exception: '.get_class($e).': '.$e->getMessage()]; @file_put_contents($file,implode("\n",$lines)."\n"); }
    private function recursive_delete(string $dir): void { if(!is_dir($dir)) return; $items=@scandir($dir); if(!$items) return; foreach($items as $it){ if($it==='.'||$it==='..') continue; $path=$dir.'/'.$it; if(is_dir($path)) $this->recursive_delete($path); else @unlink($path); } @rmdir($dir); }
}
