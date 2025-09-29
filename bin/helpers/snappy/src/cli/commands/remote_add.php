<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Support\Exception\SnappyException;
use Snappy\Support\Exception\ExitCodes;
use Snappy\Support\Exception\ValidationException;

class remote_add extends base_command {
    public function name(): string { return 'remote.add'; }
    public function description(): string { return 'Add an S3 (or compatible) remote configuration'; }
    public function usage(): string { return 'Usage: tsnap remote add <name> [s3] --endpoint=URL --bucket=NAME --region=REG --key=K --secret=S [--path-style]\nLegacy: tsnap remote add <name> memory'; }
    public function examples(): array { return [
        'tsnap remote add prod --endpoint=https://s3.example.com --bucket=mybucket --region=us-east-1 --key=abcd1234 --secret=xyz',
        'tsnap remote add mem1 memory',
    ]; }

    public function run(array $args, context $ctx): int {
        $name = $args[0] ?? '';
        if ($name==='') { $ctx->out->error('name required',64); return 64; }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name)) { $ctx->out->error('invalid name pattern',2); return 2; }
        $legacyType = $args[1] ?? null;
        if ($legacyType === 'memory') { // legacy support
            try { $ctx->registry->add($name,'memory',[]); }
            catch (ValidationException $ve) { $code = ExitCodes::codeFor($ve); $ctx->out->error($ve->getMessage(), $code); return $code; }
            catch (SnappyException $se) { $code = ExitCodes::codeFor($se); $ctx->out->error($se->getMessage(), $code); return $code; }
            catch (\Throwable $t) { $ctx->out->error('add failed: '.$t->getMessage(), ExitCodes::UNKNOWN); return ExitCodes::UNKNOWN; }
            $ctx->out->info('added remote '.$name.' (memory)');
            $ctx->out->json(['action'=>'add','name'=>$name,'config'=>[]]);
            return 0;
        }
        // Parse flags
        $flags = array_slice($args,1);
        $cfg = ['path_style'=>false];
        foreach ($flags as $f) {
            if ($f==='--path-style') { $cfg['path_style']=true; continue; }
            if (str_starts_with($f,'--endpoint=')) { $cfg['endpoint']=substr($f,11); continue; }
            if (str_starts_with($f,'--bucket=')) { $cfg['bucket']=substr($f,9); continue; }
            if (str_starts_with($f,'--region=')) { $cfg['region']=substr($f,9); continue; }
            if (str_starts_with($f,'--key=')) { $cfg['key']=substr($f,6); continue; }
            if (str_starts_with($f,'--secret=')) { $cfg['secret']=substr($f,9); continue; }
        }
        if (!isset($cfg['region']) || $cfg['region']==='') { $cfg['region']='us-east-1'; }
        $required=['endpoint','bucket','key','secret']; $missing=[]; foreach($required as $r){ if(($cfg[$r]??'')===''){ $missing[]=$r; }}
        if ($missing) { $ctx->out->error('missing required flags: '.implode(', ',$missing),64); return 64; }
        try {
            $ctx->registry->add($name,'s3',$cfg);
        } catch (ValidationException $ve) {
            $ctx->out->error($ve->getMessage(), ExitCodes::codeFor($ve)); return ExitCodes::codeFor($ve);
        } catch (SnappyException $se) {
            $code = ExitCodes::codeFor($se); $ctx->out->error($se->getMessage(), $code); return $code;
        } catch (\Throwable $t) {
            $ctx->out->error('add failed: '.$t->getMessage(), ExitCodes::UNKNOWN); return ExitCodes::UNKNOWN;
        }
        $ctx->out->info('added remote '.$name);
        // Human redaction (show first 4 of key, mask secret)
        $humanKey = substr($cfg['key'],0,4);
        $ctx->out->info('  key: '.$humanKey.'  secret: ********');
        // JSON payload (omit credentials per spec)
        $jsonCfg = $cfg; unset($jsonCfg['key'],$jsonCfg['secret']);
        $ctx->out->json(['action'=>'add','name'=>$name,'config'=>$jsonCfg]);
        return 0;
    }
}
