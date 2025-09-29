<?php
namespace Snappy\Share;

use Snappy\Support\Exception\ValidationException;

class share_http_server {
    private string $host; private int $port; private string $artifactPath; private int $maxDownloads; private int $ttl; private bool $multi; private int $successful=0; private int $artifactSize; private int $chunk=65536; private int $startedAt; private $server=null; private bool $stop=false;

    public function __construct(string $host,int $port,string $artifactPath,int $maxDownloads,int $ttl,bool $multi){
        $this->host=$host; $this->port=$port; $this->artifactPath=$artifactPath; $this->maxDownloads=$maxDownloads; $this->ttl=$ttl; $this->multi=$multi; $this->artifactSize = is_file($artifactPath)? (filesize($artifactPath)?:0):0; if($this->artifactSize<=0){ throw new ValidationException('artifact missing or empty'); }
    }

    /** Start server (blocking). Optional onReady callable(host,port). Returns array{host:string,port:int,downloads:int} */
    public function start(?callable $onReady=null): array {
        $flags = STREAM_SERVER_BIND|STREAM_SERVER_LISTEN;
        $errNo=0; $errStr='';
        $addr = 'tcp://'.$this->host.':'.$this->port;
        $this->server = @stream_socket_server($addr,$errNo,$errStr,$flags);
        if(!$this->server){ throw new ValidationException('listen failed: '.$errStr); }
        stream_set_blocking($this->server,false);
        // discover actual port if 0
        $name = stream_socket_get_name($this->server,false); // host:port
        if($name && str_contains($name,':')){ $parts=explode(':',$name); $this->port=(int)array_pop($parts); }
        if($onReady){ $onReady($this->host,$this->port); }
        $this->startedAt = time();
        $deadline = $this->startedAt + $this->ttl;
        while(!$this->stop){
            if(time() >= $deadline){ break; }
            if($this->maxDownloads>0 && $this->successful >= $this->maxDownloads){ break; }
            $r=[$this->server]; $w=$e=[]; $sec=1; $n=@stream_select($r,$w,$e,$sec,0);
            if($n===false){ continue; }
            if($n===0){ continue; }
            $conn=@stream_socket_accept($this->server,0);
            if(!$conn){ continue; }
            $this->handle($conn);
        }
        if($this->server){ @fclose($this->server); }
        return ['host'=>$this->host,'port'=>$this->port,'downloads'=>$this->successful];
    }

    private function handle($conn): void {
        stream_set_timeout($conn,3);
        $reqLine='';
        $deadline = microtime(true)+0.6; // allow up to 600ms for client to send initial line
        while(!str_contains($reqLine,"\r\n") && microtime(true) < $deadline){
            $chunk = fread($conn,1024);
            if($chunk===false){ usleep(10000); continue; }
            if($chunk===''){ usleep(10000); continue; }
            $reqLine.=$chunk;
            if(strlen($reqLine)>8192){ break; }
        }
        $first = strtok($reqLine,"\r\n");
        if(!$first){ $this->respond($conn,400,'bad request'); return; }
        $parts = explode(' ',$first); $method=$parts[0]??''; $path=$parts[1]??'';
        if($method!=='GET'){ $this->respond($conn,405,'method not allowed'); return; }
        if($path==='/health'){ $this->respond($conn,200,'ok'); return; }
        if($path==='/artifact'){
            $fh=@fopen($this->artifactPath,'rb'); if(!$fh){ $this->respond($conn,500,'artifact missing'); return; }
            $headers = [
                'HTTP/1.1 200 OK',
                'Content-Type: application/octet-stream',
                'Content-Length: '.$this->artifactSize,
                'Connection: close'
            ];
            fwrite($conn, implode("\r\n",$headers)."\r\n\r\n");
            $sent=0; $ok=true;
            while(!feof($fh)){
                $buf=fread($fh,$this->chunk); if($buf===false){ $ok=false; break; } if($buf===''){ continue; } $w=fwrite($conn,$buf); if($w===false){ $ok=false; break; } $sent+=$w; }
            fclose($fh); @fclose($conn);
            if($ok && $sent===$this->artifactSize){ $this->successful++; }
            return; }
        $this->respond($conn,404,'not found');
    }

    private function respond($conn,int $code,string $body): void { $msg = [200=>'OK',400=>'Bad Request',404=>'Not Found',405=>'Method Not Allowed',500=>'Internal Server Error'][$code]??'Status'; $resp="HTTP/1.1 $code $msg\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n$body"; @fwrite($conn,$resp); @fclose($conn); }

    public function port(): int { return $this->port; }
}
