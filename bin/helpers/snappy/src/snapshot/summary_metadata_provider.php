<?php
namespace Snappy\Snapshot;

class summary_metadata_provider implements metadata_provider_interface {
    public function generate(string $uid,string $snapshotDir,array $context): array {
        $manifest = $context['manifest'] ?? [];
        if(!is_array($manifest)) return [];
        $files = $context['files'] ?? [];
        $totalSize = 0; foreach(($manifest['files']??[]) as $f){ $totalSize += (int)($f['size_bytes'] ?? 0); }
        $data = [
            'uid'=>$uid,
            'snapshot_type'=>$manifest['snapshot_type'] ?? ($manifest['type'] ?? ''),
            'created_utc'=>$manifest['created_utc'] ?? ($manifest['created'] ?? ''),
            'file_count'=>count($files),
            'total_size_bytes'=>$totalSize,
            'compression'=>$manifest['compression']['enabled'] ?? false,
            'schema_version'=>1,
        ];
        return [['name'=>'summary.json','content'=>json_encode($data,JSON_PRETTY_PRINT) ?: '{}']];
    }
}

