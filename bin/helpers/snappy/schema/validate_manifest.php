#!/usr/bin/env php
<?php
// Simple validator for manifest_v2.example.json against manifest_v2.json.
// NOTE: This is a pragmatic subset validator (not full JSON Schema implementation).
// It enforces required fields, basic types, patterns, enums, and conditional rules
// relevant to current roadmap. For full validation integrate a JSON Schema lib later.

$baseDir = __DIR__;
$schemaFile = $baseDir . '/manifest_v2.json';
$exampleFile = $baseDir . '/manifest_v2.example.json';

function fail(string $msg): void { fwrite(STDERR, "FAIL: $msg\n"); exit(1);}
function info(string $msg): void { fwrite(STDOUT, "[info] $msg\n"); }

if (!is_file($schemaFile)) fail('Missing schema file');
if (!is_file($exampleFile)) fail('Missing example manifest file');

$schema = json_decode(file_get_contents($schemaFile), true);
if (!is_array($schema)) fail('Schema JSON invalid');
$doc = json_decode(file_get_contents($exampleFile), true);
if (!is_array($doc)) fail('Example manifest JSON invalid');

$errors = [];

// Helper validations
$addErr = function(string $e) use (&$errors) { $errors[] = $e; };

$req = $schema['required'] ?? [];
foreach ($req as $k) {
    if (!array_key_exists($k, $doc)) { $addErr("Missing required field '$k'"); }
}

// schema_version const 2
if (($doc['schema_version'] ?? null) !== 2) { $addErr('schema_version must be 2'); }

// uid pattern
if (isset($doc['uid']) && !preg_match('/^[A-Za-z0-9._-]{4,120}$/', $doc['uid'])) { $addErr('uid pattern mismatch'); }

// created_utc pattern
if (isset($doc['created_utc']) && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $doc['created_utc'])) { $addErr('created_utc pattern mismatch'); }

// tags uniqueness & pattern
if (isset($doc['tags'])) {
    if (!is_array($doc['tags'])) { $addErr('tags must be array'); }
    else {
        $seen = [];
        foreach ($doc['tags'] as $t) {
            if (!is_string($t) || $t === '') $addErr('tag must be non-empty string');
            if (!preg_match('/^[A-Za-z0-9._:-]+$/', $t)) $addErr("tag '$t' pattern invalid");
            if (isset($seen[$t])) $addErr("duplicate tag '$t'");
            $seen[$t] = true;
        }
    }
}

// files array
if (!isset($doc['files']) || !is_array($doc['files']) || count($doc['files']) < 1) { $addErr('files must be non-empty array'); }
else {
    foreach ($doc['files'] as $i => $f) {
        if (!is_array($f)) { $addErr("files[$i] must be object"); continue; }
        foreach (['name','size_bytes','compressed'] as $fk) if (!array_key_exists($fk,$f)) $addErr("files[$i].$fk missing");
        if (isset($f['size_bytes']) && (!is_int($f['size_bytes']) || $f['size_bytes'] < 0)) $addErr("files[$i].size_bytes invalid");
        if (isset($f['compressed']) && !is_bool($f['compressed'])) $addErr("files[$i].compressed must be bool");
        if (($f['compressed'] ?? false) === true && empty($f['compression_algo'])) $addErr("files[$i] compressed true but compression_algo missing");
        if (($f['compressed'] ?? false) === false && isset($f['compression_algo'])) $addErr("files[$i] compression_algo present but compressed false");
    }
}

// checksums
if (!isset($doc['checksums']) || !is_array($doc['checksums'])) { $addErr('checksums object missing'); }
else {
    if (($doc['checksums']['algo'] ?? '') !== 'sha256') $addErr('checksums.algo must be sha256');
    if (!isset($doc['checksums']['files']) || !is_array($doc['checksums']['files'])) $addErr('checksums.files missing or not object');
    else {
        foreach ($doc['checksums']['files'] as $fname => $digest) {
            if (!preg_match('/^[a-f0-9]{64}$/', $digest)) $addErr("checksum for $fname invalid hex");
        }
    }
}

// compression conditional
if (isset($doc['compression'])) {
    $c = $doc['compression'];
    if (!is_array($c)) $addErr('compression must be object');
    else {
        if (!array_key_exists('enabled',$c)) $addErr('compression.enabled missing');
        else if (!is_bool($c['enabled'])) $addErr('compression.enabled must be bool');
        if (($c['enabled']??false)===true) {
            foreach (['algo','original_size_bytes','compressed_size_bytes','ratio'] as $ck) if(!array_key_exists($ck,$c)) $addErr("compression.$ck required when enabled true");
        } else {
            foreach (['algo','original_size_bytes','compressed_size_bytes','ratio'] as $ck) if(array_key_exists($ck,$c)) $addErr("compression.$ck must be omitted when enabled false");
        }
    }
}

// provenance
if (!isset($doc['provenance']) || !is_array($doc['provenance'])) $addErr('provenance missing');
else {
    foreach (['command_line','host','user','php_version'] as $pk) if(empty($doc['provenance'][$pk])) $addErr("provenance.$pk missing");
}

// db optional completeness
if (isset($doc['db'])) {
    if (!is_array($doc['db'])) $addErr('db must be object');
    else {
        if (empty($doc['db']['engine'])) $addErr('db.engine missing');
        if (empty($doc['db']['version'])) $addErr('db.version missing');
    }
}

if (isset($doc['size_total_bytes']) && (!is_int($doc['size_total_bytes']) || $doc['size_total_bytes'] < 0)) $addErr('size_total_bytes invalid');

if ($errors) {
    foreach ($errors as $e) fwrite(STDERR, " - $e\n");
    fail('Validation failed with '.count($errors).' error(s).');
}

info('Manifest example PASSED validation against subset rules.');
// Explicit PASS line for CI grepping
fwrite(STDOUT, "PASS\n");
exit(0);

