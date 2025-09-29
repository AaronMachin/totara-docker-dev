# Remotes (Configuration + Pull)

Status: Supports configuration (add/list/remove), snapshot pull (download), and remote snapshot listing via `snapshot list --remote <name>`.

## Purpose
Define S3-compatible remote endpoints (name -> endpoint, bucket, credentials), list remote snapshots, and allow pulling a remote snapshot directory into local storage for inspection, sharing, or restoration.

## Supported Operations
- Add: `remote add <name> --endpoint=URL --bucket=NAME --region=REG --key=ACCESSKEY --secret=SECRET [--path-style]`
  - `region` optional (defaults `us-east-1`)
  - `--path-style` sets path_style=true for path-style S3-compatible object stores (e.g. local gateways, self-hosted services)
  - Name pattern: `^[a-z0-9][a-z0-9_-]{0,31}$` (lowercase)
- List Remotes: `remote list` (redacts credentials)
- List Remote Snapshots: `snapshot list --remote <name> [--limit=N] [--full]`
  - Scans objects under `snaps/`, fetches `manifest-v2.json` or falls back to `meta.json`
  - Outputs uid, created, type, first line of message (or full message flattened with `--full`), and size when derivable
  - JSON shape: `{ remote, snapshots:[ { uid, created, type, message, size_bytes? } ], full, limit, errors, candidates }`
  - Corrupt / unreadable entries skipped; if all unreadable returns non-zero with error
- Pull: `remote pull <remote> <uid|prefix> [--uid-strategy=keep|new] [--force] [--progress] [--out-dir=DIR]`
  - Resolves `<uid|prefix>` remotely (must uniquely match)
  - Downloads all files under `snaps/<uid>/` into local `snaps/<uid>` (or new uid when `--uid-strategy=new`)
  - Verifies checksums when `manifest-v2.json` present; synthesises manifest when only `meta.json` exists
  - `--force` allows overwrite when keeping the original uid
  - `--progress` emits a line per file to stderr (suppressed in `--json` mode)
  - JSON output shape: `{ action:"remote_pull", remote, uid, final_uid, files, bytes, verified, provenance_path? }`

## Redaction Rules
Human output:
- Access key: only first 4 characters shown
- Secret: fully masked as `********`
JSON (`--json` global flag):
- Credentials omitted entirely (no `key` or `secret` fields)

## config.json Structure (excerpt)
```
{
  "version": 1,
  "remotes": {
    "local": { "type": "local", "path": "/abs/path", "created": "..." },
    "prod": {
      "type": "s3",
      "config": {
        "endpoint": "https://s3.example.com",
        "bucket": "mybucket",
        "region": "us-east-1",
        "key": "AKIAFULLKEY...",   // redacted in human output, omitted in JSON mode
        "secret": "SECRET...",      // never shown in output
        "path_style": true
      },
      "created": "..."
    }
  },
  "options": { "default_remote": "local" }
}
```

## Examples
```
# List remote snapshots (first 10)
tsnap snapshot list --remote prod --limit=10

# Full messages
tsnap snapshot list --remote prod --full

# Add a production remote (path-style object store)
tsnap remote add prod --endpoint=http://objectstore.local:9000 --bucket=snaps --key=access --secret=secret --path-style

# List remotes (human)
tsnap remote list

# Pull snapshot (keep uid)
tsnap remote pull prod 4f2c9e1a

# Pull snapshot with new uid strategy
tsnap remote pull prod 4f2c9e1a --uid-strategy=new

# Pull with progress & force overwrite
tsnap remote pull prod 4f2c9e1a --force --progress
```

## Integration Test Environment (Optional)
Set `SNAPPY_OBJECTSTORE_IT=1` to enable live object store integration tests (skipped by default).

Env vars:
- SNAPPY_OBJECTSTORE_ENDPOINT (default http://localhost:8000)
- SNAPPY_OBJECTSTORE_USER (default admin)
- SNAPPY_OBJECTSTORE_SECRET (default admin12345)

## Deferred (Future Tickets)
- Push operations (upload)
- Differential / partial sync
- Credential helpers / env sourcing
- Optional reachability or credential validation
- Parallelism / multipart tuning
- Pagination beyond overscan heuristic

## Notes
- Config file writes are atomic (temp + rename).
- No secrets are logged beyond first 4 chars of the access key.
- Pull creates `import_provenance_remote.json` only when using `--uid-strategy=new`.
