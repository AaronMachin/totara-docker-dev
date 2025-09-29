<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/AbstractCliTestCase.php';

final class ShareOtpTest extends AbstractCliTestCase {
    private function createSnapshot(): string {
        $out = $this->runCli('snapshot create -m otp');
        if(!preg_match('/created snapshot ([a-f0-9]+)/',$out,$m)) { $this->fail('cannot parse uid'); }
        return $m[1];
    }
    private function b64decodeNoPad(string $enc): array { $pad=strlen($enc)%4; if($pad!==0){ $enc.=str_repeat('=',4-$pad);} $raw=base64_decode($enc,true); $arr=@json_decode($raw,true); return is_array($arr)?$arr:[]; }

    public function testOtpIncludedAndValid(): void {
        $uid=$this->createSnapshot();
        $cmd=$this->envPrefix.' '.$this->phpBin.' '.$this->cliEntry.' share create '.$uid.' --ttl=25 --multi 2>&1';
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); $this->assertIsResource($proc);
        fclose($pipes[0]);
        $encoded=''; $deadline=time()+10; while(time()<$deadline){ $line=fgets($pipes[1]); if($line!==false){ if(str_starts_with($line,'encoded: ')){ $encoded=trim(substr($line,9)); break; } } usleep(50000);} $this->assertNotSame('',$encoded,'no encoded payload');
        $payload=$this->b64decodeNoPad($encoded); $this->assertArrayHasKey('k',$payload,'OTP missing'); $this->assertNotEmpty($payload['k']);
        // Perform import (should succeed with embedded OTP automatically appended)
        $importOut=$this->runCli('share import '.$encoded,$code); $this->assertSame(0,$code,$importOut);
        $this->assertStringContainsString('imported snapshot',$importOut);
        // Wait for server exit
        $st=proc_get_status($proc); $wait=time()+8; while($st['running'] && time()<$wait){ usleep(100000); $st=proc_get_status($proc);} if($st['running']){ proc_terminate($proc); }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    }

    public function testOtpMismatchCausesForbidden(): void {
        $uid=$this->createSnapshot();
        $cmd=$this->envPrefix.' '.$this->phpBin.' '.$this->cliEntry.' share create '.$uid.' --ttl=25 2>&1';
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); $this->assertIsResource($proc);
        fclose($pipes[0]);
        $encoded=''; $deadline=time()+10; while(time()<$deadline){ $line=fgets($pipes[1]); if($line!==false){ if(str_starts_with($line,'encoded: ')){ $encoded=trim(substr($line,9)); break; } } usleep(50000);} $this->assertNotSame('',$encoded);
        $payload=$this->b64decodeNoPad($encoded); $this->assertArrayHasKey('k',$payload); $payload['k']='deadbeef';
        $tampered=rtrim(base64_encode(json_encode($payload)),'=');
        $out=$this->runCli('share import '.$tampered,$code); $this->assertSame(2,$code,'expected exit 2 for forbidden');
        $this->assertStringContainsString('client error (403)',$out);
        $st=proc_get_status($proc); $wait=time()+8; while($st['running'] && time()<$wait){ usleep(100000); $st=proc_get_status($proc);} if($st['running']){ proc_terminate($proc); }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    }
}

