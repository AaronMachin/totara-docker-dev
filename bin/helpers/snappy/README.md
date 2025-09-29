Snappy (T14A Baseline)
========================

Overview
--------
Snappy is a lean, local‑first snapshot tool for developer databases. The T14A baseline deliberately trims legacy surface area (push/pull, verify, doctor, share tokens, tag, hash store, remote indexing) to prepare for a deterministic tar.gz artifact export/import pipeline implemented in later tickets.

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
(Remote entries are configuration only in T14A: no network listing, pull, or push yet.)

(maintenance)
- gc objects (trimmed soon; kept minimal) – subject to future refinement
- config get / config set – read & persist configuration values

Artifact (Forward Spec)
-----------------------
Export/import/share features are deferred; the artifact spec is documented in docs/artifact_spec.md. Snapshot directories already contain manifest-v2.json (schema_version=2) written deterministically (field ordering not yet canonicalized – T14B+T14C address canonical hashing).

Local Layout
------------
$SNAPPY_SNAPSHOT_BASE/
  snaps/<uid>/manifest-v2.json
  snaps/<uid>/meta.json (legacy, still written for transition – slated for removal after importer/exporter stabilize)
  snaps/<uid>/backup.sql[.gz]
  snaps/index.json (summary index, auto-maintained)

Configuration
-------------
Configuration file: config.json at the chosen base (SNAPPY_CONFIG_FILE or default under tool directory for embedded usage). Remote configs stored under remotes:{ name: { type:"s3", config:{endpoint,bucket,region,key,secret,path_style?} } } plus the reserved remotes.local entry.
Secrets are never printed; remote list redacts credentials (currently key/secret retained internally but may be further redacted in later tickets).

Planned (Deferred) Features (See docs/deferred.md)
-------------------------------------------------
- Deterministic streaming export to <uid>.tar.gz (T14D)
- Streaming import with uid strategy (T14E)
- Remote snapshot enumeration (T14J)
- Ephemeral peer share (T14G)
- IntegrityService central hashing (T14C)
- Manifest canonical hash freeze (T14B)

Why Trim First?
---------------
Eliminating unused legacy commands reduces risk while refocusing on a narrow, composable pipeline: create → manifest → (future) export → import → share / remote pull.

Quick Start
-----------
Create a snapshot:
  tsnap snapshot create -m "initial load"
List snapshots:
  tsnap snapshot list
Show details:
  tsnap snapshot show <uid>
Add remote config:
  tsnap remote add prod s3 --endpoint=https://s3.example.com --bucket=mybucket --region=us-east-1 --key=AKIA... --secret=SECRET
List remotes:
  tsnap remote list
Remove remote:
  tsnap remote remove prod

Environment Variables
---------------------
SNAPPY_SNAPSHOT_BASE  Override snapshot root (default internal path / user home future)
SNAPPY_CONFIG_FILE    Override config file path
SNAPPY_PROVISIONAL_BASE  Seed path for initial config/structure
SNAPPY_FAKE_DUMP      Test hook enabling fake dump provider

Security Notes
--------------
- Do not commit real credentials. Secrets stored in config.json are not logged.
- No encryption/signing yet (explicitly out of scope for baseline).

Documentation
-------------
- Artifact Spec: docs/artifact_spec.md
- Deferred / Removed Legacy Surface: docs/deferred.md
- Remote Config: docs/remotes.md
- Refactor Notes (files removed & LOC delta): docs/refactor_notes.md

License
-------
See root LICENCE.
