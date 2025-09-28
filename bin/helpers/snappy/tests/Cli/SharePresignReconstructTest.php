<?php
declare(strict_types=1);

require_once __DIR__ . '/AbstractCliTestCase.php';

use Snappy\Share\share_registry; // ensure autoload

final class SharePresignReconstructTest extends AbstractCliTestCase {
    private function writeShares(array $tokens): void {
        $dir = $this->tmpRoot . '/.snappy';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $data = ['version'=>1,'tokens'=>$tokens];
        file_put_contents($dir . '/shares.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testReconstructFromPresigned(): void {
        $uid = 'u' . bin2hex(random_bytes(4));
        $rawToken = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $hash = hash('sha256', $rawToken);
        $fileContent = "SELECT 1;\n";
        $fileB64 = base64_encode($fileContent);
        $manifest = [
            'schema_version'=>2,
            'uid'=>$uid,
            'created_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
            'snapshot_type'=>'sql',
            'message'=>'',
            'files'=>[
                ['name'=>'backup.sql','size_bytes'=>strlen($fileContent),'compressed'=>false]
            ],
            'checksums'=>['algo'=>'sha256','files'=>[]],
            'size_total_bytes'=>strlen($fileContent),
            'compression'=>['enabled'=>false],
            'provenance'=>[],
        ];
        $manifestJson = json_encode($manifest);
        $tokens = [[
            'token_hash'=>$hash,
            'uid'=>$uid,
            'created_utc'=>gmdate('c'),
            'expires_utc'=>gmdate('c', time()+3600),
            'used_utc'=>null,
            'meta'=>[
                'presigned'=>[
                    ['file'=>'backup.sql','url'=>'data://text/plain;base64,' . $fileB64,'expires_utc'=>gmdate('c', time()+3600)],
                    ['file'=>'manifest-v2.json','url'=>'data://text/plain;base64,' . base64_encode($manifestJson),'expires_utc'=>gmdate('c', time()+3600)],
                ]
            ],
        ]];
        $this->writeShares($tokens);
        $out = $this->runCli('--json share fetch ' . $rawToken, $code);
        $this->assertSame(0, $code, 'fetch should succeed');
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $path = $decoded['data']['payload']['path'] ?? '';
        $this->assertDirectoryExists($path);
        $this->assertFileExists($path . '/backup.sql');
        $this->assertSame($fileContent, file_get_contents($path . '/backup.sql'));
        $this->assertFileExists($path . '/meta.json');
        $this->assertFileExists($path . '/manifest-v2.json');
    }

    public function testReconstructFailsOnBadUrl(): void {
        $uid = 'u' . bin2hex(random_bytes(4));
        $rawToken = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $hash = hash('sha256', $rawToken);
        $tokens = [[
            'token_hash'=>$hash,
            'uid'=>$uid,
            'created_utc'=>gmdate('c'),
            'expires_utc'=>gmdate('c', time()+3600),
            'used_utc'=>null,
            'meta'=>[
                'presigned'=>[
                    ['file'=>'backup.sql','url'=>'data://text/plain;base64,INVALIDBASE64','expires_utc'=>gmdate('c', time()+3600)],
                ]
            ],
        ]];
        $this->writeShares($tokens);
        $out = $this->runCli('--json share fetch ' . $rawToken, $code);
        $this->assertSame(6, $code, 'fetch should fail for bad data URL');
        $decoded = json_decode($out, true);
        $this->assertSame('error', $decoded['status']);
    }
}

