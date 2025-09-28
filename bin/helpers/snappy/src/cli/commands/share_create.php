<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Share\share_registry;

class share_create extends base_command {
    public function name(): string { return 'share.create'; }
    public function description(): string { return 'Create a one-time share token for a snapshot (archives by default)'; }
    public function usage(): string { return 'Usage: tsnap share create <uid|prefix> [--expire=1h] [--no-archive]\nCreates a single-use share token (hashed) and tar.gz archive by default.'; }
    public function examples(): array { return ['tsnap share create a1b2c3','tsnap share create a1b2c3 --expire=30m','tsnap share create a1b2c3 --no-archive']; }

    public function run(array $args, context $ctx): int {
        $tokenArg = null; $expireSpec = '24h'; $noArchive = false;
        foreach ($args as $a) {
            if ($a !== '' && $a[0] !== '-') { $tokenArg = $a; continue; }
            if (str_starts_with($a,'--expire=')) { $expireSpec = substr($a,9); }
            elseif ($a==='--no-archive') { $noArchive = true; }
        }
        if ($tokenArg === null) { $ctx->out->error('snapshot uid or unique prefix required', 1); return 1; }
        $uid = $ctx->manager->resolve_uid($tokenArg, 'local');
        if ($uid === '') { $ctx->out->error("no or ambiguous match for '$tokenArg' (local)", 3); return 3; }
        $ttl = $this->parseExpire($expireSpec);
        if ($ttl <= 0) { $ctx->out->error('invalid --expire specification', 2); return 2; }
        $registry = new share_registry($ctx->registry->local_base_path());
        $raw = $this->generateToken($registry);
        $hash = hash('sha256', $raw);
        $manifest = $ctx->manager->read_manifest('local', $uid) ?? [];
        $message = (string)($manifest['message'] ?? '');
        $firstLine = $message === '' ? '' : preg_split('/\r?\n/', $message, 2)[0];
        $tags = is_array($manifest['tags'] ?? null) ? array_values($manifest['tags']) : [];
        $created = gmdate('c');
        $expires = gmdate('c', time() + $ttl);
        $meta = [ 'tags' => $tags, 'message_first_line' => $firstLine ];
        if (!$noArchive) {
            try {
                $archiveInfo = $this->buildArchive($ctx, $uid);
                if ($archiveInfo) {
                    $meta['archive_path'] = $archiveInfo['path'];
                    $meta['archive_checksum'] = $archiveInfo['checksum'];
                    $meta['archive_size_bytes'] = $archiveInfo['size_bytes'];
                }
            } catch (\Throwable $e) {
                $ctx->out->error('archive build failed: '.$e->getMessage(), 5);
                return 5;
            }
        }
        $record = [
            'token_hash' => $hash,
            'uid' => $uid,
            'created_utc' => $created,
            'expires_utc' => $expires,
            'used_utc' => null,
            'meta' => $meta,
        ];
        $registry->add($record);
        $ctx->out->info('Snapshot UID: ' . $uid);
        if (isset($meta['archive_path'])) { $ctx->out->info('Archive: ' . $meta['archive_path']); }
        $ctx->out->info('Share token (store securely; shown once): ' . $raw);
        $ctx->out->info('Expires: ' . $expires);
        $payload = ['uid'=>$uid,'share_token'=>$raw,'expires_utc'=>$expires];
        if (isset($meta['archive_path'])) { $payload['archive_path'] = $meta['archive_path']; }
        $ctx->out->json($payload);
        return 0;
    }

    private function buildArchive(context $ctx, string $uid): ?array {
        $base = $ctx->registry->local_base_path();
        $exportsDir = $base . '/exports';
        if (!is_dir($exportsDir)) { @mkdir($exportsDir, 0777, true); }
        $snapshotDir = $ctx->manager->local_path($uid);
        if (!is_dir($snapshotDir)) { throw new \RuntimeException('snapshot directory missing'); }
        $archive = $exportsDir . '/snapshot_' . $uid . '.tar.gz';
        $checksumFile = $archive . '.sha256';
        if (is_file($archive) && is_file($checksumFile)) {
            // Reuse existing
            $hash = trim(explode(' ', @file_get_contents($checksumFile) ?: '')[0]);
            if ($hash !== '') {
                return ['path'=>$archive,'checksum'=>$hash,'size_bytes'=>filesize($archive) ?: 0];
            }
        }
        $tmpTar = $exportsDir . '/snapshot_' . $uid . '_' . bin2hex(random_bytes(4)) . '.tar';
        $tarHandle = fopen($tmpTar, 'wb');
        if (!$tarHandle) { throw new \RuntimeException('cannot open temp tar'); }
        $this->addDirToTar($tarHandle, $snapshotDir, strlen($snapshotDir)+1);
        // two zero blocks
        fwrite($tarHandle, str_repeat("\0", 1024));
        fclose($tarHandle);
        // gzip
        $tmpGz = $tmpTar . '.gz';
        $in = fopen($tmpTar, 'rb'); if (!$in) { throw new \RuntimeException('cannot reopen temp tar'); }
        $gz = gzopen($tmpGz, 'wb9'); if (!$gz) { fclose($in); throw new \RuntimeException('cannot open gzip'); }
        while (!feof($in)) { $data = fread($in, 8192); if ($data === false) break; if ($data !== '') { gzwrite($gz, $data); } }
        fclose($in); gzclose($gz);
        @unlink($tmpTar);
        @rename($tmpGz, $archive);
        if (!is_file($archive)) { throw new \RuntimeException('failed to finalize archive'); }
        $hash = hash_file('sha256', $archive);
        $line = $hash . '  ' . basename($archive) . "\n";
        @file_put_contents($checksumFile, $line);
        return ['path'=>$archive,'checksum'=>$hash,'size_bytes'=>filesize($archive) ?: 0];
    }

