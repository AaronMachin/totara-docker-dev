<?php
namespace Snappy\Cli\Commands;

use Snappy\Cli\base_command;
use Snappy\Cli\context;
use Snappy\Util\editor;
use Snappy\Support\Exception\ValidationException;
use Throwable;

class snapshot_create extends base_command {
    public function name(): string { return 'snapshot.create'; }
    public function description(): string { return 'Create a snapshot (snapshot create)'; }
    public function usage(): string { return 'Usage: tsnap snapshot create [-m <message>] [--type=sql] [--compress] [--keep-failed] [--tag=TAG] [--tags=CSV]\nCreates a snapshot. If -m omitted an editor will open.'; }
    public function examples(): array { return ['tsnap snapshot create -m "initial load"','tsnap snapshot create --compress -m "before upgrade"','tsnap snapshot create --tag=release --tag=pre_migration -m "pre migration"','tsnap snapshot create --tags=alpha,beta -m tagged']; }

    public function run(array $args, context $ctx): int {
        $type = 'sql'; $message = ''; $compress = false; $keepFailed = false; $tags = [];
        for ($i=0;$i<count($args);$i++) {
            $arg = $args[$i];
            if (str_starts_with($arg,'--type=')) { $type = substr($arg,7); }
            elseif ($arg==='-m' && isset($args[$i+1])) { $message = $args[$i+1]; $i++; }
            elseif ($arg==='--compress') { $compress = true; }
            elseif ($arg==='--keep-failed') { $keepFailed = true; }
            elseif (str_starts_with($arg,'--tag=')) { $tags[] = substr($arg,6); }
            elseif (str_starts_with($arg,'--tags=')) { $csv = substr($arg,7); if ($csv!=='') { foreach (explode(',', $csv) as $t) { $tags[] = $t; } } }
        }
        // Normalize & validate tags early
        $normTags = [];
        foreach ($tags as $t) {
            $t = trim($t);
            if ($t==='') { continue; }
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $t)) { $ctx->out->info('invalid tag: '.$t); $ctx->out->error('invalid tag: '.$t, 4); return 4; }
            if (!isset($normTags[$t])) { $normTags[$t]=true; }
        }
        $tags = array_keys($normTags);
        if ($message==='') {
            $tpl = "\n\n# Enter snapshot message (lines starting with # ignored)\n# Abort with empty message.\n";
            $message = editor::acquire($tpl);
            if ($message==='') { $ctx->out->error('snapshot message required', 4); return 4; }
        }
        try { $uid = $ctx->manager->create($type,$message,'local',$compress,$keepFailed); }
        catch (ValidationException $ve) { throw $ve; }
        catch (Throwable $e) { $ctx->out->error('create failed: '.$e->getMessage(), 1); return 1; }
        // Apply tags post-create (idempotent) to avoid altering manager create signature
        foreach ($tags as $t) {
            try { $ctx->manager->add_tag($uid, $t); } catch (ValidationException $ve) { /* should not happen due to early validation */ } }
        // Fallback: if tags expected but manifest missing tags (edge race), patch directly
        if ($tags) {
            $mPath = $ctx->registry->local_base_path().'/snaps/'.$uid.'/manifest-v2.json';
            if (is_file($mPath)) {
                $raw = @json_decode(@file_get_contents($mPath), true);
                if (is_array($raw) && (int)($raw['schema_version']??0)===2 && empty($raw['tags'])) {
                    $raw['tags'] = $tags;
                    @file_put_contents($mPath, json_encode($raw, JSON_PRETTY_PRINT));
                    try { $ctx->index->addOrUpdate($uid); } catch (\Throwable $e) { /* ignore */ }
                }
            }
        }
        $finalManifest = $ctx->manager->read_manifest('local',$uid) ?? [];
        if ($tags) { $ctx->out->info('tags: '.implode(',', $tags)); $ctx->out->info("created snapshot $uid"); }
        else { $ctx->out->info("created snapshot $uid"); }
        $ctx->out->json(['uid'=>$uid,'type'=>$type,'compressed'=>$compress,'keep_failed'=>$keepFailed,'tags'=>$finalManifest['tags'] ?? []]);
        return 0;
    }
}
