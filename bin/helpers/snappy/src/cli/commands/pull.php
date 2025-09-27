<?php

namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Hosting\remote_codec;
use Throwable;
use RuntimeException;

class pull extends base_command {
    public function name(): string {
        return 'pull';
    }

    public function description(): string {
        return 'Retrieve a snapshot from a remote into local storage';
    }

    public function usage(): string {
        return 'Usage: tsnap pull [<uid|prefix>] [--remote=name]|[--share=TOKEN] [--force] [--keep-remote]\nPull from remote or download presigned share token.';
    }

    public function examples(): array {
        return ['tsnap pull --share=TOKEN', 'tsnap pull abc123 --remote=origin', 'tsnap pull --share=TOKEN --force'];
    }

    public function run(array $args, context $ctx): int {
        [$opts, $positionals, $errors] = $this->parsePullArgs($args);
        if ($errors) { foreach ($errors as $e) { fwrite(STDERR, $e."\n"); } return 1; }
        $shareToken = $opts['share'];
        $force = $opts['force'];
        if ($shareToken !== null) { return $this->pullFromShareToken($shareToken, $force, $ctx); }
        $token = $positionals[0] ?? '';
        if ($token === '') { fwrite(STDERR, "--share=TOKEN required (legacy pull by uid removed in this mode)\n"); return 1; }
        fwrite(STDERR, "Direct UID/remote pulls disabled (use --share).\n"); return 2;
    }

    private function parsePullArgs(array $argv): array {
        $def = [
            'force' => ['flags' => ['--force'], 'type' => 'bool', 'default' => false],
            'keep' => ['flags' => ['--keep-remote'], 'type' => 'bool', 'default' => false],
            'remote' => ['prefix' => '--remote=', 'type' => 'string', 'default' => null],
            'share' => ['prefix' => '--share=', 'type' => 'string', 'default' => null],
        ];
        $parsed = $this->parseArgs($argv, $def);
        return [$parsed['options'], $parsed['positionals'], $parsed['errors']];
    }

    private function pullFromShareToken(string $token, bool $force, context $ctx): int {
        try { $decoded = remote_codec::decode($token); }
        catch (RuntimeException $e) { fwrite(STDERR,'decode failed: '.$e->getMessage()."\n"); return 2; }
        if (($decoded['t'] ?? '') !== 'ps' || empty($decoded['u']) || empty($decoded['x'])) { fwrite(STDERR,'invalid share token (expected fields t=ps,u,x)\n'); return 2; }
        if ((int)$decoded['x'] < time()) { fwrite(STDERR,'share token expired\n'); return 2; }
        $url = $decoded['u'];
        $tmp = sys_get_temp_dir().'/snappy_dl_'.bin2hex(random_bytes(4)).'.tar.gz';
        $fh = fopen($tmp,'w'); if(!$fh){ fwrite(STDERR,'temp file open failed\n'); return 2; }
        $isTTY = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
        $start = microtime(true);
        $lastDraw = 0.0;
        $spinner = ['⠋','⠙','⠸','⠼','⠴','⠦','⠇','⠏'];
        $spinIdx = 0;
        $barWidth = 34;
        $human = function(float $bytes): string {
            $u=['B','KB','MB','GB','TB']; $i=0; while($bytes>=1024 && $i<count($u)-1){$bytes/=1024;$i++;} return sprintf('%0.2f %s',$bytes,$u[$i]); };
        $draw = function($downloaded,$total) use (&$lastDraw,$start,&$spinIdx,$spinner,$barWidth,$human,$isTTY){
            $now = microtime(true);
            if(($now - $lastDraw) < 0.05 && $total>0) return; // limit redraw
            $lastDraw = $now;
            $elapsed = $now - $start;
            $rate = $elapsed>0 ? $downloaded / $elapsed : 0; // bytes/sec
            $eta = ($total>0 && $rate>0) ? ($total - $downloaded)/$rate : 0;
            $pct = ($total>0) ? ($downloaded/$total) : 0;
            $filled = (int)round($pct*$barWidth);
            $spin = $spinner[$spinIdx++ % count($spinner)];
            $bar = str_repeat('█',$filled).str_repeat('·', max(0,$barWidth-$filled));
            $pctTxt = $total>0 ? sprintf('%5.1f%%',$pct*100) : '  ??%';
            $speedTxt = $rate>0 ? $human($rate).'/s' : '--';
            $etaTxt = $total>0 && $rate>0 ? sprintf('ETA %ss', max(0,(int)$eta)) : '';
            if($isTTY){
                $line = sprintf("\r %s [%s] %s %s/%s %s", $spin, $bar, $pctTxt, $human($downloaded), $total>0?$human($total):'?', $speedTxt);
                if($etaTxt!=='') $line .= ' '.$etaTxt;
                echo $line; fflush(STDOUT);
            }
        };
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0); // allow large
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource,$dltotal,$dlnow,$ultotal,$ulnow) use ($draw){ $draw($dlnow,$dltotal); });
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false || $status >= 400) { $err=curl_error($ch); curl_close($ch); fclose($fh); @unlink($tmp); if($isTTY) echo "\n"; fwrite(STDERR,'download failed status '.$status.' '.$err."\n"); return 3; }
        curl_close($ch); fclose($fh);
        if($isTTY){ $draw(filesize($tmp), filesize($tmp)); echo "\r ✔ Download complete                              \n"; }
        // Determine snapshot UID from archive first entry
        $cmdList = 'tar -tzf '.escapeshellarg($tmp).' 2>/dev/null | head -n1';
        $first = trim(shell_exec($cmdList) ?? '');
        if ($first === '') { @unlink($tmp); fwrite(STDERR,'archive empty or unreadable\n'); return 4; }
        $uid = trim(explode('/', $first)[0]);
        if ($uid === '') { @unlink($tmp); fwrite(STDERR,'could not determine snapshot uid from archive\n'); return 4; }
        if (!$force && $ctx->manager->read_meta('local', $uid)) { @unlink($tmp); fwrite(STDERR,'snapshot already exists locally: '.$uid." (use --force to overwrite)\n"); return 5; }
        $snapsBase = $ctx->registry->local_base_path().'/snaps';
        if (!is_dir($snapsBase)) { @mkdir($snapsBase,0777,true); }
        $cmdExtract = 'tar -xzf '.escapeshellarg($tmp).' -C '.escapeshellarg($snapsBase).' 2>&1';
        $out=[]; $rc=0; exec($cmdExtract,$out,$rc);
        @unlink($tmp);
        if ($rc !== 0) { fwrite(STDERR,'extract failed rc='.$rc.' '.implode(' ',$out)."\n"); return 6; }
        if (!$ctx->manager->read_meta('local',$uid)) { fwrite(STDERR,'error: meta.json not found after extract (snapshot incomplete)\n'); return 7; }
        echo "retrieved snapshot $uid via share token into local\n";
        return 0;
    }

    private function cleanupTemp(?array $temp, bool $shouldRemove, context $ctx): void {
        if (!$temp || !$shouldRemove || empty($temp['created'])) {
            return;
        }
        try {
            $ctx->registry->remove($temp['name']);
        } catch (Throwable $e) { /* ignore */
        }
    }
}
