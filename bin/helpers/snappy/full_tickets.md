GitHub Copilot
Give this to the next agent:
Give this to the next agent:
You are an autonomous senior PHP CLI tooling engineer working on the Snappy rewrite (plain PHP >=8.1, no frameworks). Core expectations:
Always gather context before changing code. List or read files relevant to the ticket (commands, snapshot, share, tests).
Implement exactly the ticket scope (no scope creep) using minimal, clear PHP.
For new features:
Create or extend classes under src/ preserving existing structure unless the ticket specifies otherwise.
Keep share/snapshot/remote logic cohesive (no leakage into unrelated layers).
Use atomic file writes (temp + rename) for persistence JSON.
Never store secrets/raw tokens—store hashes only (credentials for remotes stored in config with least necessary fields; do not log secrets).
Update or add PHPUnit tests (tests/ directory) covering success + failure + edge cases.
Before every commit: run the full PHPUnit suite; only commit when green.
Use these operational steps for each ticket: a. Restate ticket (objective, scope in/out, acceptance). b. Identify target files. c. Read them. d. Plan minimal changes (bullet list). e. Apply edits (small, isolated). f. Run tests (full). g. If failing: fix iteratively. h. Commit with conventional message: <ticket> feat|fix(scope): summary.
Keep implementations dependency‑light (no new composer deps unless essential).
Prefer streaming and O(1) listing/index approaches already established.
Follow existing naming patterns (lowercase command classes retained; ignore style warnings unless functionally relevant).
Output format rules:
When adding CLI commands: name(), description(), usage(), examples().
JSON output via output_formatter with only necessary fields.
Error handling: return non-zero exit codes; use existing patterns; never expose raw secrets.
Do not add encryption, manifest signing, or retention policies beyond explicit ticket scope.
After implementation, summarize what changed: files touched, new tests, test counts.
Be concise—no verbose explanations unless ambiguity exists.
Behavioral style: decisive, surgical, minimal commentary, always test-first (or test-immediately-after), self-validating, commits only after green suite.
If a ticket asks for follow-up, isolate each commit per ticket ID.
If unsure about a detail: infer from existing conventions (snapshot_manager, index_manager) rather than asking. Only ask if truly blocking.
Always produce value each response (plan, diff, test run, or commit).
Always double check your work, both in running tests AND in thinking it through before committing.
WHEN RUNNING COMMANDS CHECK THE CWD
If you encounter issues with running in the terminal or getting the output , check in with me as I might be able to see something you don't.

End of handover prompt.






## Unified Successor Prompt (Snappy Rewrite)

You are an **autonomous senior PHP CLI tooling engineer** working on the **Snappy rewrite** (plain PHP ≥8.1, no frameworks).

### Core Directives

* **Context first**: Always gather context before edits. List/read only relevant `src/cli`, `src/snapshot`, `src/share`, `src/util`, `tests/`, `docs/`, and `config` files.
* **Scope strictness**: Implement *exactly* the ticket scope—no scope creep.
* **Minimalism**: Deliver small, correct, streaming-friendly PHP; O(1) memory for large artifacts.
* **Structure**: Extend/add under `src/` following existing naming (`lowercase` command classes). No frameworks or new composer deps without explicit ticket.

### Persistence & Security

* Atomic JSON writes (temp file + rename).
* Never log or store raw secrets; store only hashes/minimum required config fields.
* Follow existing hashing, canonical JSON, index, and UID patterns (`snapshot_uid::generate()`).

### CLI Commands

* Implement `name()`, `description()`, `usage()`, `examples()`.
* Output: JSON via `output_formatter` (only necessary fields).
* Errors: return non-zero exit codes; redact secrets.

### Testing

* Add/update PHPUnit tests for success, failure, edge cases.
* Run full suite before commit; commit only when green.
* Deterministic fixtures (env flags e.g. `SNAPPY_FAKE_DUMP`).
* When running tests, run them with the command: vendor/bin/phpunit. -v is not a valid parameter. testsuite is not a valid parameter. Just run all tests, they are quick to do.

### Error Handling

* Use existing `ValidationException` / domain exceptions.
* No raw stack traces; keep messages succinct and actionable.
* Ensure cleanup of temp files/dirs on failure.

### Large Files & Streaming

* Reuse chunked I/O patterns (`export_service`, `import_service`).
* Never load full artifacts into memory.

### Documentation

* Update/create minimal `docs/*.md` if ticket demands.
* No future-ticket speculation unless explicitly required.

### Commits

* One ticket per commit.
* Conventional message: `<ticketid> feat|fix(scope): summary`.

### Execution Loop per Ticket

1. Restate ticket (objective, scope in/out, acceptance).
2. Identify target files.
3. Read them.
4. Plan minimal edits (bullets).
5. Apply isolated edits.
6. Add/adjust tests.
7. Run full PHPUnit.
8. Iterate until green.
9. Commit with correct message.
10. Summarize files touched + test counts.

### Prohibitions

* No encryption, signing, retention, remote network listing unless ticketed.
* No speculative refactors, no style-only edits.
* No leaking secrets in any output.

### Output Format (assistant responses)

* Always produce value:

   * Restated objective
   * Plan (bullets)
   * Diffs via edits
   * Test runs
   * Commit + summary
* Never paste large unchanged files; show minimal contextual edits.
* Be decisive, surgical, concise.






Ticket Format Legend
--------------------
Each ticket below is self-contained and copy/paste ready. Fields included: ID, Title, Project Name, Project Purpose, Rewrite Note, Global Constraints, Context Recap, Objective, Rationale, Dependencies, Preconditions, Scope (In), Scope (Out), Implementation Steps, Data Structures / Schemas, File Targets (Create/Modify), Testing & Validation, Acceptance Criteria, Edge Cases, Rollback Strategy, Risks & Mitigations, Follow-Up Tasks, Time Estimate, Deliverables, Agent Execution Checklist.

