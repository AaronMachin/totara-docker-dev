# Deferred / Removed Capabilities (T14A Baseline)

The T14A trimming establishes a lean core focused on local snapshot creation and remote configuration. The following legacy or higher‑level capabilities were intentionally removed or deferred. They will return (only if justified) in later T14* tickets with simpler, more deterministic implementations.

## Removed (Legacy Code Deleted)
- doctor run (environment / integrity diagnostic umbrella)
- verify run (explicit checksum verification; superseded by deterministic import/export integrity to be added)
- share create / list / fetch (token + optional archive + presign logic)
- snapshot tag (per‑snapshot tag mutation / filtering)
- prune run (age / count pruning heuristics)
- hash store (objects/ content‑addressable dedupe prototype)
- remote multi listing & parallel pcntl fork scan
- remote index manager (remote_index_manager) + remote snapshot cache
- hosting/ ngrok tunnel & share codec classes
- push / pull snapshot commands (legacy remote replication flow)
- snapshot filtering expressions (--filter / tag / age conditions)

## Deferred (To Be Reintroduced / Reimagined)
| Area | Planned Ticket (Indicative) | Summary |
|------|-----------------------------|---------|
| Manifest canonical hashing | T14B | Freeze manifest v2 canonical JSON + golden hash test |
| IntegrityService | T14C | Central streaming hashing (files, manifest, artifact lines) |
| Export (tar.gz builder) | T14D | Deterministic artifact emission (<uid>.tar.gz) |
| Import (validator + uid strategy) | T14E | Stream validate + new/keep uid modes |
| Share (ephemeral encoded command) | T14G | Minimal peer distribution (health + artifact) |
| Remote catalog listing | T14J | Read‑only manifest / meta scan + summary output |
| GC refinement | T14K | Re-scope objects GC post hash store decision |
| Credential helpers | Later | Environment / external provider resolution |

## Rationale
Trimming reduces cognitive load and eliminates partially overlapping integrity mechanisms (verify/doctor) before introducing a single canonical hashing + artifact pipeline. It also avoids carrying forward experimental remote indexing and network tunnel/embed code until a stable export/import boundary exists.

## Design Guardrails Going Forward
1. Determinism first: hashes derived from canonical JSON & ordered file streams.
2. Single source of truth: manifest-v2 + export.json drive verification; no parallel ad‑hoc integrity commands.
3. Minimal surface: add commands only when a full vertical slice is ready (create → export → import → optional share/remote list).
4. Streaming everywhere: constant memory hashing & transfer (64KB chunks typical).
5. Plain PHP, no new composer dependencies without explicit ticket approval.

## Compatibility / Migration Notes
Existing older snapshot directories retain meta.json; manifest-v2.json is now authoritative. Tag and hash store metadata (object_map, tags arrays) are ignored by trimmed commands. Future import logic will tolerate absent provenance (added now) but depend on canonical ordering.

## Security Notes
Encryption, signing, and secret rotation remain explicitly out of scope for T14A baseline. Remote credentials are stored in config.json (redacted in list output) and never logged.

