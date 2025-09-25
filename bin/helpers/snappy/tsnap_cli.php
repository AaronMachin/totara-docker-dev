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
        "  help\n" .
        "  snap [--type=TYPE] [-m MESSAGE]\n" .
        "  publish --snap=UID\n\n" .
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

function tsnap_generate_uid() {
    return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)),0,8);
}
function tsnap_backup_root() {
    $d = getenv('NORMAL_BACKUP_LOCATION');
    if (!$d) { $d = getenv('HOME') . '/.tsnap/snaps'; }
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    return rtrim($d,'/');
}
function tsnap_open_editor_template($template) {
    $editor = getenv('EDITOR');
    if (!$editor) { $editor = 'vi'; }
    $tmp = tempnam(sys_get_temp_dir(), 'tsnapmsg');
    file_put_contents($tmp, $template);
    system(escapeshellcmd($editor) . ' ' . escapeshellarg($tmp));
    $content = file_get_contents($tmp);
    unlink($tmp);
    $lines = array();
    foreach (preg_split('/\r?\n/', $content) as $line) {
        if (preg_match('/^\s*#/', $line)) { continue; }
        if (trim($line) === '') { continue; }
        $lines[] = rtrim($line); break; // Only first non-comment, non-empty line like git
    }
    return implode("\n", $lines);
}
function tsnap_write_meta($dir, $meta) {
    file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));
}
function tsnap_read_meta($dir) {
    $file = $dir . '/meta.json';
    if (!file_exists($file)) { return null; }
    $data = json_decode(file_get_contents($file), true);
    return $data;
}

// SNAP command
if ($command === 'snap') {
    $type = 'sql';
    $message = '';
    foreach ($argv as $i => $arg) {
        if (strpos($arg,'--type=')===0) { $type = substr($arg,7); }
        elseif ($arg==='-m' && isset($argv[$i+1])) { $message = $argv[$i+1]; $argv[$i+1]=''; }
    }
    $uid = tsnap_generate_uid();
    $root = tsnap_backup_root();
    $snapDir = $root . '/' . $uid;
    if (!mkdir($snapDir, 0777, true)) { fwrite(STDERR, "Failed to create snapshot dir $snapDir\n"); exit(1); }
    $meta = array(
        'uid' => $uid,
        'created' => date('c'),
        'type' => $type,
        'message' => '',
        'files' => array(),
    );
    if ($type === 'sql') {
        // Run tdb backup in current working directory (site root expected)
        $backupFile = $snapDir . '/backup.sql';
        // tdb decides backup path; we capture stdout file path by using a temp name.
        // Simplest: run tdb backup into our target path by symlinking or copying after.
        // We call tdb backup (no alias) then copy produced file from default location.
        $tdbPath = __DIR__ . '/../../tdb';
        $beforeFiles = glob(getenv('TDB_BACKUP_PATH') ? rtrim(getenv('TDB_BACKUP_PATH'),'/').'/*' : getenv('HOME').'/.*/tdb_backups/*');
        // Basic invocation to create backup (will choose default name). We ignore output.
        system(escapeshellcmd($tdbPath) . ' backup > /dev/null 2>&1');
        // Find newest backup file for sql (pgsql/mysql etc.)
        $candidates = array();
        $defaultPath = getenv('TDB_BACKUP_PATH');
        if (!$defaultPath) {
            $defaultPath = getenv('HOME') . '/tdb_backups';
        }
        if (is_dir($defaultPath)) {
            foreach (glob($defaultPath.'/*') as $f) { if (is_file($f)) { $candidates[$f]=filemtime($f); } }
        }
        arsort($candidates);
        $latest = key($candidates);
        if (!$latest) { fwrite(STDERR, "Could not locate created DB backup.\n"); exit(2); }
        copy($latest, $backupFile);
        $meta['files'][] = basename($backupFile);
    } else {
        fwrite(STDERR, "Unknown snap type: $type\n");
        exit(3);
    }
    if (!$message) {
        $tpl = "# Enter a snapshot message (first line only will be used)\n# Lines starting with # are ignored.\n# Snapshot UID: $uid\n";
        $message = tsnap_open_editor_template($tpl);
        if (!$message) { fwrite(STDERR, "Aborting empty snapshot message.\n"); exit(4); }
    }
    $meta['message'] = $message;
    tsnap_write_meta($snapDir, $meta);
    echo "Created snapshot $uid at $snapDir\n";
    exit(0);
}

if ($command === 'publish') {
    $uid = '';
    foreach ($argv as $arg) { if (strpos($arg,'--snap=')===0) { $uid = substr($arg,7); } }
    if (!$uid) { fwrite(STDERR, "--snap=UID required\n"); exit(1); }
    $root = tsnap_backup_root();
    $snapDir = $root . '/' . $uid;
    if (!is_dir($snapDir)) { fwrite(STDERR, "Snapshot directory not found: $snapDir\n"); exit(2); }
    $meta = tsnap_read_meta($snapDir);
    if (!$meta) { fwrite(STDERR, "Missing meta.json in snapshot $uid\n"); exit(3); }
    $required = array('uid','created','type','message');
    foreach ($required as $k) { if (!isset($meta[$k]) || $meta[$k]==='') { fwrite(STDERR, "Invalid metadata, missing $k\n"); exit(4); } }
    // Upload directory contents under prefix snaps/UID
    try {
        $keys = $storage->upload($snapDir, 'snaps/'.$uid);
    } catch (Exception $e) {
        fwrite(STDERR, "Publish failed: " . $e->getMessage() . "\n");
        exit(5);
    }
    echo "Published snapshot $uid (" . count($keys) . " objects).\n";
    exit(0);
}

// LIST command
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

// CAT command
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

// GET command
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

