<?php
namespace Snappy\Share;

use Snappy\Snapshot\import_service; use Snappy\Snapshot\snapshot_manager; use Snappy\Snapshot\integrity_service; use Snappy\Support\Exception\ValidationException;

class share_import_service {
    private snapshot_manager $manager; private integrity_service $integrity;
    public function __construct(snapshot_manager $manager){ $this->manager=$manager; $this->integrity=new integrity_service(); }

    /** @param array{uid_strategy?:string} $options */
    public function import(string $encoded,array $options=[]): array {
        $debug = (bool)($options['debug'] ?? false); $debugLines=[];
        $payload = payload_builder::decode($encoded);
        if(($payload['v']??0)!==1){ throw new ValidationException('unsupported payload version'); }
        $host = $payload['h']??''; $port=(int)($payload['p']??0); $path=$payload['path']??'/artifact'; $expectedSha=$payload['sha']??''; $code=$payload['code']??''; $sourceUid=$payload['uid']??'';
        if($host===''||$port<=0||$expectedSha===''){ throw new ValidationException('payload incomplete'); }
        $otp = $payload['k'] ?? null;
        if($debug){ $debugLines[]='host='.$host.' port='.$port.' path='.$path.' otp_present='.(($otp!==null)?'yes':'no'); }
        $tmp = $this->download($host,$port,$path,$expectedSha,$otp,$debug,$debugLines);
        $importer = new import_service($this->manager);
        $uidStrategy = $options['uid_strategy'] ?? 'new';
        $res = $importer->importArtifact($tmp,['uid_strategy'=>$uidStrategy]);
        $finalDir = $this->manager->local_path($res['imported_uid']);
        $prov=[
            'share_version'=>1,
            'source_host'=>$host,
            'source_port'=>$port,
            'artifact'=>$path,
            'artifact_sha256'=>$expectedSha,
            'verification_code'=>$code,
            'original_uid'=>$res['original_uid'],
            'imported_uid'=>$res['imported_uid'],
            'uid_strategy'=>$res['strategy'],
            'received_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'encoded_payload'=>$encoded,
        ];
        if($debug){ $prov['debug']=true; }
        $this->writeShareProvenance($finalDir,$prov);
        $result = $res + ['verification_code'=>$code]; if($debug){ $result['debug']=$debugLines; }
        return $result;
    }

    private function download(string $host,int $port,string $path,string $expectedSha, ?string $otp=null, bool $debug=false, array &$debugLines=[]): string {
        if($path===''){ $path='/artifact'; } if($path[0] !== '/') { $path = '/'.$path; }
        if($otp!==null){ $path .= (str_contains($path,'?')?'&':'?').'k='.$otp; }
        $lastError = null; if($debug){ $debugLines[]='request_path='.$path; }
        for($attempt=1;$attempt<=3;$attempt++) {
            if($debug){ $debugLines[]='attempt='.$attempt; }
            $addr = 'tcp://'.$host.':'.$port; $timeout=8;
            $fp = @stream_socket_client($addr,$errno,$errstr,$timeout);
            if(!$fp){ $lastError='connect failed'; if($debug){ $debugLines[]='connect_failed errno='.$errno; } usleep(120000); continue; }
            if($debug){ $debugLines[]='connected'; }
            stream_set_timeout($fp,8);
            $req = "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\nAccept: */*\r\nUser-Agent: snappy-share/1\r\n\r\n"; fwrite($fp,$req);
            $header=''; while(!str_contains($header,"\r\n\r\n")){
                $c=fread($fp,8192); if($c===false||$c===''){ if(feof($fp)) break; usleep(20000); continue; } $header.=$c; if(strlen($header)>131072){ break; }
            }
            $pos = strpos($header, "\r\n\r\n"); if($pos===false){ fclose($fp); $lastError='bad headers'; if($debug){ $debugLines[]='bad_headers'; } usleep(80000); continue; }
            $firstLine = strtok($header,"\r\n"); if($debug){ $debugLines[]='status_line='.($firstLine?:''); }
            if($firstLine===false || !preg_match('/^HTTP\/\d\.\d\s+(\d{3})\b/',$firstLine,$sm)) { fclose($fp); $lastError='unexpected status'; if($debug){ $debugLines[]='status_parse_fail'; } usleep(120000); continue; }
            $status=(int)$sm[1]; if($debug){ $debugLines[]='status='.$status; }
            if($status!==200){ fclose($fp); $lastError = match(true) {
                $status===404 => 'artifact not found (404)',
                $status>=400 && $status<500 => 'share server client error ('.$status.')',
                $status>=500 && $status<600 => 'share server internal error ('.$status.')',
                default => 'unexpected http status ('.$status.')'
            }; if($debug){ $debugLines[]='non_200:'.$lastError; } usleep(100000); continue; }
            $contentLength = 0; if(preg_match('/Content-Length:\s*(\d+)/i',$header,$m)){ $contentLength=(int)$m[1]; if($debug){ $debugLines[]='content_length='.$contentLength; } }
            $tmp=sys_get_temp_dir().'/snappy_share_dl_'.bin2hex(random_bytes(4)); $fh=@fopen($tmp,'wb'); if(!$fh){ fclose($fp); $lastError='temp open fail'; if($debug){ $debugLines[]='temp_open_fail'; } usleep(50000); continue; }
            $hashCtx=hash_init('sha256'); $written=0;
            $remain = substr($header,$pos+4); if($remain!==''){ fwrite($fh,$remain); hash_update($hashCtx,$remain); $written+=strlen($remain); }
            while(!feof($fp)){
                $buf=fread($fp,65536); if($buf===false){ break; } if($buf===''){ $meta=stream_get_meta_data($fp); if(($meta['timed_out']??false)===true){ if($debug){ $debugLines[]='read_timeout'; } break; } usleep(10000); continue; } fwrite($fh,$buf); hash_update($hashCtx,$buf); $written+=strlen($buf); }
            fclose($fp); fclose($fh);
            if($contentLength>0 && $written!==$contentLength){ @unlink($tmp); $lastError='incomplete download (expected '.$contentLength.' got '.$written.')'; if($debug){ $debugLines[]='incomplete bytes='.$written; } usleep(160000); continue; }
            $sha=hash_final($hashCtx); if($debug){ $debugLines[]='sha_download='.$sha; }
            if(strtolower($sha)!==strtolower($expectedSha)){
                @unlink($tmp); if($debug){ $debugLines[]='sha_mismatch expected='.$expectedSha; }
                throw new ValidationException('artifact sha mismatch');
            }
            if($debug){ $debugLines[]='success bytes='.$written; }
            return $tmp; // success
        }
        if($debug){ $debugLines[]='failure last_error='.($lastError??'unknown'); }
        throw new ValidationException($lastError ?: 'download failed');
    }

    private function writeShareProvenance(string $dir,array $prov): void { $file=$dir.'/share_provenance.json'; $tmp=$file.'.tmp'; file_put_contents($tmp,json_encode($prov,JSON_PRETTY_PRINT)); @rename($tmp,$file); }
}
