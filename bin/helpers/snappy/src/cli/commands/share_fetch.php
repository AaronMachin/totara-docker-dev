<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_registry;

class share_fetch extends base_command {
    public function name(): string { return 'share.fetch'; }
    public function description(): string { return 'Fetch a snapshot via single-use share token'; }
    public function usage(): string { return 'Usage: tsnap share fetch <token>\nResolves token, marks it used, outputs snapshot path & uid.'; }
    public function examples(): array { return ['tsnap share fetch AbCdEf...']; }

    public function run(array $args, context $ctx): int {
        $raw = null; foreach ($args as $a) { if ($a !== '' && $a[0] !== '-') { $raw = $a; break; } }
        if ($raw === null) { $ctx->out->error('share token required', 1); return 1; }
        // Basic format sanity (base64url-ish) optional
        if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $raw)) { $ctx->out->error('invalid token format', 2); return 2; }
        $reg = new share_registry($ctx->registry->local_base_path());
        $record = $reg->consume($raw);
        if (!$record) { $ctx->out->error('invalid, expired, or already used token', 3); return 3; }
        $uid = $record['uid'] ?? '';
        if ($uid === '') { $ctx->out->error('token record missing uid', 4); return 4; }
        $path = $ctx->manager->local_path($uid);
        if (!is_dir($path)) {
            // attempt presigned reconstruction
            $meta = $record['meta'] ?? [];
            $presigned = $meta['presigned'] ?? [];
            if (is_array($presigned) && $presigned) {
                $reconstruct = $this->reconstruct_from_presigned($uid, $presigned, $ctx, $record['expires_utc'] ?? null);
                if ($reconstruct['ok'] === false) { $ctx->out->error('reconstruction failed: '.$reconstruct['error'], 6); return 6; }
                $path = $reconstruct['path'];
            } else {
                $ctx->out->error('snapshot not found locally for uid ' . $uid, 5); return 5;
            }
        }
        $ctx->out->info('UID: ' . $uid);
        $ctx->out->info('Path: ' . $path);
        $ctx->out->json(['uid'=>$uid,'path'=>$path]);
        return 0;
    }

    private function reconstruct_from_presigned(string $uid, array $presigned, context $ctx, ?string $expiresUtc): array {
        $targetDir = $ctx->manager->local_path($uid);
        if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
        $manifestEntry = null; $fileEntries = [];
        foreach ($presigned as $entry) {
            if (!is_array($entry)) { continue; }
            $file = $entry['file'] ?? ''; $url = $entry['url'] ?? '';
            if ($file === '' || $url === '') { continue; }
            if ($file === 'manifest-v2.json') { $manifestEntry = $entry; }
            else { $fileEntries[] = $entry; }
        }
        // download files first
        $checksums = [];
        foreach ($fileEntries as $e) {
            $file = $e['file']; $url = $e['url'];
            $dest = $targetDir . '/' . $file;
            $dir = dirname($dest); if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $data = @file_get_contents($url);
            if ($data === false) { return ['ok'=>false,'error'=>'download failed for '.$file]; }
            if (@file_put_contents($dest, $data) === false) { return ['ok'=>false,'error'=>'write failed for '.$file]; }
            $checksums[$file] = hash_file('sha256', $dest);
        }
        // manifest
        $manifest = null;
        if ($manifestEntry) {
            $murl = $manifestEntry['url'];
            $mdata = @file_get_contents($murl);
            if ($mdata !== false) { $parsed = @json_decode($mdata, true); if (is_array($parsed)) { $manifest = $parsed; @file_put_contents($targetDir.'/manifest-v2.json', json_encode($parsed, JSON_PRETTY_PRINT)); } }
        }
        if (!$manifest) { // fallback minimal manifest
            $filesBlock = [];
            foreach ($checksums as $f => $_h) { $filesBlock[] = ['name'=>$f,'size_bytes'=>filesize($targetDir.'/'.$f)?:0,'compressed'=>str_ends_with($f,'.gz')]; }
            $manifest = [
                'schema_version' => 2,
                'uid' => $uid,
                'created_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'snapshot_type' => 'unknown',
                'message' => '',
                'files' => $filesBlock,
                'checksums' => ['algo'=>'sha256','files'=>[]],
                'size_total_bytes' => array_sum(array_map(fn($f)=>filesize($targetDir.'/'.$f)?:0, array_keys($checksums))),
                'compression' => ['enabled'=>false],
                'provenance' => [],
            ];
        }
        // build meta.json from manifest
        $meta = [
            'uid' => $uid,
            'created' => $manifest['created_utc'] ?? gmdate('c'),
            'type' => $manifest['snapshot_type'] ?? 'unknown',
            'message' => $manifest['message'] ?? '',
            'files' => [],
            'file_checksums' => [],
            'reconstructed' => true,
        ];
        foreach ($manifest['files'] as $finfo) {
            $name = $finfo['name'] ?? ''; if ($name==='') { continue; }
            $meta['files'][] = $name; $meta['file_checksums'][$name] = hash_file('sha256', $targetDir.'/'.$name);
        }
        @file_put_contents($targetDir.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT));
        return ['ok'=>true,'path'=>$targetDir];
    }
}