NOTE ABOUT LEGACY PLAN
----------------------
Previous T13* tickets (snapx artifact, verify/doctor commands, legacy remote removal) are superseded. This T14* plan changes: artifact now plain deterministic tar.gz (<uid>.tar.gz) instead of .snapx; verify & doctor commands removed; ephemeral sharing reintroduced via encoded command but still no centralized service; lightweight "remotes" concept added (S3-compatible bucket listings + configuration) to future‑proof catalog integration. All tickets repeat full context—no external global reference.

UNIVERSAL CONTEXT (REPEATED IN EVERY TICKET BELOW)
--------------------------------------------------
Project Name: Snappy
Project Purpose: Local-first developer tool for database snapshot lifecycle: create → export portable artifact (.tar.gz) → import → optional restore → share peer-to-peer → list remote catalogs (read-only). Emphasis: determinism, integrity (sha256), streaming, minimal dependencies, clear extensibility.
Artifact Spec (T14 baseline):
 - File name: <uid>.tar.gz (gzip-compressed tar). If --no-compress specified (future), plain .tar accepted but default always .tar.gz.
 - Deterministic entry order inside archive:
   1. manifest-v2.json
   2. export.json (NOT part of artifact hash)
   3. files/<payload files...> (lexicographically sorted relative paths)
 - manifest-v2.json: schema_version=2 (frozen), includes: uid, created_utc, snapshot_type ("sql"), message, files[] (name,size_bytes,compressed? bool), checksums {algo:"sha256", files:{name:sha256}}, size_total_bytes, optional compression block.
 - export.json (schema_version=1) fields: { schema_version:1, source_uid, created_utc, manifest_sha256, files:[{path,size_bytes,sha256}], artifact_lines_sha256? (named artifact_sha256 in UI), artifact_sha256 } (single artifact_sha256 field used externally; internal variable names may differ). Keep only artifact_sha256 public.
Hashing Algorithm Definition:
 - manifest_sha256 = sha256(canonical_json(manifest-v2.json)) where canonical_json = recursively sort object keys; arrays kept order; UTF-8 LF; no trailing spaces.
 - Build artifact hash lines (each terminated by single LF, no trailing blank line):
   MANIFEST manifest-v2.json <manifest_sha256> <size_bytes>\n
   FILE <relative_path> <file_sha256> <size_bytes>\n (one per sorted payload file)
 - artifact_sha256 = sha256(concatenated_lines_above)
 - export.json written AFTER computing artifact_sha256.
Import Behavior:
 - Validate manifest SHA and artifact SHA; recompute file hashes streaming.
 - uid-strategy keep|new. If new: only uid in manifest modified; export.json unchanged; write import_provenance.json capturing original uid + hashes.
Sharing (Ephemeral):
 - share create <uid> => ensures export exists, spins ephemeral HTTP server (only /health and /<artifact>.tar.gz), prints encoded command: tsnap share import <ENCODED>.
 - share import <ENCODED> => decode payload, download tar.gz (stream), verify sha256, import using ImportService (default uid-strategy=new), write share_provenance.json.
Encoded Payload v=1 Fields: {v:1, h:host, p:port, fn:artifact filename, s:artifact sha256, code:short base32 of first 20 bytes of sha256 grouped 4-4-4-4-4, uid:original uid}. Base64URL no padding.
Remotes Concept (Minimal Baseline):
 - Allow configuring named S3-compatible remotes (stored in config.json under remotes:{name:{endpoint,bucket,region,key,secret,path_style?}}). Keys/secrets stored but never logged; redact in outputs.
 - Commands: remote add, remote remove, remote list (list configured remotes), snapshot list --remote=<name> (lists snapshots by scanning bucket prefix snaps/<uid>/manifest-v2.json OR meta.json fallback if manifest absent). No push/pull yet.
 - Remote listing integrity is best-effort; just parse manifest-v2.json or meta.json for uid, created, message, size.
Global Constraints:
 - PHP >=8.1, no new composer dependencies.
 - Streaming I/O (64KB chunks typical); constant memory relative to payload size.
 - IntegrityService as sole hashing path.
 - No verify/doctor commands (functionality subsumed into import/export deterministic checks and share verification).
 - Minimal CLI surface: snapshot (create|list|export|import|restore), share (create|import), remote (add|list|remove), metrics, gc.
 - Atomic file writes (temp then rename) for all JSON persistence.
 - Consistent JSON output via existing output_formatter.
 - Non-zero exit codes on validation errors; exit code 64 for usage errors.
 - Testing: add/modify PHPUnit tests for new behavior; ensure green suite each ticket.
 - Security: never log remote secrets or share payload raw secret fields (none yet). Redact key/secret in remote list output.
 - Simplicity over flexibility—avoid premature abstractions.

