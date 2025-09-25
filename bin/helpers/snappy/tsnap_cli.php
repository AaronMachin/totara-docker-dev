<?php
/**
 * tsnap cli implementation.
 * Usage executed via bin/tsnap wrapper.
 */

require_once __DIR__ . '/s3_storage.php';

function tsnap_usage($exitCode = 0) {
    $msg = "Tsnap - simple object storage (S3/MinIO) helper\n\n" .
        "Commands:\n" .
        "  list [--prefix=STR] [--limit=N]\n" .
        "  get <key> [--output=/path/file]\n" .
        "  cat <key>\n" .
        "  help\n\n" .
        "Environment: SNAPPY_S3_ENDPOINT, SNAPPY_S3_REGION, SNAPPY_S3_BUCKET, SNAPPY_S3_KEY, SNAPPY_S3_SECRET, SNAPPY_S3_PATH_STYLE\n";
    fwrite($exitCode ? STDERR : STDOUT, $msg);
    exit($exitCode);
}

$argv0 = array_shift($argv); // script path
$command = isset($argv[0]) ? $argv[0] : 'help';
if ($command === 'help' || $command === '-h' || $command === '--help') {
    tsnap_usage();
}
if (!$command) { tsnap_usage(1); }
array_shift($argv); // remove command

try {
    $storage = new s3_storage();
} catch (Exception $e) {
    fwrite(STDERR, "Config error: " . $e->getMessage() . "\n");
    exit(2);
}

if ($command === 'list') {
    $prefix = '';
    $limit = 100;
    foreach ($argv as $arg) {
        if (strpos($arg, '--prefix=') === 0) { $prefix = substr($arg, 9); }
        else if (strpos($arg, '--limit=') === 0) { $limit = (int)substr($arg, 8); }
        else { fwrite(STDERR, "Unknown option: $arg\n"); tsnap_usage(1); }
    }
    try {
        $objects = $storage->list_objects($prefix, $limit);
    } catch (Exception $e) {
        fwrite(STDERR, "List failed: " . $e->getMessage() . "\n");
        exit(3);
    }
    if (!$objects) {
        echo "(no objects)\n";
        exit(0);
    }
    // Calculate column widths
    $wKey = 3; $wSize = 4; $wDate = 12;
    foreach ($objects as $o) {
        $wKey = max($wKey, strlen($o['key']));
        $wSize = max($wSize, strlen(tsnap_human_size($o['size'])));
        $wDate = max($wDate, strlen($o['last_modified']));
    }
    printf("%-{$wKey}s  %{$wSize}s  %-{$wDate}s\n", 'KEY', 'SIZE', 'LAST MODIFIED');
    foreach ($objects as $o) {
        printf("%-{$wKey}s  %{$wSize}s  %-{$wDate}s\n", $o['key'], tsnap_human_size($o['size']), $o['last_modified']);
    }
    exit(0);
}

if ($command === 'cat') {
    if (empty($argv)) { fwrite(STDERR, "Key required.\n"); tsnap_usage(1); }
    $key = $argv[0];
    try {
        $data = $storage->read_object($key);
    } catch (Exception $e) {
        fwrite(STDERR, "Read failed: " . $e->getMessage() . "\n");
        exit(4);
    }
    echo $data;
    exit(0);
}

if ($command === 'get') {
    if (empty($argv)) { fwrite(STDERR, "Key required.\n"); tsnap_usage(1); }
    $key = $argv[0];
    $output = '';
    for ($i = 1; $i < count($argv); $i++) {
        if (strpos($argv[$i], '--output=') === 0) { $output = substr($argv[$i], 9); }
        else { fwrite(STDERR, "Unknown option: " . $argv[$i] . "\n"); tsnap_usage(1); }
    }
    if ($output === '') { $output = basename($key); }
    $dir = dirname($output);
    if ($dir && !is_dir($dir)) {
        fwrite(STDERR, "Directory does not exist: $dir\n");
        exit(5);
    }
    try {
        $storage->get_object($key, $output);
    } catch (Exception $e) {
        fwrite(STDERR, "Download failed: " . $e->getMessage() . "\n");
        exit(6);
    }
    echo "Saved to $output\n";
    exit(0);
}

fwrite(STDERR, "Unknown command: $command\n");
tsnap_usage(1);

function tsnap_human_size($bytes) {
    $units = array('B','KB','MB','GB','TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? $bytes : sprintf('%.2f', $bytes)) . $units[$i];
}

