Snappy Snapshot Service
=======================

Overview
--------
Snappy is a lightweight, git‑inspired snapshot manager for developer databases (and future pluggable asset types). It lets you:

- Create local snapshots with messages (similar to committing with a message).
- Store snapshot metadata (meta.json) plus files (e.g. database dump) in a sharded local store.
- Publish snapshots to S3 / S3‑compatible remotes for team sharing.
- List local & remote snapshots quickly via cached remote object / meta manifests.
- Verify integrity by per‑file SHA256 checksums prior to publish.

Non‑Goals: full VCS replacement, binary diffing, large object dedupe (future exploration possible).

Key Principles
--------------
1. Familiar mental model (snap -> publish, like commit -> push).
2. Zero external PHP dependencies (pure PHP + curl) for portability.
3. Deterministic layout for easy manual inspection / scripting.
4. Fast UX: remote listings are cached locally, only refreshed on demand (fetch) or publish invalidation.
5. Extensible command architecture: each CLI subcommand is an isolated class.

Directory Layout
----------------
```
bin/helpers/snappy/
  tsnap_cli.php              (front controller / dispatcher)
  snappy_autoload.php        (simple PSR‑like autoloader – lowercase path mapping)
  src/
    cli/
      command.php            (command interface)
      context.php            (shared runtime context: storage, manager, cache)
      snap.php               (snap create command)
      publish.php            (publish command)
      listing.php            (list command)
      fetch.php              (fetch remote cache command)
      cat.php                (show remote object contents)
      get.php                (download remote object)
      help.php (planned)     (dynamic help / command registry output)
    snapshot/
      snapshot_manager.php   (high‑level orchestration: create, list local/remote, publish)
      remote_cache.php       (remote object + meta caching)
    storage/
      storage.php            (storage interface)
      s3_storage.php         (S3 / MinIO implementation – AWS SigV4 signing)
    util/
      uid.php                (UID generation + local enumeration + prefix resolution)
      editor.php             (multi‑line message acquisition)
      time.php               (human age formatting)
      env.php                (basic env accessor – may expand later)
```

Snapshot Layout
---------------
Local (sharded by first 2 hex chars of UID):
```
<root>/<shard>/<uid>/meta.json
<root>/<shard>/<uid>/backup.sql   (for type=sql)
```

Remote (published):
```
snaps/<uid>/meta.json
snaps/<uid>/<files...>
```

Caching
-------
```
<root>/.snappy/remote_<prefix>.json    (ListObjects cache for a prefix)
<root>/.snappy/remote_meta/<uid>.json  (Cached meta.json + last_modified for validation)
```
Invalidated on successful publish.

Environment Variables
---------------------
(All mandatory without legacy fallbacks.)
- SNAPPY_S3_ENDPOINT      e.g. https://s3.amazonaws.com or http://minio:9000
- SNAPPY_S3_REGION        e.g. us-east-1
- SNAPPY_S3_BUCKET        bucket name
- SNAPPY_S3_KEY           access key
- SNAPPY_S3_SECRET        secret key
- SNAPPY_S3_PATH_STYLE    (optional) set 1/true to force path‑style addressing
- SNAPPY_SNAPSHOT_ROOT    local snapshot root (default: $HOME/.snappy/snaps)
- SNAPPY_TDB_BACKUP_PATH  where the external `tdb backup` writes dumps (default: $HOME/tdb_backups)
- SNAPPY_MESSAGE          non‑interactive snapshot message
- SNAPPY_DEBUG            enable verbose S3 request logging (1 / true)

Commands (Modular)
------------------
Each subcommand = class implementing Snappy\Cli\command with run($args, context $ctx): int.

Current set:
- snap      Create a local snapshot (type=sql now; future types pluggable)
- publish   Publish a snapshot by full UID or unique prefix
- list      List local then remote snapshots (supports --full-message, --local-only, --remote-only, --prefix=, --limit=)
- fetch     Refresh cached remote object listing (prefix + limit)
- cat       Output a remote object to STDOUT
- get       Download a remote object (--output=path)
- help      (Will render dynamic list – placeholder until implemented)

Adding a Command
----------------
1. Create new file `src/cli/<name>.php` implementing Snappy\Cli\command.
2. Register it in tsnap_cli.php $registry (or dynamic loader in future).
3. (Optional) Add to help.php to display description.

Adding a Snapshot Type
----------------------
1. Extend snapshot_manager::create(): branch on $type and implement handler (similar to create_sql_backup()).
2. Add files into snapshot directory; append to `files` and `file_checksums` in meta before write.
3. (Optional) Surface new type(s) in CLI help.

Meta Format (meta.json)
-----------------------
```
{
  "uid": "<string>",
  "created": "ISO-8601",
  "type": "sql",
  "message": "<user message>",
  "files": ["backup.sql"],
  "file_checksums": {"backup.sql": "<sha256>"}
}
```

Integrity & Safety
------------------
Publish performs full checksum verification; mismatch aborts (no partial publish). Remote cache invalidated only after successful upload.

Extensibility Roadmap
---------------------
Short term:
- help command implementation (dynamic command discovery)
- Basic PHPUnit tests for: UID resolution, cache reuse, checksum enforcement
- Additional snapshot types (e.g. file tree, config bundle)

Medium term:
- Pluggable compression/encryption layers (opt‑in)
- Remote pruning / GC utility
- Parallel multipart uploads for large artifacts

Long term / exploratory:
- Incremental / differential snapshots
- Optional content addressable object store for dedupe

Testing
-------
(No test harness committed yet). Suggested: introduce dev dependency on phpunit and cover snapshot_manager + command classes.

License
-------
See root LICENCE.
