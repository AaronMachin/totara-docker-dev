Snappy
========================

Overview
--------
Snappy is a lean, local‑first snapshot tool for developer databases. The baseline deliberately trims legacy surface area (push/pull, verify, doctor, share tokens, tag, hash store, remote indexing) to focus on a deterministic artifact pipeline.

Current Supported Commands
--------------------------
(snapshot.*)
- snapshot create   Create a local SQL snapshot (optional --compress)
- snapshot apply    Apply (restore) a local SQL snapshot to the developer database
- snapshot list     List local snapshots (UID, created, type, first message line)
- snapshot show     Show manifest details for a snapshot
- snapshot metrics  Aggregate counts & basic age buckets (derived from local index)
- snapshot delete   Delete a local snapshot by UID or unique prefix (safe index prune)

(remote.*)
- remote add <name> s3 --endpoint= --bucket= --region= --key= --secret= [--path-style]
- remote list
- remote remove <name>

(maintenance)
- gc objects – subject to future refinement
- config get / config set – read & persist configuration values

Aliases
-------
Short root aliases reduce typing for frequent snapshot lifecycle commands.
Default built‑in aliases (auto‑loaded if none configured):
  create -> snapshot.create
  list   -> snapshot.list
  show   -> snapshot.show
  apply  -> snapshot.apply
Usage examples:
  tsnap create -m "initial load"
  tsnap list
  tsnap show <uid>
  tsnap apply <uid>
The canonical namespaced forms always remain available:
  tsnap snapshot create -m "initial load"
  tsnap snapshot apply <uid>

Configurable Aliases
You can add (or override) aliases via config values. Each alias is stored under the aliases.<name> path with the value set to the canonical command name (group.sub form). Only snapshot.* targets are applied.
Examples (persisting new aliases):
  tsnap config set aliases.metrics snapshot.metrics --persist
  tsnap config set aliases.export snapshot.export --persist
  tsnap config set aliases.import snapshot.import --persist
After setting, invoke directly:
  tsnap metrics
  tsnap export <uid> --out-dir=/tmp
  tsnap import <artifact> --uid-strategy=new
Removing an alias:
  tsnap config set aliases.metrics null --persist   (sets to null, effectively removed)
You can also point an alias to another snapshot command:
  tsnap config set aliases.ls snapshot.list --persist

Configuration is read at each invocation; no restart needed.

Artifact (Forward Spec)
-----------------------
Export/import/share features evolve toward deterministic tar.gz (<uid>.tar.gz) artifacts containing manifest-v2.json, export metadata, and files/* streamed without loading entire contents into memory.

Local Layout
------------
$SNAPPY_SNAPSHOT_BASE/
  snaps/<uid>/manifest-v2.json
  snaps/<uid>/meta.json (legacy transitional file)
  snaps/<uid>/backup.sql[.gz]
  snaps/index.json (summary index, auto-maintained)

Configuration
-------------
Configuration file: config.json at the chosen base (SNAPPY_CONFIG_FILE or default under tool directory for embedded usage). Remote configs stored under remotes:{ name:{ type:"s3", config:{endpoint,bucket,region,key,secret,path_style?} } } plus the reserved remotes.local entry.
Secrets are never printed; remote list redacts credentials.

Share Host Providers & Tunnelling
---------------------------------
The share.create command can expose a snapshot artifact beyond your LAN using a pluggable *host provider*.

Defaults:
- Provider selected via config option: options.share_host_provider (string)
- Default value: "ngrok" (if ngrok binary present in PATH it will create a TCP tunnel)
- Set to "none" (or use --lan-only flag) to disable tunnelling and keep sharing local / LAN-only.

Security / Access:
- An OTP (one-time password) k is generated per share session and embedded in the encoded payload; the importer automatically appends it (?k=).
- If OTP omitted or wrong, server returns 403 Forbidden.
- No encryption: treat data as public. Integrity is always verified (sha256) client side.

Configuration Example (config.json):
```
{
  "options": {
    "share_host_provider": "ngrok"
  }
}
```
Set to none:
```
{
  "options": { "share_host_provider": "none" }
}
```
Runtime Overrides:
- --lan-only flag forces provider=none for that invocation.

Payload Fields (additions):
- provider: configured provider name (e.g. ngrok, none)
- tunnel: active tunnel type or none
- k: OTP required to download (+ appended automatically by importer)

Example:
  tsnap share create <uid>
  # Outputs encoded payload containing provider, tunnel, k
  tsnap share import <encoded>

Quick Start
-----------
Create a snapshot:
  tsnap create -m "initial load"
List snapshots:
  tsnap list
Show details:
  tsnap show <uid>
Apply (restore) a snapshot to your dev database:
  tsnap apply <uid>
Share snapshot over the internet (ngrok default if available):
  tsnap share create <uid>
LAN only (no tunnel):
  tsnap share create <uid> --lan-only
Import shared snapshot (OTP + checksum verified):
  tsnap share import <encoded>

Delete snapshot (safe removal + index prune):
  tsnap snapshot delete <uid|prefix>
Metrics (once alias added):
  tsnap config set aliases.metrics snapshot.metrics --persist
  tsnap metrics
Export snapshot:
  tsnap snapshot export <uid> --out-dir=/tmp
Import snapshot:
  tsnap snapshot import /tmp/<uid>.tar.gz --uid-strategy=new

Add remote config:
  tsnap remote add prod s3 --endpoint=https://s3.example.com --bucket=mybucket --region=us-east-1 --key=AKIA... --secret=SECRET
List remotes:
  tsnap remote list
Remove remote:
  tsnap remote remove prod

Delete a Snapshot
-----------------
Safely remove a local snapshot (directory + index entry):
  tsnap snapshot delete <uid|prefix>
If the prefix is ambiguous (matches multiple UIDs) deletion is aborted (exit 64). If not found exit 2.
Idempotent: deleting an already deleted UID returns exit 2.

Environment Variables
---------------------
SNAPPY_SNAPSHOT_BASE  Override snapshot root
SNAPPY_CONFIG_FILE    Override config file path
SNAPPY_PROVISIONAL_BASE  Seed path for initial config/structure
SNAPPY_FAKE_DUMP      Test hook enabling fake dump provider

Security Notes
--------------
- Do not commit real credentials. Secrets stored in config.json are not logged.
- No encryption/signing (explicitly out of scope currently).

Documentation
-------------
- Artifact Spec: docs/artifact_spec.md
- Deferred / Removed Legacy Surface: docs/deferred.md
- Remote Config: docs/remotes.md
- Refactor Notes: docs/refactor_notes.md

License
-------
See root LICENCE.
