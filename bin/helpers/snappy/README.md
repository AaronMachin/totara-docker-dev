Snappy
========================

Overview
--------
Snappy is a lean, local‑first snapshot tool for developer databases. The baseline deliberately trims legacy surface area (push/pull, verify, doctor, share tokens, tag, hash store, remote indexing) to focus on a deterministic artifact pipeline.

Current Supported Commands
--------------------------
(snapshot.*)
- snapshot create   Create a local SQL snapshot (optional --compress)
- snapshot list     List local snapshots (UID, created, type, first message line)
- snapshot show     Show manifest details for a snapshot
- snapshot metrics  Aggregate counts & basic age buckets (derived from local index)

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
Usage examples:
  tsnap create -m "initial load"
  tsnap list
  tsnap show <uid>
The canonical namespaced forms always remain available:
  tsnap snapshot create -m "initial load"

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

Quick Start
-----------
Create a snapshot:
  tsnap create -m "initial load"
List snapshots:
  tsnap list
Show details:
  tsnap show <uid>
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
