<?php
/**
 * tsnap cli implementation.
 * Usage executed via bin/tsnap wrapper.
 */

require_once __DIR__ . '/s3_storage.php';

function tsnap_usage($exitCode = 0) {
    $msg = "Tsnap - simple object storage (S3/MinIO) helper\n\n" .
        "Commands:\n" .
        "  list [--prefix=STR] [--limit=N] [--local|-l] [--full-message] [--local-only] [--remote-only]\n" .
        "  fetch [--prefix=STR] [--limit=N]\n" .
        "  get <key> [--output=/path/file]\n" .
        "  cat <key>\n" .
        "  snap [--type=TYPE] [-m MESSAGE]\n" .
        "  publish <UID|prefix>  (or publish --snap=UID)\n" .
        "  help\n\n" .
        "Listing shows local snapshots plus remote object cache (.snappy) unless suppressed. Use 'tsnap fetch' to update remote cache.\n" .
        "You can abbreviate a snapshot UID to a unique prefix (like git).\n" .
        "Environment: SNAPPY_S3_ENDPOINT, SNAPPY_S3_REGION, SNAPPY_S3_BUCKET, SNAPPY_S3_KEY, SNAPPY_S3_SECRET, NORMAL_BACKUP_LOCATION, TDB_BACKUP_PATH\n";
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
    // First 10 hex chars random (improves aliasing); first 2 still used for sharding.
    $random10 = bin2hex(random_bytes(5)); // 10 hex chars
    $suffix = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)),0,8);
    return $random10 . $suffix;
}
// Helper: directory path for a given UID (new sharded layout). Falls back to legacy layout detection when reading.
function tsnap_uid_dir($uid) {
    $root = tsnap_backup_root();
    $prefix = substr($uid,0,2);
    return $root . '/' . $prefix . '/' . $uid;
}
// Added helper to detect db name without spawning shell with fragile quoting.
function tsnap_detect_dbname() {
    $dir = getcwd();
    $limit = 10; // avoid infinite loop
    while ($limit-- > 0 && $dir && $dir !== '/' ) {
        $cfg = $dir . '/config.php';
        if (is_file($cfg)) {
            $contents = @file_get_contents($cfg);
            if ($contents !== false) {
                if (preg_match('/\$CFG->dbname\s*=\s*[\'\"]([^\'\";]+)/', $contents, $m)) {
                    return trim($m[1]);
                }
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) { break; }
        $dir = $parent;
    }
    return '';
}
function tsnap_backup_root() {
    $d = getenv('NORMAL_BACKUP_LOCATION');
    if (!$d) { $d = getenv('HOME') . '/.tsnap/snaps'; }
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    return rtrim($d,'/');
}
function tsnap_open_editor_template($template) {
    // Priority order:
    // 1. TSNAP_MESSAGE env var (multi-line allowed)
    // 2. External editor attached to /dev/tty if available
    // 3. Simple one-line prompt via /dev/tty
    // 4. Abort
    $processMessage = function($content) {
        if ($content === false || $content === null) { return ''; }
        $lines = preg_split('/\r?\n/', $content);
        $filtered = array();
        foreach ($lines as $ln) {
            // Skip editor instruction comments
            if (preg_match('/^\s*#/', $ln)) { continue; }
            $filtered[] = rtrim($ln, "\r");
        }
        // Trim leading & trailing blank lines
        while (count($filtered) && trim($filtered[0]) === '') { array_shift($filtered); }
        while (count($filtered) && trim($filtered[count($filtered)-1]) === '') { array_pop($filtered); }
        $msg = implode("\n", $filtered);
        return trim($msg) === '' ? '' : $msg;
    };

    $envMsg = getenv('TSNAP_MESSAGE');
    if ($envMsg) {
        $msg = $processMessage($envMsg);
        return $msg; // may be '' -> caller will handle emptiness
    }

    $hasPosix = function_exists('posix_isatty');
    $stdinTTY = $hasPosix ? @posix_isatty(STDIN) : false;
    $stdoutTTY = $hasPosix ? @posix_isatty(STDOUT) : false;
    $devTtyAvailable = is_readable('/dev/tty') && is_writable('/dev/tty');
    $interactive = ($stdinTTY && $stdoutTTY) || $devTtyAvailable;

    if ($interactive) {
        $editor = getenv('EDITOR');
        if (!$editor) { $editor = 'vi'; }
        $tmp = tempnam(sys_get_temp_dir(), 'tsnapmsg');
        file_put_contents($tmp, $template);
        $cmd = escapeshellcmd($editor) . ' ' . escapeshellarg($tmp);
        if ($devTtyAvailable) { $cmd .= ' </dev/tty >/dev/tty 2>&1'; }
        $rc = 0;
        system($cmd, $rc);
        $content = @file_get_contents($tmp);
        @unlink($tmp);
        $msg = $processMessage($content);
        if ($rc === 0 && $msg !== '') { return $msg; }
        // Fallback prompt
        if ($devTtyAvailable) {
            $fh = @fopen('/dev/tty', 'r');
            if ($fh) {
                fwrite(STDERR, "Enter snapshot message (finish with ENTER): ");
                $line = fgets($fh);
                fclose($fh);
                $msg = $processMessage($line);
                return $msg;
            }
        }
    }

    fwrite(STDERR, "Non-interactive session: provide a message with -m or set TSNAP_MESSAGE env var.\n");
    return '';
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
function tsnap_list_local($showFullMessage = false) {
    $uids = tsnap_all_local_uids();
    if (!$uids) { echo "(no local snapshots)\n"; return; }
    $rows = array();
    foreach ($uids as $uid) {
        $snapDir = tsnap_uid_dir($uid);
        if (!is_dir($snapDir)) {
            $legacy = tsnap_backup_root() . '/' . $uid;
            $snapDir = is_dir($legacy) ? $legacy : $snapDir;
        }
        $meta = tsnap_read_meta($snapDir);
        if (!$meta) continue;
        $msg = isset($meta['message']) ? $meta['message'] : '';
        if (!$showFullMessage) {
            // Only first line
            $first = preg_split('/\r?\n/', $msg, 2);
            $msg = isset($first[0]) ? $first[0] : '';
        } else {
            // Collapse newlines to visible separator for table
            $msg = preg_replace('/\r?\n+/', ' | ', $msg);
        }
        $rows[] = array(
            'uid'=>$uid,
            'created'=>isset($meta['created'])?$meta['created']:'',
            'type'=>isset($meta['type'])?$meta['type']:'',
            'message'=>$msg,
        );
    }
    if (!$rows) { echo "(no local snapshots)\n"; return; }
    usort($rows, function($a,$b){ return strcmp($b['created'],$a['created']); });
    $wUid=3;$wCreated=7;$wType=4;
    foreach ($rows as $r){ $wUid=max($wUid,strlen($r['uid'])); $wCreated=max($wCreated,strlen($r['created'])); $wType=max($wType,strlen($r['type'])); }
    printf("%-{$wUid}s  %-{$wCreated}s  %-{$wType}s  %s\n","UID","CREATED","TYPE","MESSAGE");
    foreach ($rows as $r){ $msg=$r['message']; if (strlen($msg)>120) $msg=substr($msg,0,117).'...'; printf("%-{$wUid}s  %-{$wCreated}s  %-{$wType}s  %s\n",$r['uid'],$r['created'],$r['type'],$msg); }
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
    $snapDir = tsnap_uid_dir($uid);
    if (!is_dir($snapDir) && !mkdir($snapDir, 0777, true)) { fwrite(STDERR, "Failed to create snapshot dir $snapDir\n"); exit(1); }
    $meta = array(
        'uid' => $uid,
        'created' => date('c'),
        'type' => $type,
        'message' => '',
        'files' => array(),
        'file_checksums' => array(), // new: filename => sha256
    );
    if ($type === 'sql') {
        $dbname = tsnap_detect_dbname();
        $tdbPath = __DIR__ . '/../../tdb';
        if ($dbname) {
            $cmd = escapeshellcmd($tdbPath) . ' backup ' . escapeshellarg($dbname) . ' ' . escapeshellarg($uid) . ' > /dev/null 2>&1';
        } else {
            $cmd = escapeshellcmd($tdbPath) . ' backup > /dev/null 2>&1';
        }
        system($cmd);
        $defaultPath = getenv('TDB_BACKUP_PATH');
        if (!$defaultPath) { $defaultPath = getenv('HOME') . '/tdb_backups'; }
        $candidate = '';
        if ($dbname && is_dir($defaultPath)) {
            $matches = glob($defaultPath . '/' . $uid . '.*');
            if ($matches) { $candidate = $matches[0]; }
        }
        if (!$candidate) {
            $candidates = array();
            if (is_dir($defaultPath)) {
                foreach (glob($defaultPath.'/*') as $f) { if (is_file($f)) { $candidates[$f]=filemtime($f); } }
            }
            arsort($candidates); $candidate = key($candidates);
        }
        if (!$candidate || !is_file($candidate)) { fwrite(STDERR, "Could not locate created DB backup.\n"); exit(2); }
        $backupFile = $snapDir . '/backup.sql';
        copy($candidate, $backupFile);
        $meta['files'][] = basename($backupFile);
        $meta['file_checksums'][basename($backupFile)] = hash_file('sha256', $backupFile); // record checksum
    } else {
        fwrite(STDERR, "Unknown snap type: $type\n");
        exit(3);
    }
    if (!$message) {
        $tpl = "\n\n\n# Enter a snapshot message (multi-line allowed; lines starting with # are ignored)\n# Empty or comment-only messages will abort.\n# Snapshot UID: $uid\n";
        $message = tsnap_open_editor_template($tpl);
        if (!$message) { fwrite(STDERR, "Snapshot message required. Use -m or TSNAP_MESSAGE in non-interactive environments.\n"); exit(4); }
    }
    $meta['message'] = $message;
    tsnap_write_meta($snapDir, $meta);
    echo "Created snapshot $uid at $snapDir\n";
    exit(0);
}

function tsnap_all_local_uids() {
    $root = tsnap_backup_root();
    $uids = array();
    if (!is_dir($root)) { return $uids; }
    $entries = @scandir($root);
    if (!$entries) { return $uids; }
    foreach ($entries as $e) {
        if ($e==='.'||$e==='..') continue;
        $path = $root . '/' . $e;
        if (is_dir($path)) {
            // New layout: two-char shard directories
            if (strlen($e) === 2) {
                $inner = @scandir($path);
                if ($inner) {
                    foreach ($inner as $d) {
                        if ($d==='.'||$d==='..') continue;
                        $snapPath = $path . '/' . $d;
                        if (is_dir($snapPath) && is_file($snapPath . '/meta.json')) { $uids[] = $d; }
                    }
                }
            } else {
                // Legacy layout: snapshot dirs directly under root
                if (is_file($path . '/meta.json')) { $uids[] = $e; }
            }
        }
    }
    return $uids;
}
function tsnap_resolve_uid($partial) {
    $partial = trim($partial);
    if ($partial==='') { return ''; }
    $uids = tsnap_all_local_uids();
    // Exact match first
    foreach ($uids as $u) { if ($u === $partial) { return $u; } }
    $matches = array();
    foreach ($uids as $u) { if (strpos($u, $partial) === 0) { $matches[] = $u; } }
    if (count($matches) === 1) { return $matches[0]; }
    if (count($matches) === 0) { fwrite(STDERR, "No snapshot matches prefix '$partial'\n"); return ''; }
    // Ambiguous
    fwrite(STDERR, "Ambiguous snapshot prefix '$partial' (" . count($matches) . " matches):\n");
    foreach ($matches as $m) { fwrite(STDERR, "  $m\n"); }
    return '';
}

if ($command === 'publish') {
    $uid = '';
    // Support either: publish <uid/prefix> OR publish --snap=UID
    if (!empty($argv)) {
        foreach ($argv as $arg) {
            if ($arg === '') { continue; }
            if (strpos($arg, '--snap=') === 0) {
                $uid = substr($arg, 7);
            } elseif ($arg[0] !== '-') { // treat first non-option token as uid/prefix
                if ($uid==='') { $uid = $arg; }
            }
        }
    }
    if ($uid === '') { fwrite(STDERR, "Snapshot UID or prefix required (publish <UID|prefix>)\n"); exit(1); }
    $resolved = tsnap_resolve_uid($uid);
    if ($resolved === '') { exit(1); }
    $uid = $resolved;
    $snapDir = tsnap_uid_dir($uid);
    if (!is_dir($snapDir)) { // legacy fallback
        $legacy = tsnap_backup_root() . '/' . $uid;
        if (is_dir($legacy)) { $snapDir = $legacy; }
    }
    if (!is_dir($snapDir)) { fwrite(STDERR, "Snapshot directory not found: $snapDir\n"); exit(2); }
    $meta = tsnap_read_meta($snapDir);
    if (!$meta) { fwrite(STDERR, "Missing meta.json in snapshot $uid\n"); exit(3); }
    $required = array('uid','created','type','message');
    foreach ($required as $k) { if (!isset($meta[$k]) || $meta[$k]==='') { fwrite(STDERR, "Invalid metadata, missing $k\n"); exit(4); } }
    if (isset($meta['file_checksums']) && is_array($meta['file_checksums'])) {
        foreach ($meta['file_checksums'] as $fname => $expected) {
            $localPath = rtrim($snapDir,'/') . '/' . $fname;
            if (is_file($localPath)) {
                $actual = hash_file('sha256', $localPath);
                if ($actual !== $expected) {
                    fwrite(STDERR, "Checksum mismatch for $fname (expected $expected got $actual). Aborting publish.\n");
                    exit(6);
                }
            } else {
                fwrite(STDERR, "Missing file listed in metadata: $fname. Aborting publish.\n");
                exit(6);
            }
        }
    }
    try {
        $keys = $storage->upload($snapDir, $uid);
    } catch (Exception $e) {
        fwrite(STDERR, "Publish failed: " . $e->getMessage() . "\n");
        exit(5);
    }
    echo "Published snapshot $uid (" . count($keys) . " objects).\n";
    // Invalidate remote caches so subsequent list reflects new snapshot
    @unlink(tsnap_remote_cache_file(''));
    @unlink(tsnap_remote_cache_file('snaps'));
    exit(0);
}

// LIST command
if ($command === 'list') {
    $prefix = '';
    $limit = 100;
    $fullMsg = false;
    $localOnly = false;
    $remoteOnly = false;
    foreach ($argv as $arg) {
        if ($arg==='--full-message' || $arg==='-F') { $fullMsg = true; }
        elseif ($arg==='--local-only') { $localOnly = true; }
        elseif ($arg==='--remote-only') { $remoteOnly = true; }
        elseif (strpos($arg, '--prefix=') === 0) { $prefix = substr($arg, 9); }
        elseif (strpos($arg, '--limit=') === 0) { $limit = (int)substr($arg, 8); }
        else { fwrite(STDERR, "Unknown option: $arg\n"); tsnap_usage(1); }
    }
    if ($remoteOnly) { $localOnly = false; }

    if (!$remoteOnly) {
        echo "Local snapshots:\n";
        tsnap_list_local($fullMsg);
        echo "\n";
    }
    if ($localOnly) { exit(0); }

    // Remote snapshot summary (using object cache & remote meta cache)
    $cache = tsnap_load_remote_cache($prefix);
    $fetched = false;
    if ($cache === null) {
        try {
            $objects = $storage->list_objects($prefix, $limit);
            $cache = tsnap_save_remote_cache($prefix, $objects, $limit);
            $fetched = true;
        } catch (Exception $e) {
            fwrite(STDERR, "Remote list failed and no cache present: " . $e->getMessage() . "\n");
            exit(3);
        }
    }
    $age = isset($cache['retrieved_at']) ? tsnap_human_age($cache['retrieved_at']) : 'unknown';
    echo "Remote snapshots (" . ($fetched ? 'fresh' : 'cached ' . $age) . ") prefix='" . ($prefix?:'/') . "' limit=" . $limit . ":\n";

    $objs = isset($cache['objects']) && is_array($cache['objects']) ? $cache['objects'] : array();
    if (!$objs) { echo "(none)\n"; exit(0); }

    // Build map of snapshot UID => metadata info
    $remoteSnapshots = array();
    $localUIDs = array_flip(tsnap_all_local_uids());

    // Patterns: current format under snaps/UID/ , legacy root UID/
    $patternCurrent = '#^snaps/([^/]+)/meta\.json$#';
    $patternLegacy = '#^([a-f0-9]{10}\d{8}-\d{6}-[a-f0-9]{8})/meta\.json$#i';

    foreach ($objs as $o) {
        $k = $o['key'];
        $lm = $o['last_modified'];
        $uid = null;
        if (preg_match($patternCurrent, $k, $m)) {
            $uid = $m[1];
        } elseif (preg_match($patternLegacy, $k, $m)) {
            $uid = $m[1];
        }
        if ($uid === null) { continue; }
        $remoteSnapshots[$uid] = array(
            'meta_last_modified' => $lm,
            'uid' => $uid,
            'has_local' => isset($localUIDs[$uid]),
            'meta' => null,
            'stale' => false,
        );
    }

    if (empty($remoteSnapshots)) {
        echo "(no remote snapshots)\n";
        exit(0);
    }

    // Load or fetch each meta.json only if missing or outdated
    foreach ($remoteSnapshots as $uid => &$info) {
        $cachedMeta = tsnap_load_remote_meta($uid);
        if ($cachedMeta && isset($cachedMeta['meta_last_modified']) && $cachedMeta['meta_last_modified'] === $info['meta_last_modified']) {
            $info['meta'] = isset($cachedMeta['meta']) ? $cachedMeta['meta'] : null;
        } else {
            // Download meta.json
            $remoteKey = 'snaps/' . $uid . '/meta.json';
            // Try legacy key if current key missing from listing pattern
            if (!preg_match($patternCurrent, $remoteKey)) { $remoteKey = $uid . '/meta.json'; }
            try {
                $json = $storage->read_object($remoteKey);
                $metaArr = @json_decode($json, true);
                if (is_array($metaArr)) {
                    tsnap_save_remote_meta($uid, $info['meta_last_modified'], $metaArr);
                    $info['meta'] = $metaArr;
                }
            } catch (Exception $e) {
                // Leave meta null; continue
            }
        }
    }
    unset($info);

    // Prepare display rows similar to local
    $rows = array();
    foreach ($remoteSnapshots as $uid => $info) {
        $meta = $info['meta'];
        $created = ($meta && isset($meta['created'])) ? $meta['created'] : $info['meta_last_modified'];
        $type = ($meta && isset($meta['type'])) ? $meta['type'] : '?';
        $message = ($meta && isset($meta['message'])) ? $meta['message'] : '';
        if (!$fullMsg) {
            $first = preg_split('/\r?\n/', $message, 2);
            $message = isset($first[0]) ? $first[0] : '';
        } else {
            $message = preg_replace('/\r?\n+/', ' | ', $message);
        }
        $rows[] = array(
            'uid' => $uid,
            'created' => $created,
            'type' => $type,
            'message' => $message,
            'local' => $info['has_local'] ? 'yes' : '',
        );
    }
    // Sort desc by created
    usort($rows, function($a,$b){ return strcmp($b['created'],$a['created']); });

    $wUid=3;$wCreated=7;$wType=4;$wLocal=5;
    foreach ($rows as $r) {
        $wUid=max($wUid,strlen($r['uid']));
        $wCreated=max($wCreated,strlen($r['created']));
        $wType=max($wType,strlen($r['type']));
    }
    printf("%-{$wUid}s  %-{$wCreated}s  %-{$wType}s  %-{$wLocal}s  %s\n","UID","CREATED","TYPE","LOCAL","MESSAGE");
    foreach ($rows as $r) {
        $msg=$r['message']; if (strlen($msg)>120) $msg=substr($msg,0,117).'...';
        printf("%-{$wUid}s  %-{$wCreated}s  %-{$wType}s  %-{$wLocal}s  %s\n", $r['uid'], $r['created'], $r['type'], $r['local'], $msg);
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

// FETCH command
if ($command === 'fetch') {
    $prefix = '';
    $limit = 100;
    foreach ($argv as $arg) {
        if (strpos($arg,'--prefix=')===0) { $prefix = substr($arg,9); }
        elseif (strpos($arg,'--limit=')===0) { $limit = (int)substr($arg,8); }
        else { fwrite(STDERR, "Unknown option: $arg\n"); tsnap_usage(1); }
    }
    try {
        $objects = $storage->list_objects($prefix, $limit);
        $cache = tsnap_save_remote_cache($prefix, $objects, $limit);
    } catch (Exception $e) {
        fwrite(STDERR, "Fetch failed: " . $e->getMessage() . "\n");
        exit(3);
    }
    $count = isset($cache['objects']) ? count($cache['objects']) : 0;
    echo "Fetched $count remote objects for prefix '" . ($prefix?:'/') . "' (limit=$limit).\n";
    echo "Cache file: " . tsnap_remote_cache_file($prefix) . "\n";
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
function tsnap_cache_dir() {
    $root = tsnap_backup_root(); // e.g. ~/.tsnap/snaps
    $dir = $root . '/.snappy';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}
function tsnap_remote_cache_file($prefix) {
    $safe = $prefix === '' ? 'root' : preg_replace('/[^A-Za-z0-9._-]/','_', $prefix);
    return tsnap_cache_dir() . '/remote_' . $safe . '.json';
}
function tsnap_load_remote_cache($prefix) {
    $file = tsnap_remote_cache_file($prefix);
    if (!is_file($file)) { return null; }
    $data = @json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
function tsnap_save_remote_cache($prefix, $objects, $limit) {
    $file = tsnap_remote_cache_file($prefix);
    $payload = array(
        'retrieved_at' => date('c'),
        'prefix' => $prefix,
        'limit' => $limit,
        'objects' => $objects,
    );
    @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
    return $payload;
}
function tsnap_human_age($iso) {
    $t = strtotime($iso);
    if (!$t) return 'unknown';
    $diff = time() - $t;
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

function tsnap_remote_meta_cache_dir() {
    $dir = tsnap_cache_dir() . '/remote_meta';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}
function tsnap_remote_meta_cache_file($uid) {
    return tsnap_remote_meta_cache_dir() . '/' . $uid . '.json';
}
function tsnap_load_remote_meta($uid) {
    $f = tsnap_remote_meta_cache_file($uid);
    if (!is_file($f)) { return null; }
    $data = @json_decode(@file_get_contents($f), true);
    return is_array($data) ? $data : null;
}
function tsnap_save_remote_meta($uid, $lastModified, $metaArr) {
    $payload = array(
        'uid' => $uid,
        'meta_last_modified' => $lastModified,
        'cached_at' => date('c'),
        'meta' => $metaArr,
    );
    @file_put_contents(tsnap_remote_meta_cache_file($uid), json_encode($payload, JSON_PRETTY_PRINT));
    return $payload;
}
