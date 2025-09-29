<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/AbstractCliTestCase.php';

final class ShareTamperTest extends AbstractCliTestCase {
    private function createSnapshot(): string {
        $out = $this->runCli('snapshot create -m tamper');
        if(!preg_match('/created snapshot ([a-f0-9]+)/',$out,$m)) { $this->fail('cannot parse uid'); }
        return $m[1];
    }

    private function b64decodeNoPad(string $enc): string { $pad=strlen($enc)%4; if($pad!==0){ $enc.=str_repeat('=',4-$pad);} return (string)base64_decode($enc,true); }
    private function b64encodeNoPad(string $raw): string { return rtrim(base64_encode($raw),'='); }

    public function testTamperedShaCausesImportFailure(): void {
        $uid=$this->createSnapshot();
        $cmd = $this->envPrefix.' '.$this->phpBin.' '.$this->cliEntry.' share create '.$uid.' --ttl=30 2>&1';
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); $this->assertIsResource($proc);
        fclose($pipes[0]);
        $encoded=''; $deadline=time()+10; while(time()<$deadline){ $line=fgets($pipes[1]); if($line!==false){ if(str_starts_with($line,'encoded: ')){ $encoded=trim(substr($line,9)); break; } } usleep(50000);} $this->assertNotSame('',$encoded,'no encoded payload captured');
        $json=$this->b64decodeNoPad($encoded); $data=@json_decode($json,true); $this->assertIsArray($data);
        $data['sha']='deadbeef'.substr($data['sha'],8);
        $tampered=$this->b64encodeNoPad(json_encode($data));
        $out=$this->runCli('share import '.$tampered,$code); $this->assertSame(2,$code,'expected failure exit code'); $this->assertStringContainsString('artifact sha mismatch',$out);
        $st=proc_get_status($proc); $deadline=time()+5; while($st['running'] && time()<$deadline){ usleep(100000); $st=proc_get_status($proc);} if($st['running']){ proc_terminate($proc); }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    }
}

