Snappy Snapshot Service
=======================

Overview
--------
Snappy is a lightweight, git‑inspired snapshot manager for developer databases (more asset types later). It provides:

- Create local snapshots with a message (snap = commit analogue).
- Push snapshots to one or more remotes (S3 / compatible) for sharing.
- Pull snapshots from remotes back into local storage.
- Simple pluggable storage interface (local filesystem, S3 implementation included).
- Per‑file SHA256 integrity verification before push and after pull.
- Git‑like multi‑remote management (remote add / list / remove).

Key Principles
--------------
1. Familiar mental model: snap -> push / pull (like commit -> push / pull).
2. Zero external PHP dependencies (pure PHP + curl).
3. Deterministic, inspectable on‑disk layout.
4. Clear, minimal command surface; only persistent state is config.json + snapshot files.
5. Extensible storage & snapshot types without large refactors.

Directory Layout
----------------
```
bin/helpers/snappy/
  tsnap_cli.php              (CLI front controller / dynamic command loader)
  snappy_autoload.php        (simple lowercase path autoloader)
  src/
    cli/
      command.php            (command interface)
      context.php            (registry + manager wiring)
      snap.php               (create snapshot)
      push.php               (push snapshot to remote)
      pull.php               (pull snapshot from remote)
      listing.php            (list snapshots on a remote or all)
      remote.php             (manage remotes & reload config)
      cat.php                (dump object contents)
      get.php                (download single object)
      fetch.php              (compat stub: forces a list scan on a remote)
      help.php               (dynamic help)
    snapshot/
      snapshot_manager.php   (create, list, push, pull, resolve)
      remote_registry.php    (multi‑remote registry + config.json persistence)
    storage/
      storage.php            (storage interface)
      local_storage.php      (filesystem implementation)
      s3_storage.php         (S3 / MinIO via SigV4)
    util/
      snapshot_uid.php       (UID generation and resolution)
      editor.php             (message acquisition)
      time.php               (time helpers)
      env.php                (future expansion)
```

Local Snapshot Layout
---------------------
Local snapshots live under a unified base directory:
```
$SNAPPY_SNAPSHOT_ROOT/snaps/<uid>/meta.json
$SNAPPY_SNAPSHOT_ROOT/snaps/<uid>/backup.sql   (type=sql)
```
Default base path: $HOME/.snappy

Remote Layout
-------------
```
snaps/<uid>/meta.json
snaps/<uid>/<files...>
```

Configuration File
------------------
Primary config state (remotes + options) stored at:
```
$SNAPPY_SNAPSHOT_ROOT/.snappy/config.json
```
Example config.json snippet:
```
{
  "version": 1,
  "remotes": {
    "local": {"type":"local","path":"/home/user/.snappy","created":"..."},
    "origin": {"type":"s3","config":{"endpoint":"https://s3.example","bucket":"mybucket","region":"us-east-1","key":"...","secret":"..."},"created":"..."}
  },
  "options": {"default_remote": "local"},
  "updated": "..."
}
```
Edit config.json manually then run:
```
snappy remote reload
```

Environment Variables
---------------------
(Only needed if not supplied when adding a remote.)
- SNAPPY_SNAPSHOT_ROOT   Base path (default: $HOME/.snappy)
- SNAPPY_TDB_BACKUP_PATH Path where `tdb backup <uid>` writes dumps (default: $HOME/tdb_backups)
- SNAPPY_DEBUG           1/true enables verbose S3 logging

Commands
--------
- snap        Create a local snapshot (currently type=sql)
- push        Push snapshot (full UID or unique prefix) to a remote: push <uid|prefix> [remote]
- pull        Pull snapshot from a remote into local: pull <uid|prefix> <remote> [--force]
- list        List snapshots (default local). Options: --remote=<name> --all --limit=N --full
- remote      Manage remotes: add/list/remove/reload
- cat         Output raw object from a remote (--remote=, default local)
- get         Download remote object to file (--remote=, --output=)
- fetch       Force listing scan (legacy convenience; returns count)
- help        Display dynamic command help

Remote Management Examples
--------------------------
Add an S3 remote:
```
snappy remote add origin s3 \
  --endpoint=https://s3.example \
  --bucket=mybucket \
  --region=us-east-1 \
  --key=AKIA... \
  --secret=SECRET \
  --path-style
```
Manual edit + reload:
```
vi $SNAPPY_SNAPSHOT_ROOT/.snappy/config.json
snappy remote reload
```
Remove remote:
```
snappy remote remove origin
```

Typical Workflow
----------------
```
snappy snap -m "before upgrade"
snappy push <uid-prefix> origin
snappy pull <uid-prefix> origin
```

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

Integrity
---------
- push: validates local file checksums before upload.
- pull: validates downloaded files against meta checksums.

Extending Snapshot Types
------------------------
Add branch in snapshot_manager::create() and produce files + checksums before write_meta().

Testing (Planned)
-----------------
- UID prefix resolution
- push / pull round‑trip + checksum verification
- remote add validation
- list performance

Roadmap
-------
Short term:
- Compression/encryption opt‑in
- Prune / GC utility

Medium term:
- Parallel uploads (multipart)
- Additional storage backends (Azure Blob, GCS)

Long term:
- Incremental / differential snapshots
- Content‑addressable dedupe

License
-------
See root LICENCE.
