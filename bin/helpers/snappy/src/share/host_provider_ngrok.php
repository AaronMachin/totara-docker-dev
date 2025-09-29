<?php
namespace Snappy\Share;

class host_provider_ngrok implements host_provider_interface {
    private $proc = null; private array $pipes = [];
    public function name(): string { return 'ngrok'; }
    public function start(int $localPort): ?array {
        $ngrokBin = trim((string)@getenv('SNAPPY_NGROK_BIN')) ?: 'ngrok';
        $binPath = trim((string)@shell_exec('command -v '.escapeshellarg($ngrokBin).' 2>/dev/null'));
        if($binPath===''){ return null; }
        $cmd = [$binPath,'tcp',(string)$localPort,'--log=stdout','--log-format=logfmt'];
        $desc = [1=>['pipe','w'],2=>['pipe','w']];
        $this->proc = @proc_open($cmd,$desc,$this->pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($this->proc)){ return null; }
        stream_set_blocking($this->pipes[1],false);
        $start = microtime(true); $forwardHost=''; $forwardPort=0;
        while(microtime(true)-$start < 6.0){
            $line = @fgets($this->pipes[1]); if($line!==false){ if(preg_match('/url=tcp:\/\/([^\s:]+):(\d+)/',$line,$m)){ $forwardHost=$m[1]; $forwardPort=(int)$m[2]; if($forwardHost && $forwardPort>0){ break; } } }
            usleep(100000);
        }
        if($forwardHost===''){ $this->shutdown(); return null; }
        return ['host'=>$forwardHost,'port'=>$forwardPort,'tunnel'=>'ngrok'];
    }
    public function shutdown(): void {
        if($this->proc){ @proc_terminate($this->proc,15); usleep(120000); $st=@proc_get_status($this->proc); if(($st['running']??false)===true){ @proc_terminate($this->proc,9);} foreach($this->pipes as $p){ if(is_resource($p)) @fclose($p);} @proc_close($this->proc); $this->proc=null; $this->pipes=[]; }
    }
}

