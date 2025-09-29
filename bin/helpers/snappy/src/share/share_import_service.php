<?php
namespace Snappy\Share;

use Snappy\Snapshot\import_service; use Snappy\Snapshot\snapshot_manager; use Snappy\Snapshot\integrity_service; use Snappy\Support\Exception\ValidationException;

class share_import_service {
    private snapshot_manager $manager; private integrity_service $integrity;
    public function __construct(snapshot_manager $manager){ $this->manager=$manager; $this->integrity=new integrity_service(); }

    /** @param array{uid_strategy?:string} $options */
    public function import(string $encoded,array $options=[]): array {
        $payload = payload_builder::decode($encoded);
        if(($payload['v']??0)!==1){ throw new ValidationException('unsupported payload version'); }
        $host = $payload['h']??''; $port=(int)($payload['p']??0); $path=$payload['path']??'/artifact'; $expectedSha=$payload['sha']??''; $code=$payload['code']??''; $sourceUid=$payload['uid']??'';
        if($host===''||$port<=0||$expectedSha===''){ throw new ValidationException('payload incomplete'); }
        $tmp = $this->download($host,$port,$path,$expectedSha);
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
        $this->writeShareProvenance($finalDir,$prov);
        return $res + ['verification_code'=>$code];
    }

    private function download(string $host,int $port,string $path,string $expectedSha): string {
        $addr = 'tcp://'.$host.':'.$port; $timeout=5;
        $fp = @stream_socket_client($addr,$errno,$errstr,$timeout);
        if(!$fp){ throw new ValidationException('connect failed'); }
        stream_set_timeout($fp,5);
        $req = "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n"; fwrite($fp,$req);
        $header=''; while(!str_contains($header,"\r\n\r\n")){ $c=fread($fp,512); if($c===false||$c===''){ break; } $header.=$c; if(strlen($header)>16384){ break; } }
        if(!preg_match('/^HTTP\/1\.1 200 /',$header)){ fclose($fp); throw new ValidationException('unexpected status'); }
        $tmp=sys_get_temp_dir().'/snappy_share_dl_'.bin2hex(random_bytes(4)); $fh=@fopen($tmp,'wb'); if(!$fh){ fclose($fp); throw new ValidationException('temp open fail'); }
        $hashCtx=hash_init('sha256');
        $remain = substr($header,strpos($header,"\r\n\r\n")+4); if($remain!==''){ fwrite($fh,$remain); hash_update($hashCtx,$remain); }
        while(!feof($fp)){ $buf=fread($fp,65536); if($buf===false){ break; } if($buf===''){ continue; } fwrite($fh,$buf); hash_update($hashCtx,$buf); }
        fclose($fp); fclose($fh);
        $sha=hash_final($hashCtx); if(strtolower($sha)!==strtolower($expectedSha)){ @unlink($tmp); throw new ValidationException('artifact sha mismatch'); }
        return $tmp;
    }

    private function writeShareProvenance(string $dir,array $prov): void { $file=$dir.'/share_provenance.json'; $tmp=$file.'.tmp'; file_put_contents($tmp,json_encode($prov,JSON_PRETTY_PRINT)); @rename($tmp,$file); }
}

