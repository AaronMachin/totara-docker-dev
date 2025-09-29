<?php
declare(strict_types=1);

require_once __DIR__.'/../Cli/AbstractCliTestCase.php';

final class ShareRoundTripTest extends AbstractCliTestCase {
    private function createSnapshot(): string {
        $out = $this->runCli('snapshot create -m test-share');
        if(!preg_match('/created snapshot ([a-f0-9]+)/',$out,$m)) { $this->fail('unable to parse created snapshot uid from output: '.$out); }
        return $m[1];
    }

    public function testShareCreateThenImportRoundTrip(): void {
        $uid = $this->createSnapshot();
        // Start share create process (auto export). Use ttl large enough.
        $cmd = $this->envPrefix.' '.$this->phpBin.' '.$this->cliEntry.' share create '.$uid.' --ttl=20 2>&1';
        $des=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc = proc_open($cmd,$des,$pipes); $this->assertIsResource($proc,'proc_open failed');
        fclose($pipes[0]);
        $encoded=''; $deadline=time()+10; $buf='';
        while(time()<$deadline){
            $line = fgets($pipes[1]); if($line!==false){ $buf.=$line; if(str_starts_with($line,'encoded: ')){ $encoded=trim(substr($line,9)); break; } }
            usleep(50000);
        }
        $this->assertNotSame('',$encoded,'did not capture encoded payload from share create output: '.$buf);
        // Import using encoded payload
        $importOut = $this->runCli('share import '.$encoded.' --uid-strategy=new',$code);
        $this->assertSame(0,$code,'share import failed: '.$importOut);
        $this->assertStringContainsString('imported snapshot',$importOut);
        // Extract imported uid and verification code from output
        $importedUid=''; $verCode='';
        if(preg_match('/imported snapshot ([a-f0-9-]+) \(verification ([a-z0-9-]+)\)/i',$importOut,$mm)) { $importedUid=$mm[1]; $verCode=$mm[2]; }
        $this->assertNotSame('',$importedUid,'failed to parse imported uid');
        $this->assertNotSame('',$verCode,'failed to parse verification code');
        // Locate snapshot base from envPrefix
        $snapBase=''; if(preg_match('/SNAPPY_SNAPSHOT_BASE=\'([^\']+)\'/',$this->envPrefix,$m2)){ $snapBase=$m2[1]; }
        $provFile=$snapBase.'/'.$importedUid.'/share_provenance.json';
        $this->assertFileExists($provFile,'share_provenance.json missing');
        $prov=json_decode(@file_get_contents($provFile),true); $this->assertIsArray($prov,'invalid provenance json');
        $this->assertSame($importedUid,$prov['imported_uid']??'');
        $this->assertSame($verCode,$prov['verification_code']??'');
        // Wait for server to exit (after first download)
        $st = proc_get_status($proc); $waitDeadline=time()+10; while($st['running'] && time()<$waitDeadline){ usleep(100000); $st=proc_get_status($proc); }
        $this->assertFalse($st['running'],'share create server did not exit after download');
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    }
}
