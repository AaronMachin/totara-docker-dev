<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\export_service;

final class ImportStdInCliTest extends TestCase {
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir().'/snappy_import_stdin_'.bin2hex(random_bytes(5)); @mkdir($this->root,0777,true); }
    protected function tearDown(): void { $this->recursiveDelete($this->root); putenv('SNAPPY_FAKE_DUMP'); putenv('SNAPPY_CONFIG_FILE'); putenv('SNAPPY_SNAPSHOT_BASE'); }
    private function recursiveDelete(string $dir): void { if(!is_dir($dir)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }

    public function testImportFromStdIn(): void {
        putenv('SNAPPY_FAKE_DUMP=1');
        $configFile=$this->root.'/config.json'; file_put_contents($configFile,'{}');
        $snapBase=$this->root.'/snapbase'; @mkdir($snapBase,0777,true);
        putenv('SNAPPY_CONFIG_FILE='.$configFile);
        putenv('SNAPPY_SNAPSHOT_BASE='.$snapBase);
        $cfg=new config_manager($configFile,$snapBase); $cfg->save();
        $registry=new remote_registry($cfg,$snapBase);
        $manager=new snapshot_manager($registry);
        $uid=$manager->create('sql','stdin import');
        $artifact=(new export_service($manager))->export($uid,['out_dir'=>$this->root]);
        $script=__DIR__.'/../../tsnap_cli.php';
        if(!is_file($script)) { $this->markTestSkipped('CLI script missing'); }
        $cmd='cat '.escapeshellarg($artifact['artifact_path']).' | php '.escapeshellarg($script).' snapshot import - --uid-strategy=new --json 2>&1';
        $output=shell_exec($cmd);
        $this->assertNotNull($output,'CLI output null');
        $lines=preg_split('/\r?\n/',$output); $lines=array_values(array_filter($lines,'strlen')); $jsonLine=end($lines);
        $data=json_decode($jsonLine,true);
        $this->assertIsArray($data,'Top-level JSON not parsed');
        $payload=$data['data']['payload']??null; $this->assertIsArray($payload,'payload missing');
        $this->assertArrayHasKey('imported_uid',$payload);
        $this->assertNotSame($uid,$payload['imported_uid']);
        $this->assertDirectoryExists($snapBase.'/snaps/'.$payload['imported_uid']);
    }
}
