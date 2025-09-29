<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/AbstractCliTestCase.php';

final class ShareTTLTest extends AbstractCliTestCase {
    private function createSnapshot(): string { $out=$this->runCli('snapshot create -m ttl'); if(!preg_match('/created snapshot ([a-f0-9]+)/',$out,$m)){ $this->fail('cannot parse uid'); } return $m[1]; }

    public function testServerStopsAfterTTL(): void {
        $uid=$this->createSnapshot();
        $cmd=$this->envPrefix.' '.$this->phpBin.' '.$this->cliEntry.' share create '.$uid.' --ttl=2 2>&1';
        $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); $this->assertIsResource($proc);
        fclose($pipes[0]);
        $deadline=time()+8; $output='';
        while(time()<$deadline){
            $line=fgets($pipes[1]); if($line!==false){ $output.=$line; }
            $st=proc_get_status($proc); if(!$st['running']) break; usleep(100000);
        }
        $st=proc_get_status($proc); $this->assertFalse($st['running'],'server still running after TTL expiry window');
        $this->assertStringContainsString('share server done downloads=0',$output,'expected completion line');
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    }
}

