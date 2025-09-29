<?php
namespace Snappy\Share;

use Snappy\Snapshot\snapshot_manager; use Snappy\Snapshot\export_service; use Snappy\Snapshot\integrity_service; use Snappy\Support\Exception\ValidationException;

class share_create_service {
    private snapshot_manager $manager; private integrity_service $integrity;
    public function __construct(snapshot_manager $manager){ $this->manager=$manager; $this->integrity=new integrity_service(); }

    /** @param array{listen?:string,ttl?:int,max?:int,multi?:bool,no_auto_export?:bool} $options */
    public function serve(string $uidOrPrefix,array $options, callable $onPayload): array {
        $listen = $options['listen'] ?? ':0';
        $ttl = (int)($options['ttl'] ?? 300); if($ttl<=0) $ttl=300;
        $max = (int)($options['max'] ?? 1); if($max<1) $max=1;
        $multi = (bool)($options['multi'] ?? false);
        $noAuto = (bool)($options['no_auto_export'] ?? false);
        $uid = $this->manager->resolve_uid($uidOrPrefix,'local'); if($uid===''){ throw new ValidationException('snapshot not uniquely resolved'); }
        $snapDir = $this->manager->local_path($uid); if(!is_dir($snapDir)){ throw new ValidationException('snapshot directory missing'); }
        $artifact = $snapDir.'/'.$uid.'.tar.gz'; if(!is_file($artifact)) { $artifact = $snapDir.'/'.$uid.'.tar'; }
        if(!is_file($artifact)) {
            if($noAuto){ throw new ValidationException('artifact not found (export first)'); }
            $exp = new export_service($this->manager); $r = $exp->export($uid,['out_dir'=>$snapDir]); $artifact = $r['artifact_path'] ?? '';
        }
        if(!is_file($artifact)) { throw new ValidationException('artifact missing'); }
        $artifactSha = $this->integrity->hashFile($artifact); $code = $this->integrity->shortVerificationCode($artifactSha);
        // parse listen
        $host='127.0.0.1'; $port=0;
        if(str_contains($listen,':')){ $parts=explode(':',$listen); if($parts[0] !== ''){ $host=$parts[0]; } $last = $parts[count($parts)-1]; if($last!==''){ $port=(int)$last; } } else { $host=$listen; }
        $srv = new share_http_server($host,$port,$artifact,$multi?($max):1,$ttl,$multi);
        $payloadData = ['v'=>1,'h'=>$host,'p'=>0,'uid'=>$uid,'sha'=>$artifactSha,'code'=>$code,'path'=>'/artifact'];
        $onReady = function($boundHost,$boundPort) use (&$payloadData,$onPayload){ $payloadData['p']=$boundPort; $onPayload($payloadData); };
        $res = $srv->start($onReady);
        return ['uid'=>$uid,'downloads'=>$res['downloads'],'artifact_sha256'=>$artifactSha,'verification_code'=>$code];
    }
}