    private function addDirToTar($tarHandle, string $dir, int $stripLen): void {
        $items = @scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') { continue; }
            $path = $dir . '/' . $it;
            $rel = substr($path, $stripLen);
            if ($rel === false) { $rel = $it; }
            if (is_dir($path)) {
                $this->writeTarHeader($tarHandle, rtrim($rel,'/').'/', 0, 5, filemtime($path) ?: time());
                $this->addDirToTar($tarHandle, $path, $stripLen);
            } elseif (is_file($path)) {
                $size = filesize($path) ?: 0;
                $mtime = filemtime($path) ?: time();
                $this->writeTarHeader($tarHandle, $rel, $size, 0, $mtime);
                $fh = fopen($path, 'rb'); if ($fh) { while (!feof($fh)) { $chunk = fread($fh, 8192); if ($chunk === false) break; if ($chunk !== '') { fwrite($tarHandle, $chunk); } } fclose($fh); }
                $pad = (512 - ($size % 512)) % 512; if ($pad) { fwrite($tarHandle, str_repeat("\0", $pad)); }
            }
        }
    }

    private function writeTarHeader($tarHandle, string $name, int $size, int $typeFlag, int $mtime): void {
        $name = str_replace('\\', '/', $name);
        $prefix = '';
        if (strlen($name) > 100) {
            // split into prefix + name per ustar
            $parts = explode('/', $name);
            $file = array_pop($parts);
            $prefix = substr(implode('/', $parts), 0, 155);
            $name = substr($file, 0, 100);
        }
        $header = str_pad($name, 100, "\0");
        $header .= str_pad(decoct(0644), 7, '0', STR_PAD_LEFT) . "\0"; // mode
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0"; // uid
        $header .= str_pad(decoct(0), 7, '0', STR_PAD_LEFT) . "\0"; // gid
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"; // size
        $header .= str_pad(decoct($mtime), 11, '0', STR_PAD_LEFT) . "\0"; // mtime
        $header .= str_repeat(' ', 8); // checksum placeholder
        $header .= ($typeFlag === 5 ? '5' : '0'); // typeflag
        $header .= str_repeat("\0", 100); // linkname
        $header .= 'ustar' . "\0"; // magic
        $header .= '00'; // version
        $header .= str_pad('root', 32, "\0"); // uname
        $header .= str_pad('root', 32, "\0"); // gname
        $header .= str_repeat("\0", 8); // devmajor
        $header .= str_repeat("\0", 8); // devminor
        $header .= str_pad($prefix, 155, "\0");
        $header .= str_repeat("\0", 12); // pad to 512
        // checksum
        $checksum = 0; for ($i=0;$i<strlen($header);$i++) { $checksum += ord($header[$i]); }
        $checksumStr = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ";
        $header = substr_replace($header, $checksumStr, 148, 8);
        fwrite($tarHandle, $header);
    }

    private function generateToken(share_registry $reg): string {
        // Loop until collision-free (extremely unlikely to loop >1)
        for ($i=0;$i<5;$i++) {
            $raw = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            if (!$reg->hasHash(hash('sha256',$raw))) { return $raw; }
        }
        // Fallback include random uniqid if pathological collisions
        return rtrim(strtr(base64_encode(random_bytes(24) . uniqid('', true)), '+/', '-_'), '=');
    }

    private function parseExpire(string $spec): int {
        $spec = trim($spec);
        if ($spec === '') { return 0; }
        if (preg_match('/^(\d+)([mhd])?$/i', $spec, $m)) {
            $n = (int)$m[1]; $unit = strtolower($m[2] ?? 's');
            return match($unit) {
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                default => $n, // seconds
            };
        }
        return 0; // invalid
    }
}