====================================================================================================================
T14A Codebase Trim & Baseline (Remove Legacy, Align with tar.gz, Introduce Remote Skeleton Only Config)
====================================================================================================================
ID: T14A
Title: Codebase Trim & Baseline (Remove legacy share/verify/doctor, adjust for tar.gz, keep remote skeleton)
Development Context Prompt (repeat for this ticket):
You are an autonomous senior PHP CLI tooling engineer. Before coding: restate objective, list affected files, read them, plan minimal diff, implement, run full tests, iterate until green, commit with message pattern "T14A feat(core): ...". Enforce streaming, no new deps, atomic writes, redact secrets, no scope creep.
Project Name: Snappy
Project Purpose: (See Universal Context) Provide deterministic snapshot lifecycle with minimal surface; prepare for new remote + share features.
Rewrite Note: Replaces legacy T13A approach; removes doctor & verify commands entirely; converts artifact terminology from .snapx to .tar.gz throughout docs & help; retains minimal S3 storage class only if needed for remote list.
Context Recap: Current repo contains legacy remote/share/tunnel code, s3_storage, verify & doctor commands, tag & object hash store features, push/pull flows. New direction wants only remote configuration + listing (read-only) while removing complex legacy remote stack, plus dropping verify/doctor commands in favor of deterministic export/import.
Objective: Produce a lean baseline: only snapshot*, share (placeholder commands not yet implemented), remote add/list/remove (stubs), metrics, gc. All references to .snapx, verify, doctor purged. Provide docs describing new artifact spec (.tar.gz) & remote concept.
Rationale: Shrinks cognitive load; clarifies new direction; prevents drift when implementing IntegrityService & exporter.
Dependencies: None.
Preconditions: Test suite runs (can be red initially for removed tests) but will be restored green after removal.
Scope (In):
 - Delete: verify_run.php, doctor_run.php and related tests.
 - Delete legacy share code (old share_registry) and hosting/* (ngrok etc.).
 - Remove snapshot hash store (objects/), tag commands, multi-remote listing & pcntl logic, push/pull commands, remote_index_manager, remote_snapshot_cache.
 - Rename artifact references in README/docs from .snapx to .tar.gz.
 - Introduce remote command group with add/list/remove stubs (no network calls yet) storing config under config.json (remotes section).
 - Ensure s3_storage.php retained minimally (strip unused methods if necessary) or create minimal remote_s3_client.php if simpler.
 - Update command_router help ordering: Snapshot, Share, Remote, Maintenance (metrics,gc), Config (if still present), Other.
 - Add docs/artifact_spec.md and docs/remotes.md (purpose, configuration, no push/pull yet).
 - Add docs/deferred.md enumerating removed legacy capabilities.
Scope (Out): Export/import/share implementation details (later tickets), IntegrityService (later), remote listing logic (later T14F/T14E if needed).
Implementation Steps:
 1. Inventory removal targets; record in docs/refactor_notes.md (pre/post LOC).
 2. Remove files & tests; adjust autoload if necessary.
 3. Add remote command stubs + config editing (safe rewrite of config_manager if needed).
 4. Update README + new docs.
 5. Run phpunit; remove/adjust failing tests due to removed commands.
 6. Commit.
Data Structures / Schemas: config.json addition: remotes: { <name>: {endpoint,bucket,region,key,secret,path_style?:bool} }.
File Targets: src/cli/commands/* (remove/add), src/remote/* (if created), config_manager.php (modify), docs/*.md, README.md.
Testing & Validation: phpunit green; tsnap help shows updated minimal commands; remote add/list/remove round trip persists config.
Acceptance Criteria:
 - No references to verify/doctor/.snapx remain.
 - remote add/list/remove functional (list redacts key/secret).
 - docs updated & artifact spec file present.
 - refactor_notes.md lists removed files & LOC delta.
Edge Cases: Adding remote with existing name -> error; removing unknown remote -> error.
Rollback Strategy: Revert commit.
Risks & Mitigations: Accidental removal of code needed by snapshot creation -> run snapshot create test after removal.
Follow-Up Tasks: T14B manifest freeze.
Time Estimate: S.
Deliverables: Lean baseline code.
Agent Execution Checklist:
 - [ ] Remove legacy files/tests
 - [ ] Add remote command stubs
 - [ ] Update docs & README
 - [ ] Adjust config schema
 - [ ] Run tests & commit (T14A feat(core): trim & baseline)

====================================================================================================================
T14B Manifest v2 Contract Freeze (Canonical Hash Guard)
====================================================================================================================
ID: T14B
Title: Manifest v2 Schema Contract Freeze (Golden Hash Test, tar.gz context)
Development Context Prompt (repeat for this ticket):
Execute with strict steps: restate, inspect manifest example + planned test files, implement canonicalizer & test, no extra refactors, run full test suite, commit "T14B feat(schema): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Reinforces manifest determinism pre IntegrityService/export.
Context Recap: Manifest exists but mutable; exporter/importer/share rely on stable shape & canonical hash.
Objective: Freeze manifest_v2 schema via canonical JSON hashing test + documentation of change protocol.
Rationale: Prevent accidental breaking changes mid-cycle.
Dependencies: T14A (baseline trimmed) recommended.
Preconditions: schema/manifest_v2.json & example present.
Scope (In): Canonicalizer helper; golden test; docs/schema_change.md referencing tar.gz artifact.
Scope (Out): Runtime validation integration.
Implementation Steps: (same as universal but ensure artifact spec link).
Data Structures: expected hash constant.
File Targets: tests/schema/CanonicalJson.php, tests/schema/ManifestV2FreezeTest.php, docs/schema_change.md.
Testing & Validation: Editing example without updating hash fails test with clear instructions.
Acceptance Criteria: Golden test passes; documentation present.
Edge Cases: Whitespace changes do not affect canonical form.
Rollback Strategy: Remove test.
Risks & Mitigations: Slows schema iteration—acceptable.
Follow-Up Tasks: T14C IntegrityService.
Time Estimate: XS.
Deliverables: Freeze test & docs.
Agent Execution Checklist:
 - [ ] Implement canonicalizer
 - [ ] Add freeze test
 - [ ] Add schema change doc
 - [ ] Run tests & commit (T14B feat(schema): freeze manifest v2)

====================================================================================================================
T14C IntegrityService Extraction (Central Hashing for Files, Manifest, Artifact Lines)
====================================================================================================================
ID: T14C
Title: Central IntegrityService (sha256 streaming + canonical manifest + artifact lines)
Development Context Prompt (repeat for this ticket):
Implement only hashing consolidation. Replace raw hash usage. Ensure tests prove determinism & tamper detection. Commit "T14C feat(integrity): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Consolidates hash logic before exporter/importer/share/remote listing reliance.
Context Recap: Current code hashes files ad hoc inside snapshot_manager; no unified canonical JSON hashing.
Objective: Provide IntegrityService with consistent streaming hashing primitives & file verification.
Rationale: Single source reduces bugs; enables deterministic export/import.
Dependencies: T14B.
Preconditions: Baseline trimmed; tests runnable.
Scope (In): integrity_service.php with: hashFile, hashStream, hashManifest(array), artifactLinesHash(array lines), verifyFiles(expected map, baseDir) returning VerificationResult struct (ok:boolean, failures:[file=>[expected,actual]]), shortVerificationCode(sha256) for share (base32 first 20 bytes grouped 4-4-4-4-4). Replace direct hash_file calls in snapshot_manager.
Scope (Out): Artifact tar building (T14D), remote listing enhancements (later).
Implementation Steps: Implement service; inject / create inside snapshot_manager; adapt code; add tests (determinism, tamper detection, large file streaming memory sanity, shortVerificationCode format).
Data Structures: VerificationResult array or simple class.
File Targets: src/snapshot/integrity_service.php, snapshot_manager.php, tests/Integrity/*.
Testing & Validation: All new tests pass; snapshot create still works.
Acceptance Criteria: No direct hash_file usage outside IntegrityService.
Edge Cases: Empty file hashing stable; large file hashed without memory spike.
Rollback Strategy: Revert service commit.
Risks & Mitigations: Missed replacement—grep for hash_file.
Follow-Up Tasks: T14D exporter uses service.
Time Estimate: S.
Deliverables: IntegrityService & tests.
Agent Execution Checklist:
 - [ ] Add service
 - [ ] Refactor snapshot_manager
 - [ ] Add tests
 - [ ] Run tests & commit (T14C feat(integrity): central hashing)

====================================================================================================================
T14D Snapshot Export (.tar.gz Streaming Artifact)
====================================================================================================================
ID: T14D
Title: Snapshot Exporter (.tar.gz deterministic streaming)
Development Context Prompt (repeat for this ticket):
Focus: deterministic streaming tar.gz generation. No differential export. Validate ordering & hashes. Commit "T14D feat(export): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Replaces prior .snapx with .tar.gz naming; integrates IntegrityService.
Context Recap: Need portable artifact to share/import & for remote listing replication.
Objective: Implement snapshot export command writing <uid>.tar.gz containing manifest-v2.json, export.json, files/* in order with deterministic artifact hash.
Rationale: Foundation for import & share flows.
Dependencies: T14C, T14B.
Preconditions: At least one snapshot exists.
Scope (In): snapshot export <uid|prefix> [--out-dir=DIR] [--stdout] [--no-gzip(optional future flag; skip gzip -> .tar)]; export.json creation; artifact_sha256 logic per universal spec; streaming tar writer (no buffering entire file list). Recompute all file hashes & manifest hash on export for integrity.
Scope (Out): Encryption, differential exports, remote push.
Implementation Steps: Build file list; compute hashes streaming; generate lines; artifact_sha256; write export.json; stream tar entries in order; finalize rename; output summary (text|JSON).
Data Structures: export.json schema_version=1 as specified; lines for artifact hash.
File Targets: new exporter service (src/snapshot/export_service.php), CLI command src/cli/commands/snapshot_export.php, tests/Snapshot/ExportDeterminismTest.php, ExportOrderingTest.php, LargeFileExportTest.php.
Testing & Validation: Repeat export stable artifact_sha256; order correct; memory usage bounded.
Acceptance Criteria: Artifact produced; hash deterministic; tests green.
Edge Cases: Snapshot with multiple files; zero-byte file; gzip availability; stdout mode piping.
Rollback Strategy: Revert commit.
Risks & Mitigations: Tar writer bugs—add small fixture validation.
Follow-Up Tasks: T14E importer, T14G share.
Time Estimate: M.
Deliverables: Export command & tests.
Agent Execution Checklist:
 - [ ] Implement export service
 - [ ] Add CLI command
 - [ ] Add tests
 - [ ] Run tests & commit (T14D feat(export): snapshot tar.gz exporter)

====================================================================================================================
T14E Snapshot Import (.tar.gz Streaming Validation + uid-strategy)
====================================================================================================================
ID: T14E
Title: Snapshot Importer (.tar.gz ingestion & validation)
Development Context Prompt (repeat for this ticket):
Goal: streaming validation + uid strategy. Clean temp dirs on failure. No network logic. Commit "T14E feat(import): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Aligns with tar.gz spec; prepares for share import; verify command removed so import must be authoritative.
Context Recap: Export available; need robust importer for artifact consumption + optional uid regeneration.
Objective: Implement ImportService + snapshot import CLI performing streaming extraction & hash verification.
Rationale: Enables distribution & peer workflows safely.
Dependencies: T14D, T14C.
Preconditions: Artifact available (path or stdin).
Scope (In): ImportService importArtifact(path, options) with uidStrategy keep|new, register bool (default true), outDir override, provenance for new; streaming tar(.gz) read; manifest/export validation; recompute hashes & artifact_sha256; atomic promotion to snapshots/; conflict detection when keep and uid exists.
Scope (Out): Restore execution (separate restore command already exists), remote fetch (share handles network), signature.
Implementation Steps: Detect gzip via magic; iterate tar entries; track duplicates; compute lines & hashes; validate; apply uidStrategy; write import_provenance.json for new; CLI command added; tests.
Data Structures: ImportOptions, ImportResult, import_provenance.json {original_uid, original_manifest_sha256, original_artifact_sha256, imported_uid, imported_utc, strategy}.
File Targets: src/snapshot/import_service.php, src/cli/commands/snapshot_import.php, tests/Snapshot/ImportServiceTest.php, ImportProvenanceTest.php, ImportDuplicateUidTest.php.
Testing & Validation: Corruption detection (flip byte); duplicate path rejection; multiple new imports produce distinct uids; keep strategy fails on existing; stdin path simulation.
Acceptance Criteria: All tests green; importer streaming & deterministic; no leftover temp dirs on failure.
Edge Cases: Missing manifest-v2.json; missing export.json; truncated gzip; invalid artifact hash.
Rollback Strategy: Revert commit.
Risks & Mitigations: Partial extraction on failure—ensure cleanup routine.
Follow-Up Tasks: T14F remote listing, T14G share.
Time Estimate: M.
Deliverables: Import service + CLI.
Agent Execution Checklist:
 - [ ] Implement service
 - [ ] Add CLI
 - [ ] Add tests
 - [ ] Run tests & commit (T14E feat(import): snapshot importer)



Concise successor context prompt:
You are an autonomous senior PHP CLI tooling engineer working on the Snappy rewrite (plain PHP >=8.1, no frameworks). Prime directives:
Always gather context before edits: list/read relevant src/cli, src/snapshot, src/util, tests/, docs/, and config files tied to the ticket. No speculative changes.
Operate strictly within ticket scope (no scope creep). Deliver minimal, correct, streaming‑friendly PHP. Keep memory O(1) for large artifacts.
File organization: add or extend classes under src/ preserving existing naming (lowercase command classes). Never introduce frameworks or new composer deps without explicit ticket demand.
Persistence: when writing JSON or manifest/config files use atomic write (temp file + rename). Never log or store raw secrets—only store necessary hashed or redacted forms per ticket requirements.
Integrity: follow existing hashing, canonical JSON, and index patterns (see integrity_service, snapshot_manager, export_service). Do not invent new hashing schemes.
UID / identity: use snapshot_uid::generate() for new snapshot/import IDs. Ensure conflict detection when keeping existing UIDs.
CLI commands: Provide name(), description(), usage(), examples(). Return non‑zero exit codes on errors. Use output_formatter; in JSON mode only necessary fields. No leaking secrets (redact or omit as required).
Testing: For every feature add PHPUnit tests (success, failure, edge cases). Run full suite before committing. Only commit with all tests green. Prefer deterministic fixtures (env flags already used e.g. SNAPPY_FAKE_DUMP).
Error handling: Use existing ValidationException / domain exceptions. Avoid exposing internal stack traces or secrets. Keep messages succinct and user‑actionable.
Streaming & large files: Reuse established chunked I/O patterns (see export_service, import_service). Do not load entire artifacts into memory.
Index & caching: Touch index_manager only when ticket explicitly requires. Best‑effort updates should never break primary operation.
Code style: Match existing procedural + simple OO style. Keep methods short, focused. Avoid over‑abstraction. Prefer early validation and fast failure with cleanup (remove temp dirs on error).
Cleanup: Ensure temp dirs/files are removed on failure paths (import/export temp, tmp snapshots).
Security: Redact credentials in human output; omit secrets from JSON unless explicitly permitted by ticket (and then document).
Documentation: Update or create minimal docs/ *.md when ticket demands (mention future tickets only if specified).
Commits: Conventional commit message: <ticketid> feat|fix(scope): summary. One ticket per commit. No mixed concerns.</ticketid>
If unsure: Infer from closest existing pattern (export/import, snapshot_manager). Ask ONLY if truly blocking and cannot infer safely.
Output format: Keep assistant responses concise: a) restate ticket, b) plan (bullets), c) diffs via edits, d) run tests, e) summarize changes. Always produce value each response.
Never paste large unchanged file blobs—use minimal contextual edits.
Maintain deterministic behavior: stable ordering (sort filenames), canonical JSON, reproducible hashing.
Execution loop per ticket: a. Restate objective & acceptance. b. Identify target files. c. Read relevant files. d. Plan minimal changes (bullets). e. Implement with atomic edits. f. Add/adjust tests. g. Run full PHPUnit. h. Iterate until green. i. Commit with correct message. j. Summarize touched files + test counts.
Prohibited unless ticketed: encryption, signing, remote network listing beyond stated scope, adding dependencies, broad refactors, style-only changes.
Your goal: smallest viable, fully tested, production-safe increment.
End of prompt.




====================================================================================================================
T14F Remote Management (Config CRUD Only – Defers Listing Integration)
====================================================================================================================
ID: T14F
Title: Remote Config Management (S3 read-only config CRUD – listing integration deferred to T14J)
Development Context Prompt (repeat for this ticket):
Implement ONLY remote add/list/remove configuration persistence. Do NOT modify snapshot list yet. Redact secrets. No network listing beyond lightweight credential sanity (optional head bucket best-effort). Commit "T14F feat(remote): config management".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Split original combined config + listing into two smaller tickets (T14F config, T14J listing integration) to keep diffs minimal and reduce risk.
Context Recap: We need remote definitions (name -> endpoint, bucket, credentials) before we can implement remote snapshot enumeration.
Objective: Provide stable CRUD for remotes stored in config.json (remotes section) with validation & redaction support.
Rationale: Establishes foundation for later remote listing, pull, and push features while keeping initial change set very small (git-like incremental evolution).
Dependencies: T14A baseline (config manager present).
Preconditions: config.json writable.
Scope (In):
 - remote add <name> --endpoint= --bucket= --region= --key= --secret= [--path-style]
 - remote list (prints table or JSON of configured remotes with redacted credentials)
 - remote remove <name>
 - Validation: unique name; required fields non-empty; name pattern ^[a-z0-9][a-z0-9_-]{0,31}$.
 - Redaction: Show first 4 chars of key only; mask secret entirely (e.g. **** or 8 asterisks) in human output; omit secrets from JSON unless --show-secrets (NOT implemented now – keep simple).
Scope (Out): snapshot list --remote (T14J), remote pull (T14I), network bucket listing, credentials testing, caching.
Implementation Steps:
 1. Extend config_manager to support getRemotes(), saveRemotes().
 2. Implement three command classes remote_add.php, remote_list.php, remote_remove.php.
 3. Update command_router registration & help grouping.
 4. Add tests: add/remove cycle, duplicate add error, remove missing error, redaction in list, JSON output excludes secrets.
 5. Docs: update docs/remotes.md (config section) – note listing/pull in future tickets.
Data Structures: config.json { remotes: { name: {endpoint,bucket,region,key,secret,path_style?:bool} } }.
File Targets: config_manager.php, new command files, docs/remotes.md, tests/Remote/RemoteConfigTest.php.
Testing & Validation: PHPUnit tests cover all acceptance criteria.
Acceptance Criteria:
 - CRUD works; duplicate prevented; removal of existing succeeds.
 - Redacted output (no secret leakage) verified by test.
 - JSON output contains endpoint,bucket,region,path_style; omits key/secret or provides redacted forms consistently.
Edge Cases: Invalid name -> error code; missing required flags -> usage error (64); config.json absent -> auto create.
Rollback Strategy: Revert commit.
Risks & Mitigations: Secret leakage -> enforced redaction tests.
Follow-Up Tasks: T14J snapshot list remote integration; T14I remote pull.
Time Estimate: S.
Deliverables: Remote config commands + tests + docs update.
Agent Execution Checklist:
 - [ ] Implement config manager extensions
 - [ ] Add command classes
 - [ ] Add tests
 - [ ] Update docs
 - [ ] Run tests & commit (T14F feat(remote): config management)

====================================================================================================================
T14G Ephemeral Share (Peer-to-Peer Encoded Command, tar.gz)
====================================================================================================================
ID: T14G
Title: tsnap share (ephemeral encoded peer transfer)
Development Context Prompt (repeat for this ticket):
Implement minimal HTTP server & payload. Enforce TTL/max. Integrity check before import. Commit "T14G feat(share): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Uses export artifact; no central registry; leverages IntegrityService shortVerificationCode.
Context Recap: Need low-friction handoff after export/import exist; replaces removed legacy share tokens.
Objective: share create <uid> & share import <ENCODED> implementing encoded payload distribution.
Rationale: Quick human copy/paste distribution path.
Dependencies: T14D export, T14E import, T14C IntegrityService.
Preconditions: Snapshot present; export command available.
Scope (In): share_create_service, share_http_server (only /health & artifact GET), payload_builder, shortVerificationCode in IntegrityService, provenance file share_provenance.json, options (--listen=:0, --ttl, --max, --multi, --no-auto-export, --uid-strategy override for import).
Scope (Out): Auth tokens, tunnels, multi-artifact sessions, encryption.
Implementation Steps: If artifact missing auto export; start server on chosen port; compute payload; print command; count successful downloads; shutdown per limits; import side downloads, verifies sha256, calls ImportService (uid-strategy default new), writes share_provenance.json.
Data Structures: share_provenance.json {share_version:1, source_host, source_port, artifact, artifact_sha256, verification_code, original_uid, imported_uid, uid_strategy, received_utc, encoded_payload}.
File Targets: src/share/* new, src/cli/commands/share_share_create.php, share_share_import.php, modify command_router, integrity_service.php (add shortVerificationCode), tests/Share/*.
Testing & Validation: Round trip test (create server thread/process -> import); tamper detection; TTL expiry; multi limit; code format test; provenance file content.
Acceptance Criteria: One-line command works; tamper aborts; server enforces limits; provenance recorded; memory stable.
Edge Cases: Port busy; partial download; malformed payload; expired TTL.
Rollback Strategy: Remove share files & commands.
Risks & Mitigations: Hanging server—implement timeout & signal handling.
Follow-Up Tasks: Optional QR code output.
Time Estimate: M.
Deliverables: Share commands & tests.
Agent Execution Checklist:
 - [ ] Implement services
 - [ ] Add commands
 - [ ] Add tests
 - [ ] Run tests & commit (T14G feat(share): ephemeral peer share)

====================================================================================================================
T14H Metrics & GC Refinement (No Verify/Doctor)
====================================================================================================================
ID: T14H
Title: Metrics & GC (post share/remote integration, no verify/doctor)
Development Context Prompt (repeat for this ticket):
Provide aggregated stats & safe cleanup only. Guard against deleting active snapshots. Commit "T14H feat(maintenance): ...".
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Adjust metrics to rely solely on manifest-v2.json; GC cleans tmp & stale partial imports/export temps.
Context Recap: With verify/doctor removed, maintenance reduced to metrics & garbage collection.
Objective: Provide accurate aggregate stats & safe cleanup.
Rationale: Keep codebase lean while still giving user visibility & hygiene.
Dependencies: T14D (export manifests standardized), earlier tickets for baseline.
Preconditions: Snapshots exist.
Scope (In): metrics command outputs JSON & text: total_snapshots, total_bytes, newest_uid+created, largest_uid+bytes, average_size, compressed_count. gc command: [--dry-run] remove tmp/<uid> older than N hours (default 24), orphan export temp files *.tmp older than 1h.
Scope (Out): Object hash store cleanup (removed), remote GC.
Implementation Steps: Update existing metrics & gc implementations or re-write small services; tests verifying dry-run vs real; ensure atomic deletions.
Data Structures: None new; simple arrays.
File Targets: metrics command, gc command, tests/Maintenance/*.
Testing & Validation: Create fixture snapshots; run metrics; assert numbers; create temp dirs/files; run gc dry-run then live.
Acceptance Criteria: Commands run; gc removes expected entries; no removal of active snapshots.
Edge Cases: Permission errors (skip with warning); negative ages (ignore).
Rollback Strategy: Revert commit.
Risks & Mitigations: Accidental deletion—restrict deletion paths to temp/* and *.tmp in export dir.
Follow-Up Tasks: None.
Time Estimate: S.
Deliverables: Updated commands & tests.
Agent Execution Checklist:
 - [ ] Implement metrics updates
 - [ ] Implement gc updates
 - [ ] Add tests
 - [ ] Run tests & commit (T14H feat(maintenance): metrics & gc refinement)

====================================================================================================================
T14I Remote Pull (Download Snapshot From Remote S3 Into Local Store)
====================================================================================================================
ID: T14I
Title: Remote Pull (S3 object set → local snapshot directory)
Development Context Prompt (repeat for this ticket):
Implement ONLY read/download path from configured remote into local snapshots. Follow strict steps: restate, inspect s3_storage + snapshot_manager, plan minimal service, implement streaming download & integrity check, add CLI command remote pull, add tests (success, missing uid, checksum mismatch), commit "T14I feat(remote): pull snapshot". No scope creep (no push, no resume, no parallel multi-remote). Do not log secrets. Use IntegrityService for verification.
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Extends remote read-only capabilities with actual snapshot retrieval (pull) while still avoiding upload/push complexity.
Context Recap: T14F provided remote configuration and listing. Users now need to materialize a remote snapshot locally to restore or share without re-exporting. Remote buckets store snapshots under snaps/<uid>/ containing manifest-v2.json (preferred) or legacy meta.json + data files.
Objective: Add remote pull <remote> <uid|prefix> command to download a snapshot directory from a configured remote S3-compatible bucket into local snapshots/, verifying integrity when manifest-v2.json present. Support uid-strategy keep|new similar to import (when new, adjust manifest uid and write import_provenance_remote.json).
Rationale: Enables consumption of published snapshots/canonical catalogs; foundation for later push/diff features.
Dependencies: T14C IntegrityService, T14J remote listing (for UID resolution), manifest-v2 freeze (T14B).
Preconditions: Remote configured; remote list shows target snapshot; local filesystem writable; IntegrityService available.
Scope (In):
 - CLI: remote pull <remote> <uid|prefix> [--uid-strategy=keep|new] [--force] [--progress] [--out-dir=<override>]
 - UID resolution via listing (prefix unique match) or exact uid.
 - Streaming download of each file in snaps/<uid>/ excluding transient objects; create temp dir then atomic rename.
 - Integrity: If manifest-v2.json exists remotely, download it first, parse checksums, then for each file compute sha256 after download and compare. If only meta.json present, download files and compute checksums; generate new manifest-v2.json locally (mark provenance source="remote-meta-v1").
 - uid-strategy=new: generate new uid, adjust manifest (uid only), write import_provenance_remote.json {original_uid, strategy, source_remote, original_manifest_sha256? (if available), imported_uid, imported_utc}.
 - --force allows overwrite of existing snapshot when strategy=keep (else fail by default).
 - Progress: simple stderr line per file (name, bytes) unless --json.
 - JSON output: {action:"remote_pull", remote, uid, final_uid, files, bytes, verified, provenance_path?}.
Scope (Out): push/upload, multipart parallelism, resumable partial downloads, encryption, caching, metrics.
Implementation Steps:
 1. Implement RemotePullService (src/remote/remote_pull_service.php) with pull(RemoteConfig, uidOrPrefix, options)->Result.
 2. Add uid resolution helper using existing listing logic (fetch manifests list & match prefix).
 3. Download manifest-v2.json (if present) then iterate expected files list; else build file list by listing objects under snaps/<uid>/ and excluding manifest/meta.
 4. For each file: stream to temp path (use fopen with read/write chunk 64KB) computing sha256 via IntegrityService hashStream.
 5. Compare hashes (if manifest present). Accumulate size & file count.
 6. If manifest absent: after files downloaded compute metadata & write generated manifest-v2.json (schema_version=2) using existing structure + computed checksums; include provenance.remote_source_version=1.
 7. Apply uid strategy new (rename dir + modify manifest) + write import_provenance_remote.json.
 8. Atomic promote temp dir to snapshots/<final_uid> (fail if exists unless --force when keep).
 9. CLI command remote_pull.php delegates to service, handles JSON/text output & exit codes (hash mismatch -> non-zero).
 10. Tests: success path with manifest; path with meta-only; prefix ambiguous error; uid exists no --force error; uid-strategy=new provenance; checksum tamper (simulate by altering downloaded file after fetch to ensure detection? or mock remote returning wrong bytes) -> failure.
Data Structures / Schemas:
 - import_provenance_remote.json {original_uid, imported_uid, strategy, source_remote, original_manifest_sha256?, imported_utc, source_type:"manifest-v2"|"meta-v1"}.
 - RemotePullResult (array) {success, original_uid, final_uid, files, bytes, verified, provenance_path?}.
File Targets: src/cli/commands/remote_pull.php (new), src/remote/remote_pull_service.php (new), modify command_router.php, possibly extend remote listing helper, tests/Remote/RemotePull*.
Testing & Validation: PHPUnit tests with fake_storage implementing list/get and controllable object contents; verify hash mismatch triggers failure; ensure new uid path produces provenance file; ensure force overwrites.
Acceptance Criteria:
 - remote pull downloads snapshot into local snapshots/<uid> or new uid when requested.
 - Integrity verified when manifest present; mismatches abort and cleanup temp.
 - meta-only remote snapshot yields synthesized manifest-v2.json locally.
 - Provenance file written only on uid-strategy=new.
 - No secret logging (inspect test output).
 - Command JSON output matches spec.
Edge Cases: Ambiguous prefix (error); missing snapshot (error); zero-byte file; manifest lists file absent remotely (error & abort); local dir already exists (error unless --force keep or new strategy different uid).
Rollback Strategy: Revert commit.
Risks & Mitigations: Large snapshots memory—use streaming; partial failure leaves temp dir—cleanup on exception.
Follow-Up Tasks: Future push, differential sync.
Time Estimate: M.
Deliverables: RemotePullService, CLI command, tests, updated docs/remotes.md (add pull usage).
Agent Execution Checklist:
 - [ ] Implement service
 - [ ] Add CLI command & router entry
 - [ ] Add tests (manifest, meta-only, new uid, force, mismatch, ambiguous)
 - [ ] Update docs/remotes.md
 - [ ] Run tests & commit (T14I feat(remote): pull snapshot)

====================================================================================================================
T14J Snapshot List Remote Integration (Remote Enumeration in snapshot list)
====================================================================================================================
ID: T14J
Title: snapshot list --remote (Enumerate Remote Snapshots via S3)
Development Context Prompt (repeat for this ticket):
Integrate remote listing into snapshot list command with minimal code. Use existing remote configs. Streaming list (paginate via batch fetch of object keys). No caching layer. Commit "T14J feat(remote): snapshot list integration". Keep diff small: reuse helper functions where possible; no speculative abstractions.
Project Name: Snappy
Project Purpose: (Universal Context)
Rewrite Note: Separated from T14F to keep earlier remote config change small; this mirrors git’s incremental feature addition philosophy.
Context Recap: Remote configurations exist (T14F). Need to allow developers to view snapshots stored in remote S3 buckets without local download.
Objective: Extend snapshot list command with --remote <name> to list remote snapshots (uid, created, message first line, size if derivable) by scanning snaps/<uid>/manifest-v2.json or meta.json fallback.
Rationale: Enables discovery of remotely published snapshots; essential for deciding which to pull (T14I) or share further.
Dependencies: T14F (remote config), T14B (manifest freeze), T14C (IntegrityService not strictly required but available).
Preconditions: At least one remote configured; remote bucket accessible; PHP has network access.
Scope (In):
 - Flag: snapshot list --remote <name> [--limit N] [--full]
 - S3 listing: list objects with prefix snaps/ (cap *roughly* 20x limit then filter) to minimize requests.
 - For each candidate directory (snaps/<uid>/): attempt to fetch manifest-v2.json first; fallback to meta.json.
 - Extract fields: uid, created_utc (or created), message (first line unless --full then replace newlines with ' | '), snapshot_type, size_total_bytes (if available), optional tags (ignored in output for simplicity now).
 - Output formatting consistent with local list; distinguish remote mode (e.g. add column REMOTE=remoteName or annotate in JSON).
 - JSON output: {remote:"name", snapshots:[...]} preserving existing local schema plus remote.
 - Error handling: remote not found -> usage error; network/list error -> non-zero with message; partial failures (corrupt manifest) skip entry with warning (unless all fail -> error).
Scope (Out): Multi-remote aggregation, caching, parallel forks, progress display, colorization changes.
Implementation Steps:
 1. Modify snapshot_list command to parse --remote flag (mutually exclusive with local listing; if provided ignore local).
 2. Implement simple RemoteLister (src/remote/remote_lister.php) encapsulating listing & manifest/meta fetch logic returning normalized array.
  2a. Normalization: {uid, created, type, message, size_bytes?}
 3. Inject RemoteLister into command (construct on demand to keep wiring simple).
 4. Add tests: RemoteListEmptyTest (no snapshots), RemoteListWithManifestsTest, RemoteListFallbackMetaTest (only meta.json), RemoteListCorruptManifestSkipsTest, RemoteListLimitTest.
 5. Update docs/remotes.md with usage examples.
Data Structures: RemoteLister::list(RemoteConfig $cfg, int $limit, bool $full): array.
File Targets: snapshot_list command file, src/remote/remote_lister.php (new), tests/Remote/RemoteListSnapshotsTest.php (and variants), docs/remotes.md.
Testing & Validation: Fake S3 storage stub to supply objects & JSON bodies; ensure limit enforced; ensure message truncation vs full.
Acceptance Criteria:
 - snapshot list --remote <name> prints expected table / JSON.
 - Limit respected; corrupted entries skipped with warning (still exit 0 if at least one good entry or zero legitimate snapshots). If all entries unreadable -> non-zero.
 - No secret leakage (assert test output).
 - Local listing behavior unchanged when --remote absent.
Edge Cases: Empty bucket; manifest present but missing fields; meta.json missing message -> display empty; large message truncated properly.
Rollback Strategy: Revert commit.
Risks & Mitigations: Performance for huge buckets -> initial overscan factor; documented future caching.
Follow-Up Tasks: T14I remote pull (download) leverages same normalization.
Time Estimate: S.
Deliverables: Remote listing integration; tests; docs update.
Agent Execution Checklist:
 - [ ] Implement RemoteLister
 - [ ] Extend snapshot_list command
 - [ ] Add tests (manifests, meta fallback, corrupt skip, limit)
 - [ ] Update docs/remotes.md
 - [ ] Run tests & commit (T14J feat(remote): snapshot list integration)

====================================================================================================================
Backlog / Stretch (Concept Summaries, Not Formal Tickets)
====================================================================================================================
 - Remote push/pull synchronization (authenticated upload)
 - Optional artifact signing (public key) post stable adoption
 - Differential snapshot export (binary diff) for large datasets
 - Catalog indexing service (external) consuming remote listing output
 - Encryption at rest for artifact on disk
 - QR code output for share create
 - Parallel multi-remote snapshot listing with caching
