<?php
require __DIR__ . '/vendor/autoload.php';

use Snappy\Util\canonical_json;

$examplePath = __DIR__ . '/schema/manifest_v2.example.json';
$raw = file_get_contents($examplePath);
if ($raw === false) { fwrite(STDERR, "missing file: $examplePath\n"); exit(1); }

$data = json_decode($raw, true);
if ($data === null) { fwrite(STDERR, "json decode error\n"); exit(1); }

// Canonical encode using shared utility (kept for contributor convenience per schema_change.md)
$json = canonical_json::encode($data);

echo $json, "\n";
echo 'HASH=', hash('sha256', $json), "\n";
