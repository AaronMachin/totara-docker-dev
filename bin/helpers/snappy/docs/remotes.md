# Remotes (T14F Config CRUD Only)

Status: Basic configuration (add/list/remove) stored in config.json. Listing integration with snapshot operations deferred to T14J.

## Purpose
Define S3-compatible remote endpoints (name -> endpoint, bucket, credentials) for future snapshot pull/push & remote listing features.

## Supported Operations
- Add: `remote add <name> --endpoint=URL --bucket=NAME --region=REG --key=ACCESSKEY --secret=SECRET [--path-style]`
  - `region` optional (defaults `us-east-1`)
  - `--path-style` sets path_style=true for MinIO / path addressing
  - Name pattern: `^[a-z0-9][a-z0-9_-]{0,31}$` (lowercase)
- List: `remote list` (redacts credentials)
- Remove: `remote remove <name>` (cannot remove `local`)

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
# Add a production remote (MinIO style path addressing)
tsnap remote add prod --endpoint=http://minio.local:9000 --bucket=snaps --key=minioadmin --secret=minioadmin --path-style

# List (human)
tsnap remote list

# List JSON
tsnap --json remote list

# Remove
tsnap remote remove prod
```

## Deferred (Future Tickets)
- Remote snapshot enumeration (T14J)
- Pull / push operations (T14I / later)
- Credential helpers / env sourcing
- Optional reachability or credential validation

## Notes
- Config file writes are atomic (temp + rename).
- No secrets are logged beyond first 4 chars of the access key.
