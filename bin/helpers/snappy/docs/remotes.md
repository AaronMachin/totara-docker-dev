# Remotes (T14A Baseline)

Status: Configuration-only skeleton. No listing, push, or pull yet.

## Purpose
Prepare for future remote catalog & artifact synchronization without retaining legacy push/pull complexity.

## Configuration Schema (config.json excerpt)
```
{
  "version": 1,
  "remotes": {
    "local": { "type": "local", "path": "/abs/path", "created": "2025-09-29T00:00:00Z" },
    "prod": {
      "type": "s3",
      "config": {
        "endpoint": "https://s3.example.com",
        "bucket": "mybucket",
        "region": "us-east-1",
        "key": "AKIA...",      // redacted in output
        "secret": "SECRET...", // redacted in output
        "path_style": true
      },
      "created": "..."
    }
  },
  "options": { "default_remote": "local" }
}
```

## Commands
- `remote add <name> s3 --endpoint= --bucket= --region= --key= --secret= [--path-style]`
- `remote list` (redacts `key` and `secret` fields)
- `remote remove <name>` (cannot remove `local`)

## Design Notes
- Only S3 (or compatible) placeholder supported. Validation ensures required fields present.
- No network I/O performed in T14A; storage client will be introduced when listing/download needed.
- Secrets stored in plain config (encryption explicitly out-of-scope per ticket); never logged or shown.

## Future (Deferred)
(To be implemented in later tickets)
- Remote snapshot catalog listing (manifest scan or compact index)
- Artifact push / pull
- Credentials sourcing via environment variables
- Optional credential helper abstraction

