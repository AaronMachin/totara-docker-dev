Snappy Roadmap
==============

Purpose
-------
Provide an actionable, phased roadmap focused on making Snappy the best local developer database snapshot tool with strong metadata and secure one‑time sharing. Emphasis: simplicity, reliability, maintainability, great UX. Policies beyond simple retention are intentionally deferred unless they add clear local value.

NOTE: This is a clean rewrite of a prototype; we will introduce breaking changes directly. No deprecation stubs or notices are required and legacy command names will be replaced outright when redesigned.

Guiding Principles
------------------
- Local‑first: Fast, deterministic, inspectable snapshot storage.
- Minimal mental overhead: Fewer core concepts; rich metadata without complexity.
- Trust & integrity: Strong verification; security only where it matters (shares & transfers).
- Progressive enhancement: Each milestone independently valuable.
- Clear separation: Domain logic vs CLI vs infrastructure.
- Automation friendly: Machine‑readable outputs; stable command surface (post‑refactor).

Milestone Overview (High Level)
-------------------------------
0. Baseline & Freeze
1. Core Infrastructure Refactor (autoloading, structure, error model)
2. Manifest v2 & Metadata Enrichment
3. Snapshot Creation Improvements (providers, reliability, compression)
4. Index & Fast Listing (local + remote summary index)
5. CLI Experience Redesign
6. Secure One‑Time Share Mechanism
7. Optional Encryption + Integrity Enhancements (share/export scope only)
8. Tagging & Search Filters
9. Simple Retention (count/age prune) – optional utility
10. Test & CI Foundation
11. Optional Content Addressing / Dedup (opt‑in experiment)
12. Diagnostics & Observability (doctor, metrics)
13. Documentation & Developer Onboarding
14. Backlog / Stretch Ideas

Ticket Format Legend
--------------------
Each ticket lists: Description, Rationale, Scope, Out of Scope, Dependencies, Deliverables, Acceptance Criteria, Suggested AI Prompt, Estimation (relative), Risks / Mitigations.

---
Milestone 0: Baseline & Freeze
------------------------------
T0.1 Baseline Tag & Inventory
- Description: Create a git tag (baseline_pre_refactor) and capture current command outputs + sample snapshots.
- Rationale: Safety net before structural change.
- Scope: Tag repo, export current README, list of commands, one sample snapshot folder.
- Out of Scope: Any code change.
- Dependencies: None.
- Deliverables: Tag, /docs/baseline/ (outputs & snapshot manifest copies).
- Acceptance: Tag exists; directory populated.
- Suggested AI Prompt: "Create a docs/baseline directory and add current CLI help outputs and one sample meta.json." 
- Estimation: XS.
- Risks: None.

---
Milestone 1: Core Infrastructure Refactor
-----------------------------------------
T1.1 Introduce Composer & PSR-4
- Description: Add composer.json, PSR-4 autoload (Snappy\\ => src/).
- Rationale: Standard tooling, easier extension.
- Scope: composer.json, update bootstrap to prefer Composer autoloader, fallback to legacy if absent.
- Out: Packaging for distribution (later).
- Dependencies: T0.1.
- Deliverables: composer.json, vendor/autoload.php usage.
- Acceptance: All existing commands still function.
- AI Prompt: "Add composer.json with PSR-4 for Snappy namespace and adjust tsnap_cli.php to use it." 
- Est: S.
- Risks: Path issues (mitigate: fallback autoloader retained temporarily).

T1.2 Directory Restructure
- Description: Reorganize into Domain/, Application/, Infrastructure/, CLI/, Support/.
- Rationale: Separation of concerns.
- Scope: Move classes; introduce SnapshotService orchestrator.
- Out: Feature changes.
- Dependencies: T1.1.
- Deliverables: New structure; legacy thin facades.
- Acceptance: Tests/CLI still pass.
- Est: M.
- Risks: Merge conflicts; mitigate by doing early.

T1.3 Unified Error & Exception Hierarchy
- Description: Create base SnappyException + specific types (SnapshotNotFound, RemoteError, ValidationError).
- Rationale: Cleaner error handling & exit codes.
- Scope: Exceptions + mapping to exit codes.
- Out: Retry logic.
- Dependencies: T1.2.
- Deliverables: New exception classes; CLI adapter.
- Acceptance: Known errors produce friendly messages + nonzero exit.
- Est: S.

T1.4 Central Process Wrapper
- Description: Wrapper for external commands (tdb) capturing stdout/stderr and exit code.
- Rationale: Reliability; future provider substitution.
- Scope: ProcessRunner class; replace system() usage.
- Out: Async execution.
- Dependencies: T1.2.
- Deliverables: ProcessRunner + integration.
- Acceptance: Snapshot create still works; failure logs captured.
- Est: S.

---
Milestone 2: Manifest v2 & Metadata Enrichment
---------------------------------------------
T2.1 Manifest Schema v2 Draft
- Description: Define new JSON schema (manifest_v2.json) with enriched fields.
- Rationale: Future-proof metadata.
- Scope: Fields: schema_version, uid, created_utc, snapshot_type, message, tags[], files[], checksums{file:algo:value}, size_bytes, db.engine/version, compression, provenance, custom_metadata.
- Out: Incremental base_uid (later).
- Dependencies: T1.*.
- Deliverables: /schema/manifest_v2.json + docs.
- Acceptance: Schema file validated (self-check script).
- Est: S.

T2.2 Dual Write (v1 + v2)
- Description: On create, write both meta.json (legacy) and manifest-v2.json.
- Rationale: Transitional safety.
- Scope: SnapshotManager update.
- Out: Reading v2 exclusively.
- Dependencies: T2.1.
- Deliverables: Modified create path.
- Acceptance: Both files present; tests read old still.
- Est: S.

T2.3 Unified Read via Adapter
- Description: Adapter to read either v1 or v2 returning normalized Snapshot object.
- Rationale: Simplifies later refactors.
- Scope: SnapshotLoader.
- Dependencies: T2.2.
- Deliverables: Loader class + tests.
- Acceptance: Listing uses adapter; results identical.
- Est: M.

---
Milestone 3: Snapshot Creation Improvements
-------------------------------------------
T3.1 Dump Provider Interface
- Description: IDumpProvider (detect(), dump(uid, targetDir)). Default TdbShellProvider.
- Rationale: Extensibility.
- Scope: Interface + default provider + provider registry.
- Dependencies: T1.4.
- Deliverables: Interface, default impl.
- Acceptance: Snapshot create path unchanged externally.
- Est: M.

T3.2 Compression Support (gzip baseline)
- Description: Optional gzip compression of dump before storage; record in manifest.
- Rationale: Space saving.
- Scope: --compress flag; default off.
- Out: Advanced algorithms (later).
- Dependencies: T2.2.
- Deliverables: Compressed artifact + metadata.
- Acceptance: Checksums validated post decompress.
- Est: S.

T3.3 Exit Code & Log Capture
- Description: If dump fails, store logs in logs/dump.log; abort manifest write.
- Rationale: Debuggability.
- Dependencies: T1.4.
- Deliverables: logs/ folder creation.
- Acceptance: Failing dump returns nonzero exit with message referencing log path.
- Est: S.

---
Milestone 4: Index & Fast Listing
----------------------------------
T4.1 Local Snapshot Index
- Description: Maintain snapshots/index.json (summaries) updated on create/delete.
- Rationale: O(1) listings.
- Scope: IndexManager; rebuild command.
- Dependencies: T2.3.
- Deliverables: index.json + rebuild CLI.
- Acceptance: list command uses index (bench faster >30 snapshots).
- Est: M.

T4.2 Remote Summary Index (Optional)
- Description: Remote file snaps/index.json with minimal rows; updated on push.
- Rationale: Avoid remote directory scans.
- Scope: Push updates remote index (lock minimization).
- Dependencies: T4.1.
- Deliverables: Remote index update logic.
- Acceptance: list --remote uses summary then selective meta fetch (none needed for basic view).
- Est: M.

---
Milestone 5: CLI Experience Redesign
------------------------------------
T5.1 Command Namespace Restructure
- Description: Introduce new verbs: snapshot create/list/show, share create/list, prune, verify, config get/set (replace legacy commands outright; no deprecation layer maintained).
- Rationale: Clarity & discoverability.
- Scope: New command handlers; remove old single-word aliases as breaking change is acceptable.
- Dependencies: Earlier milestones.
- Deliverables: Updated tsnap entrypoint dispatch.
- Acceptance: New names function; old legacy commands are removed and documented in README update.
- Est: M.

T5.2 JSON / Quiet / Color Flags
- Description: Global output mode flags.
- Rationale: Automation.
- Scope: OutputFormatter abstraction.
- Dependencies: T5.1.
- Deliverables: --json output for listing, show.
- Acceptance: Machine-readable stable JSON.
- Est: S.

T5.3 Improved Help & Examples
- Description: Auto-generate help from command metadata (only new command set; no legacy alias references).
- Rationale: UX.
- Dependencies: T5.1.
- Est: XS.

---
Milestone 6: Secure One-Time Share Mechanism
-------------------------------------------
T6.1 Share Token Model
- Description: Generate single-use token referencing snapshot + expiry.
- Rationale: Secure ephemeral sharing.
- Scope: Token registry (local JSON store), token generate CLI, mark consumed.
- Out: Network server (later optional).
- Dependencies: T5.*.
- Deliverables: share create <uid> --expire=1h output token.
- Acceptance: Token listed & flagged consumed after use.
- Est: M.

T6.2 Share Retrieval Flow
- Description: Command share fetch <token> to resolve + pull from remote or local path.
- Rationale: Consumer workflow.
- Scope: Resolve token -> copy/export snapshot directory/archive.
- Dependencies: T6.1.
- Deliverables: share fetch command.
- Acceptance: After fetch token invalid.
- Est: S.

T6.3 Optional Archive Export for Share
- Description: share create --as-archive to produce tar.gz and checksum.
- Rationale: Portability.
- Dependencies: T3.2.
- Deliverables: Archive + manifest, checksum file.
- Acceptance: Archive re-importable.
- Est: S.

T6.4 (Optional) Pre-signed URL Support
- Description: If remote is S3, create pre-signed GET for each artifact (duration = token expiry) instead of copying.
- Rationale: Avoid local relay.
- Dependencies: Existing S3 credentials & presign util.
- Deliverables: Pre-signed links in token metadata.
- Acceptance: share fetch downloads directly from S3.
- Est: M.

---
Milestone 7: Optional Encryption + Integrity Enhancements
---------------------------------------------------------
T7.1 Archive-Level Encryption (Share Only)
- Description: Encrypt exported archive with passphrase (scrypt + secretbox) supplied interactively or via env.
- Rationale: Protect shared artifact transiently.
- Scope: snappy encrypt/decrypt utils.
- Dependencies: T6.3.
- Deliverables: Encrypted .snappy.enc + manifest flag encryption: {algo, kdf}.
- Acceptance: Decryption restores identical checksum.
- Est: M.

T7.2 Manifest Signature (Optional)
- Description: HMAC manifest with locally stored key (not high security; tamper flag only).
- Rationale: Integrity.
- Dependencies: T2.3.
- Deliverables: manifest-v2.json + manifest-v2.sig.
- Acceptance: verify flags mismatch if tampered.
- Est: S.

---
Milestone 8: Tagging & Search
-----------------------------
T8.1 Tag Add/Remove
- Description: snapshot tag <uid> add foo.
- Rationale: Organization.
- Scope: Manifest v2 update + index reflect tags.
- Dependencies: T4.1.
- Deliverables: Tag commands.
- Acceptance: list --filter tag=foo works.
- Est: S.

T8.2 Filter Syntax
- Description: Basic AND conditions field=value (tag=, type=, age<, age>). Parser.
- Dependencies: T8.1.
- Deliverables: Filter evaluator.
- Acceptance: list --filter 'tag=foo AND age<7d'.
- Est: M.

---
Milestone 9: Simple Retention (Optional)
---------------------------------------
T9.1 Prune By Count
- Description: prune --keep-last=50.
- Rationale: Manage disk usage.
- Scope: Sort by created; delete older beyond threshold.
- Dependencies: T4.1.
- Deliverables: Prune command (dry-run default).
- Acceptance: Dry-run lists; confirm deletes.
- Est: S.

T9.2 Prune By Age
- Description: prune --max-age=30d.
- Dependencies: T9.1.
- Acceptance: Snapshots older are removed.
- Est: XS.

---
Milestone 10: Test & CI Foundation
----------------------------------
T10.1 Test Framework Setup
- Description: Add PHPUnit or Pest, bootstrap.
- Rationale: Regression prevention.
- Dependencies: T1.1.
- Deliverables: phpunit.xml, first unit tests.
- Acceptance: CI green.
- Est: S.

T10.2 Integration Tests (Local)
- Description: Create -> tag -> push (to local stub) -> pull -> verify.
- Dependencies: T10.1.
- Est: M.

T10.3 S3 MinIO Integration Test
- Description: Spin MinIO docker, run test scenario.
- Dependencies: T10.2.
- Est: M.

T10.4 Share Token Flow Test
- Description: Generate, fetch, ensure single-use.
- Dependencies: T6.2.
- Est: S.

---
Milestone 11: Optional Content Addressing / Dedup
-------------------------------------------------
T11.1 Hash Store Prototype
- Description: Store artifacts by sha256 under objects/ prefix, manifest references logical names + hash.
- Rationale: Space optimization.
- Dependencies: T2.3.
- Deliverables: Experimental flag --content-addressable.
- Acceptance: Duplicate file not re-copied physically.
- Est: M.

T11.2 GC for Unreferenced Objects
- Description: Scan manifests; delete orphans.
- Dependencies: T11.1.
- Est: S.

---
Milestone 12: Diagnostics & Observability
-----------------------------------------
T12.1 Doctor Command
- Description: doctor runs checks (disk space, config validity, remote reachability, index integrity).
- Dependencies: T4.1.
- Est: S.

T12.2 Metrics Summary
- Description: snapshot stats (count by type, total size, last N age histogram).
- Dependencies: T4.1.
- Est: XS.

---
Milestone 13: Documentation & Onboarding
----------------------------------------
T13.1 New README Structure (Deferred until base done)
- Description: Quickstart, Concepts, Commands, Sharing, Index, Roadmap link.
- Dependencies: Post Milestone 5 stable CLI.
- Est: S.

T13.2 Developer Guide
- Description: docs/dev/ with architecture, extension points.
- Dependencies: T1.* & T2.*.
- Est: S.

T13.3 User Guide for Sharing
- Description: docs/share/ examples, security notes.
- Dependencies: T6.*.
- Est: XS.

---
Milestone 14: Backlog / Stretch Ideas
-------------------------------------
- Incremental snapshots (binlog / WAL referencing) – requires DB-specific design.
- Streaming restore (pipe gzip directly into db import).
- Multi-artifact snapshots (DB + file tree) – extend manifest.
- Anonymization transforms (PII masking) – transformation pipeline.
- REST microservice wrapper – optional remote API.
- Web UI for browsing.
- zstd compression & pluggable compression providers.
- BLAKE3 checksums for speed.
- Pre-signed multi-part parallel upload optimization.

Cross-Cutting Non-Functional Goals
----------------------------------
- Consistent exit codes documented in docs/exit-codes.md.
- All JSON outputs stable & versioned (add output_version field when needed).
- Logging levels: error, warn, info, debug (controlled by --debug or env).
- Performance target: list 500 snapshots < 150ms locally with index.

Dependency Graph (Simplified)
-----------------------------
Baseline -> Core Refactor -> Manifest v2 -> Providers/Compression -> Index -> CLI Redesign -> Sharing -> Encryption -> Tagging -> Retention -> Tests (some parallel) -> Content Addressing -> Diagnostics -> Docs.

Allocation Guidance
-------------------
- Parallelizable early: Manifest schema design (T2.1) & composer setup (T1.1).
- Keep T4 (index) ahead of features needing fast search (tagging, retention).
- Tests introduced no later than completion of Milestone 5 to prevent regression in share features.

Suggested Initial Execution Order (Week 1–2)
-------------------------------------------
1. T0.1, T1.1, T1.4
2. T1.2, T1.3
3. T2.1, T2.2, T2.3
4. T3.1, T3.3, T3.2
5. T4.1

Example AI Prompts Collection (Reusable)
---------------------------------------
- "Refactor system() calls in snapshot creation to use a new ProcessRunner that captures status and logs." (T1.4)
- "Add manifest_v2.json schema file with specified fields and implement dual write in SnapshotManager." (T2.1/T2.2)
- "Introduce a SnapshotLoader that can read legacy meta.json or manifest-v2.json and returns a normalized array." (T2.3)
- "Implement local snapshot index manager writing snapshots/index.json and modify list to consume it." (T4.1)
- "Add share create and share fetch commands with single-use token storage in .snappy/shares.json." (T6.1/T6.2)
- "Add gzip compression option (--compress) to snapshot create and record details in manifest v2." (T3.2)
- "Implement prune command supporting --keep-last and --max-age with dry-run mode." (T9.1/T9.2)
- "Add tagging support (snapshot tag add/remove) and filtered listing via --filter tag=name." (T8.1)
- "Create doctor command to validate configuration, index integrity, and remote connectivity." (T12.1)

Risks & Mitigations Summary
---------------------------
- Structural churn: Mitigate with early refactor (Milestone 1) + adapter pattern.
- Feature creep: Roadmap explicitly defers complex policy/transform engine.
- Token security: Use cryptographically secure random, store hash rather than raw token when feasible.
- Index corruption: Provide rebuild command; atomic write (tmp + rename).

Exit Criteria for "Modern Core" (First Stable Post-Refactor Release)
--------------------------------------------------------------------
Includes: Milestones 1–6 + 8 + 10 (core tests) + 13 basic README.

Notes on Policies
-----------------
Advanced policies deferred. Only simple retention (count/age) implemented to avoid conceptual overhead. Future design to revisit if anonymization or compliance needs emerge.

End of Roadmap

Expanded AI Execution Specifications
===================================
Purpose of This Section
-----------------------
This section restates and greatly expands every ticket so that an autonomous AI agent (with zero prior context other than a copy of the repository) can execute the task without ambiguity. Each task includes: Context Recap, Objective, Rationale, Preconditions / Dependencies, Scope (In), Explicit Out of Scope, Detailed Implementation Steps, Data / Structures, File Targets & Creation Rules, Testing & Validation Plan, Acceptance Criteria (Verbose), Failure / Rollback Guidance, Edge Cases, Follow‑Up Tasks, and a Direct Agent Instruction Block.

Global Project Context (Repeatable)
-----------------------------------
Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) with metadata. Key goals: simplicity, reliability, metadata richness, fast listing, one-time secure sharing, and maintainable architecture. Modernization tasks include reorganizing code, enriching manifest format, improving CLI ergonomics, enabling compression, indexing snapshots, and enabling secure single-use sharing tokens. Avoid heavy policy engines; retain minimal retention logic only.

Legend for Estimation (Repeat)
- XS: <= 30 minutes
- S: 30–90 minutes
- M: 1.5–4 hours
- L: 4–8 hours
- XL: > 1 day

-------------------------------------------------------------------
Milestone 0: Baseline & Freeze (Expanded)
-------------------------------------------------------------------
T0.1 Baseline Tag & Inventory (Expanded)
Context Recap: Code is pre‑refactor. We need a reproducible snapshot of current behavior.
Objective: Create a permanent baseline reference to compare future changes and enable rollback.
Rationale: Ensures reproducibility and diff auditing after structural refactors.
Dependencies: None.
Scope (In): Create git tag, capture current CLI help outputs, store sample snapshot meta.json, store directory tree summary.
Out of Scope: Refactoring, code changes beyond new docs assets.
Implementation Steps:
 1. Ensure working tree clean (abort if uncommitted changes, instruct user if so).
 2. Run: `php bin/helpers/snappy/tsnap_cli.php help` (or existing executable alias) and capture full text.
 3. Run each existing command with --help: snap, push, pull, list, remote, cat, get, fetch, share (if present), help.
 4. Create directory docs/baseline/ (create intermediate directories if missing).
 5. Save each help output into separate files: docs/baseline/help_<command>.txt.
 6. Generate tree (limited depth) for bin/helpers/snappy/src > docs/baseline/tree.txt.
 7. Create a dummy snapshot (if environment supports tdb) OR fabricate a minimal meta.json using current schema to represent one snapshot. Save as docs/baseline/sample_meta.json.
 8. Add README.md copy as docs/baseline/README.pre_refactor.md.
 9. Commit changes.
 10. Create annotated tag baseline_pre_refactor with message outlining commit hash and timestamp.
Data / Structures: Simple text files.
File Targets: docs/baseline/* (new); no edits to existing PHP.
Testing Plan: Verify all help files non-empty; tag exists (`git tag -l`).
Acceptance Criteria:
 - Tag baseline_pre_refactor present and points to current HEAD.
 - docs/baseline contains per-command help, tree.txt, sample_meta.json, README.pre_refactor.md.
 - No modifications to runtime code apart from added files.
Rollback: Delete docs/baseline and remove tag if mis-tagged; re-run.
Edge Cases: Missing tdb leads to fabricated sample meta; document this inside sample_meta.json with a note field.
Follow-Up: Reference baseline folder in future PRs when asserting no behavioral regression.
Agent Instruction Block:
  - Create docs/baseline directory.
  - Capture help outputs and tree snapshot.
  - Create sample meta if real snapshot cannot be made.
  - Commit and tag.

---
Milestone 1: Core Infrastructure Refactor (Expanded)
-------------------------------------------------------------------
T1.1 Introduce Composer & PSR-4 (Expanded)
Context: Currently custom autoloader; modern tooling absent.
Objective: Add composer.json enabling PSR‑4 autoload for Snappy namespace; integrate into startup.
Rationale: Standardization, dependency management, static analysis later.
Dependencies: Baseline advisable.
Scope (In): composer.json, autoload config, updated tsnap_cli.php to prefer vendor/autoload.php.
Out of Scope: Publishing to Packagist, refactoring namespace conventions beyond loader.
Implementation Steps:
 1. Create composer.json at project root or snappy root (decide: for isolation, place inside bin/helpers/snappy/). If nested, ensure running composer install there works.
 2. Define: {
      "name": "snappy/tool", "type": "library", "autoload": {"psr-4": {"Snappy\\": "src/"}}, "require": {"php": ">=8.1"}
    }
 3. Run composer install (if environment allows) to produce vendor/autoload.php.
 4. Modify tsnap_cli.php: If file_exists(__DIR__.'/vendor/autoload.php') include it; else fallback to existing snappy_autoload.php.
 5. Update roadmap or internal note referencing new loader.
 6. Ensure no fatal errors when executing existing commands.
File Targets: composer.json, tsnap_cli.php.
Testing: Run `php tsnap_cli.php help`; confirm success.
Acceptance Criteria: Autoload resolves existing classes; no change in command outputs; vendor/ ignored by .gitignore (add if missing).
Rollback: Delete composer.json and vendor directory; restore tsnap_cli.php edits.
Edge Cases: Global composer constraints conflict—document or isolate.
Agent Instruction Block: Add composer file, adjust CLI bootstrap, verify execution.

T1.2 Directory Restructure (Expanded)
Context: Current flat src folders; need conceptual separation.
Objective: Introduce Domain/, Application/, Infrastructure/, CLI/, Support/ directories without breaking functionality.
Rationale: Improves maintainability and onboarding.
Dependencies: T1.1 (autoload in place) recommended.
Scope (In): Move existing classes into new logical dirs; add transitional class maps or re-export if necessary.
Out of Scope: Renaming classes fully (done later as needed) or heavy refactors.
Implementation Steps:
 1. Plan mapping: 
    - snapshot/* -> Domain/Snapshot/
    - storage/* -> Infrastructure/Storage/
    - config/* -> Infrastructure/Config/
    - util/* -> Support/Util/
    - cli/* -> CLI/Core/ (commands under CLI/Commands/)
 2. Move files physically; update namespaces accordingly (e.g., namespace Snappy\Snapshot becomes Snappy\Domain\Snapshot). Option: add legacy shim classes (old namespace classes extending new) for interim backward compatibility.
 3. Update all references (search & replace namespace strings).
 4. Run basic command to ensure no autoload failures.
 5. Document mapping in docs/dev/architecture.md (create file if missing).
Data Structures: None changed.
Testing: Execute: help, snap, list commands.
Acceptance Criteria: Commands function; new directory layout present; no fatal missing class errors.
Rollback: Git revert of changeset.
Edge Cases: Overlapping class names—ensure unique destinations.
Agent Instruction Block: Perform systematic namespace update and validate execution.

T1.3 Unified Error & Exception Hierarchy (Expanded)
Context: RuntimeException scattered; inconsistent error semantics.
Objective: Provide consistent domain-specific exceptions mapped to exit codes.
Rationale: Better UX, scripting reliability.
Dependencies: T1.2 for stable file locations.
Scope (In): Introduce base SnappyException + specific subclasses; modify major code paths to throw them; central CLI catch block mapping to exit codes.
Out of Scope: Exhaustive replacement in minor utility functions (can phase in).
Implementation Steps:
 1. Create Support/Exception/ directory.
 2. Add classes: SnappyException (abstract), SnapshotNotFoundException, RemoteException, ValidationException, ProcessFailedException, ConfigException.
 3. Add exit code map (Support/Exception/ExitCodes.php).
 4. Update snapshot_manager, remote_registry, config_manager to throw new exceptions instead of generic RuntimeException where meaningful.
 5. Adjust CLI dispatcher to catch SnappyException and print standardized: "ERROR (<code>): <message>" to STDERR.
 6. Provide getErrors() method or context array optional property on exceptions for future JSON mode.
Testing: Force known error (pull non-existent UID) and verify formatted output + exit status.
Acceptance Criteria: Controlled errors use new hierarchy; exit codes documented in docs/exit-codes.md.
Rollback: Replace uses with RuntimeException and remove new files.
Edge Cases: Uncaught generic exceptions still bubble; ensure a final catch converts them to code 99 (unknown).
Agent Instruction Block: Implement hierarchy and refactor key throw sites.

T1.4 Central Process Wrapper (Expanded)
Context: system() calls with silent redirection; no error capture.
Objective: Provide robust ProcessRunner to execute external commands (e.g., tdb) capturing stdout/stderr, exit code, duration.
Rationale: Reliability, diagnostics, future provider extensibility.
Dependencies: None (but after directory restructure path stable).
Scope (In): New Support/Process/ProcessRunner.php + interface; integrate into snapshot creation path.
Out of Scope: Async or streaming incremental progress.
Implementation Steps:
 1. Define interface ProcessResult { exitCode, stdout, stderr, durationMs } (value object class).
 2. Implement run(array $cmd, array $envOverrides = [], ?int $timeoutSeconds = null): ProcessResult.
 3. Use proc_open with pipes; capture output; measure time.
 4. Replace system() call in snapshot_manager::create_sql_backup with ProcessRunner usage.
 5. If exitCode != 0, throw ProcessFailedException including truncated stderr (first 10 lines) and full log available if logging step done (T3.3 will expand).
Testing: Simulate failure by running invalid command path (temporarily override tdb binary variable) verifying exception.
Acceptance Criteria: Snapshot creation still works; failure surfaces error text.
Rollback: Revert snapshot_manager modifications and remove ProcessRunner.
Edge Cases: Large output—truncate stored in memory after e.g. 1MB.
Agent Instruction Block: Add ProcessRunner and integrate into snapshot_manager.

-------------------------------------------------------------------
Milestone 2: Manifest v2 & Metadata Enrichment (Expanded)
-------------------------------------------------------------------
T2.1 Manifest Schema v2 Draft (Expanded)
Context: Current meta.json minimal; needs richer metadata and future compatibility.
Objective: Create schema file describing extended manifest structure.
Rationale: Enables tooling validation & subsequent dual write.
Dependencies: None strictly, but easier after T1.*.
Scope (In): schema/manifest_v2.json + doc comments + optional JSON schema validation script.
Out of Scope: Changing existing meta.json reading yet.
Implementation Steps:
 1. Create directory schema/ (if absent) inside snappy root.
 2. Define JSON Schema draft 2020-12 or simpler self-defined conventions.
 3. Fields required:
    - schema_version (int, fixed 2)
    - uid (string)
    - created_utc (ISO 8601 Z)
    - snapshot_type (string: sql)
    - message (string)
    - tags (array[string], default [])
    - files (array[object]): [{name, size_bytes, compressed, compression_algo?}]
    - checksums (object): {algo: "sha256", files: {<name>: <hash>}}
    - size_total_bytes (int)
    - db (object: engine, version?) optional
    - provenance (object: {command_line, host, user, php_version})
    - compression (object: enabled(bool), algo?, ratio?)
    - custom_metadata (object, free-form)
 4. Provide examples section in file or separate example manifest (manifest_v2.example.json).
 5. (Optional) Add validation script validate_manifest.php.
Testing: Validate example file with the schema using script.
Acceptance Criteria: schema file exists; example validates; documented in roadmap or architecture doc.
Rollback: Remove schema/ files.
Edge Cases: Minimal manifest (omit optional fields) still passes required constraints.
Agent Instruction Block: Create schema and example with validation script.

T2.2 Dual Write (v1 + v2) (Expanded)
Context: Need transitional period where legacy tools still read meta.json.
Objective: On snapshot creation, produce both legacy meta.json and new manifest-v2.json.
Rationale: Safe migration while new loader introduced.
Dependencies: T2.1 existing schema.
Scope (In): Modify snapshot creation only; not yet altering read logic.
Out of Scope: Index, loader normalization (T2.3).
Implementation Steps:
 1. After snapshot files prepared, build data structure conforming to schema.
 2. Compute file sizes & aggregate size_total_bytes.
  3. Add provenance (command line from $argv, gethostname(), getenv('USER')).
 4. Serialize as manifest-v2.json (pretty printed) in snapshot directory.
 5. Retain original meta.json unchanged.
 6. Add optional integrity cross-check: compare number of files in both structures.
Testing: Create snapshot; confirm both files present; manifest passes validator.
Acceptance Criteria: meta.json unchanged; manifest-v2.json valid; no runtime regressions.
Rollback: Remove manifest-v2 write block.
Edge Cases: Compression not yet implemented (mark compression.enabled=false).
Agent Instruction Block: Implement code writing manifest-v2.json with required fields.

T2.3 Unified Read via Adapter (Expanded)
Context: Consumers will need single internal representation.
Objective: Create SnapshotLoader returning normalized associative array or Snapshot object from either meta.json or manifest-v2.json.
Rationale: Simplifies future features (index, tags, filters).
Dependencies: T2.2 (both written), T2.1 (schema definition guides mapping).
Scope (In): Loader class + update list/display paths to use it.
Out of Scope: JSON output formatting (later).
Implementation Steps:
 1. Create Domain/Snapshot/SnapshotLoader.php.
 2. Load manifest-v2.json if present else meta.json.
 3. Map legacy fields to new normalized structure {uid, created_utc, type, message, tags[], files[], checksums{file=>hash}, size_total_bytes, provenance?, raw_manifest_version}.
 4. Provide method load($snapshotDir): array.
 5. Refactor snapshot_manager::read_meta to delegate to loader (rename read_meta -> read_manifest maybe keep wrapper for backward calls).
 6. Adjust list command to use loader and surfaces first line of message.
Testing: Create snapshot; list command returns consistent data; no failures on legacy only snapshot if manifest-v2 manually removed.
Acceptance Criteria: Loader works for both formats; list unchanged in output; internal code no longer directly decodes meta.json outside loader.
Rollback: Revert loader usage and file addition.
Edge Cases: Corrupt manifest-v2 with valid meta.json -> fallback to meta.json with warning (STDERR).
Agent Instruction Block: Implement loader and refactor usages.

-------------------------------------------------------------------
Milestone 3: Snapshot Creation Improvements (Expanded)
-------------------------------------------------------------------
T3.1 Dump Provider Interface (Expanded)
Context: Hard-coded tdb dependency; need abstraction.
Objective: Introduce IDumpProvider with default shell-based implementation to produce backup artifact.
Rationale: Future DB engines / testability.
Dependencies: ProcessRunner (T1.4) beneficial.
Scope (In): Interface, provider registry/resolution, config hook.
Out of Scope: Additional DB providers beyond default.
Implementation Steps:
 1. Define Interface Domain/Snapshot/DumpProviderInterface.php with methods: supports(array $context): bool, dump(string $uid, string $targetDir, array $options): DumpResult.
 2. DumpResult contains: {files: [ {name, path} ], metadata: {engine?, version?}}.
 3. Implement TdbDumpProvider using ProcessRunner (wrap current command logic). Allow env override for binary path: SNAPPY_TDB_BIN.
 4. Add ProviderResolver that returns first provider whose supports() returns true (for now just TdbDumpProvider always true).
 5. Modify snapshot creation to: provider = resolver->resolve(); result = provider->dump(); copy or move produced file(s) into snapshot directory canonical names.
Testing: Create snapshot; verify same outputs.
Acceptance Criteria: Code path uses provider; original behavior intact.
Rollback: Remove provider classes and inline logic again.
Edge Cases: Provider failure (throws) -> snapshot creation aborts gracefully.
Agent Instruction Block: Implement interface and integrate in create.

T3.2 Compression Support (gzip baseline) (Expanded)
Context: Snapshots uncompressed; large size potential.
Objective: Add optional gzip compression of primary dump file with checksum recording post compression.
Rationale: Space savings; baseline for future algorithms.
Dependencies: Dual write (manifest writes compression metadata).
Scope (In): --compress flag on create; modify manifest-v2; update meta.json or keep uncompressed reference? (meta.json remains referencing backup.sql; compressed file named backup.sql.gz).
Out of Scope: Transparent on-the-fly decompression commands (not needed yet).
Implementation Steps:
 1. Parse --compress flag in snap command.
 2. After dump provider produces backup.sql, if compress requested:
    - Run gzip -c backup.sql > backup.sql.gz (or PHP zlib function) and remove original backup.sql OR keep both (decide: remove original to save space; update references accordingly).
 3. Update meta.json 'files' to reflect new file name backup.sql.gz (breaking change acceptable per project directive).
 4. Compute sha256 hash of compressed file.
 5. In manifest-v2.json set compression.enabled=true, compression.algo="gzip", original_size_bytes, compressed_size_bytes, compression_ratio.
 6. Ensure reading logic (loader) gracefully handles .gz extension.
Testing: Create snapshot with and without --compress; verify listing unaffected; verify file exists and is valid gzip (gunzip -t).
Acceptance Criteria: Compressed snapshot present; manifest fields populated; checksum verifies.
Rollback: Revert file changes; restore original naming.
Edge Cases: gzip binary missing -> fallback to PHP gzencode; if both unavailable, error out with clear message.
Agent Instruction Block: Implement compression pipeline and metadata updates.

T3.3 Exit Code & Log Capture (Expanded)
Context: Failures currently silent.
Objective: Capture stdout/stderr of dump process into logs/dump.log and abort if non-zero exit.
Rationale: Debuggability.
Dependencies: ProcessRunner.
Scope (In): Create logs directory inside snapshot dir; write log before manifest created.
Out of Scope: Rotating logs globally.
Implementation Steps:
 1. After provider dump invocation returns or fails, if failure: ensure temporary directory accessible; write combined log (timestamp, command, exit code, stdout, stderr) to snapshot temp dir (maybe under $SNAPPY_SNAPSHOT_ROOT/tmp/<uid>/ first; only promote to final snapshot dir if success).
 2. On success: optionally store minimal log if useful (optional – can skip for now).
 3. On failure: print STDERR message referencing log path; remove partial snapshot directory (unless debugging flag set to keep).
 4. Add --keep-failed flag to preserve partial artifacts for diagnosis.
Testing: Force failure (invalid tdb path) verify log file content & location.
Acceptance Criteria: Failures produce log; snapshot not registered.
Rollback: Remove logging logic.
Edge Cases: Write permission failure -> include fallback message.
Agent Instruction Block: Add logging and failure handling as specified.

-------------------------------------------------------------------
Milestone 4: Index & Fast Listing (Expanded)
-------------------------------------------------------------------
T4.1 Local Snapshot Index (Expanded)
Context: Listing iterates filesystem or remote; slow at scale.
Objective: Maintain snapshots/index.json summarizing snapshots for O(1) listing.
Rationale: Performance and enabling filters.
Dependencies: Loader (T2.3) to derive normalized fields.
Scope (In): IndexManager with methods: ensure(), addOrUpdate(uid), remove(uid), rebuild().
Out of Scope: Remote index synchronization (T4.2).
Implementation Steps:
 1. Define index schema: {version:1, generated_utc:"...", snapshots:[ {uid, created_utc, message_first, tags, size_total_bytes, compression:{enabled,algo}, files_count} ]}.
 2. On snapshot creation: call IndexManager->addOrUpdate().
  3. Provide CLI: snapshot index rebuild (hidden or under internal) to scan snaps/*/manifest-v2.json and rebuild file atomically (write temp then rename).
 4. Modify list command: if index exists & not stale (optional stale check: none for v1), use it to render results; only read full manifest when --full or extended detail requested.
 5. If manifest-v2 missing for an entry, fallback to meta.json mapping minimal fields.
Testing: Create multiple snapshots; verify index file updates; manually delete one snapshot directory then run rebuild returns updated index.
Acceptance Criteria: list speed improves (document baseline measure); index file exists and valid JSON.
Rollback: Remove index usage & file.
Edge Cases: Corrupted index -> auto rebuild fallback.
Agent Instruction Block: Implement IndexManager and integrate with create & list.

T4.2 Remote Summary Index (Expanded)
Context: Remote listing requires scanning objects; can be slow/expensive.
Objective: Maintain remote summary file snaps/index.json updated on push.
Rationale: Minimizes remote API calls.
Dependencies: T4.1 local index design.
Scope (In): On push: fetch remote index (if exists), merge or append summary for pushed snapshot(s), upload updated index atomically.
Out of Scope: Conflict resolution beyond last-write-wins (initial phase).
Implementation Steps:
 1. Implement RemoteIndexManager with read(), writeAtomic().
 2. During push after uploading manifest, read remote index (ignore errors), merge or insert new summary row using same schema as local index.
 3. Write to temporary key snaps/index.json.tmp then move/overwrite final index.json (S3: put_object overwrite acceptable).
 4. Modify remote list path: attempt reading snaps/index.json; if present use summaries; only fetch full manifests when --full.
Testing: Push snapshot; confirm remote index includes its uid by reading via existing storage API.
Acceptance Criteria: list --remote uses index when present; fallback works if missing.
Rollback: Stop writing remote index and remove logic.
Edge Cases: Concurrent pushes: lost update risk—mitigate by simple retry if ETag mismatch (optional future improvement, can accept risk now).
Agent Instruction Block: Implement remote index logic integrated into push and remote list.

-------------------------------------------------------------------
Milestone 5: CLI Experience Redesign (Expanded)
-------------------------------------------------------------------
T5.1 Command Namespace Restructure (Expanded)
Context: Flat command names reduce discoverability; planned future verbs.
Objective: Introduce hierarchical commands: snapshot create/list/show, etc., replacing prototype/legacy names directly.
Rationale: Improved UX, clarity for automation.
Dependencies: Index (T4.1) optional but helpful.
Scope (In): New command dispatcher supporting primary verb + subcommand; REMOVE old prototype command names (breaking change acceptable).
Out of Scope: Transitional warning layer (intentionally skipped per project strategy).
Implementation Steps:
 1. Create CLI/Framework/CommandRouter.php handling argv parsing pattern: <primary> <sub> [args].
 2. Define new commands: snapshot:create, snapshot:list, snapshot:show (or snapshot create/list/show syntax).
 3. Remove obsolete 'snap' single command; update documentation accordingly.
 4. Update help system to group commands logically.
 5. Add central registry mapping only new names.
Testing: Run new commands producing expected results. Confirm old names fail with clear error.
Acceptance Criteria: New commands operational; old names not present; help lists only new hierarchy.
Rollback: Re-introduce legacy aliases if absolutely required (not planned).
Edge Cases: Unknown subcommand -> helpful usage message.
Agent Instruction Block: Implement router & new snapshot commands, deleting legacy alias code.

T5.2 JSON / Quiet / Color Flags (Expanded)
Context: Scripting needs structured output.
Objective: Add global flags (--json, --quiet, --no-color) plus color auto-detection.
Rationale: Automation, readability.
Dependencies: T5.1 (central parsing).
Scope (In): OutputFormatter class with methods: printRow, printTable, printError, emitJson.
Out of Scope: Rich TTY detection beyond basic stream_isatty.
Implementation Steps:
 1. Parse global flags before command dispatch.
 2. If --json, commands output JSON object with metadata: {command, status, data, errors?}. Avoid mixing human text.
  3. --quiet suppress normal info lines (errors still to STDERR).
 4. Implement minimal color helper referencing existing util/color.php, disable with --no-color or non-TTY.
Testing: snapshot list --json outputs valid JSON parsable by jq.
Acceptance Criteria: Flags function across commands; no color codes in JSON mode.
Rollback: Remove additions.
Edge Cases: Both --quiet and --json specified -> JSON takes precedence for output; quiet only suppress side chatter.
Agent Instruction Block: Add global flag parsing and output abstraction.

T5.3 Improved Help & Examples (Expanded)
Context: Current help is minimal; needs structured formatting.
Objective: Dynamic help grouped by domain with examples.
Rationale: Faster onboarding.
Dependencies: T5.1 router.
Scope (In): Help generator reading command metadata arrays.
Out of Scope: Man page generation (future).
Implementation Steps:
 1. Each command class exposes metadata: name, aliases, description, usage, examples (array), group.
 2. help command lists groups (Snapshot, Remote, Share, Maintenance).
 3. Format adaptively (plain vs colored).
Testing: Run help; inspect formatting.
Acceptance Criteria: All commands enumerated with examples; legacy commands show alias relation.
Rollback: Keep old help implementation.
Edge Cases: Unknown command help request -> suggestions list (closest match Levenshtein distance optional—skip if complex).
Agent Instruction Block: Implement metadata-driven help generator.

-------------------------------------------------------------------
Milestone 6: Secure One-Time Share Mechanism (Expanded)
-------------------------------------------------------------------
T6.1 Share Token Model (Expanded)
Context: Need ephemeral secure share without persistent publishing.
Objective: Generate single-use tokens referencing snapshot UID with expiry metadata.
Rationale: Controlled, minimal sharing feature.
Dependencies: Snapshot existence / loader.
Scope (In): shares.json registry; CLI share create <uid> --expire=1h.
Out of Scope: Network service, encryption (later milestone).
Implementation Steps:
 1. Define storage file: $SNAPPY_SNAPSHOT_ROOT/.snappy/shares.json with structure {version:1, tokens:[ {token_hash, uid, created_utc, expires_utc, used_utc|null, meta:{tags,message_first_line}} ]}.
 2. When creating token: generate raw token = base64url(random_bytes(24)); store hash = sha256(token); never store raw token.
 3. Compute expires_utc = created_utc + duration (support suffix: m,h,d).
 4. Output raw token to user once; instruct to share securely.
 5. Validate UID exists; attach message first line + tags for introspection.
Testing: Create token; inspect file; ensure token raw not stored.
Acceptance Criteria: Token generation succeeds; shares.json valid JSON; token not reusable after marking used.
Rollback: Remove shares.json and command.
Edge Cases: Duplicate hash improbable; if occurs, regenerate.
Agent Instruction Block: Implement share create with secure token hash storage.

T6.2 Share Retrieval Flow (Expanded)
Context: Need consumer to reconstruct snapshot from token locally.
Objective: share fetch <token> resolves to local snapshot directory, copying if not already present.
Rationale: Enables secure remote transfer scenario when combined with export or presigned later.
Dependencies: T6.1.
Scope (In): Fetch command reading shares.json, marking token used.
Out of Scope: Remote pulling logic extension (works local first; remote variant later if token references remote attribute).
Implementation Steps:
 1. Accept token input (raw). Compute hash; locate entry where token_hash matches and used_utc null and expires_utc > now.
  2. On success: if snapshot already exists, simply mark used_utc and output path.
 3. If future design includes remote link, placeholder field remote_name can be considered (skip now).
 4. Mark used_utc now and save shares.json.
Testing: Create then fetch; ensure used_utc populated; second fetch fails.
Acceptance Criteria: Single use enforced; expired token rejected.
Rollback: Remove fetch command.
Edge Cases: Clock skew minimal; treat expiry strictly <= now expired.
Agent Instruction Block: Implement share fetch logic with single-use guarantee.

T6.3 Optional Archive Export for Share (Expanded)
Context: Sharing may need portable file artifact.
Objective: Add flag --as-archive to create tar.gz (or zip if easier) of snapshot directory plus manifest.
Rationale: Simplifies out-of-band transfer.
Dependencies: Compression support optional complement.
Scope (In): share create --as-archive storing snapshot_uid.tar.gz in exports/.
Out of Scope: Encryption (Milestone 7).
Implementation Steps:
 1. Create exports/ under snapshot root if not exists.
 2. Tar/gzip (internal PHP PharData or external tar command) snapshot directory excluding any transient logs? (Include all by default.)
 3. Compute sha256 of archive; store alongside .sha256 file.
 4. Record archive_path & checksum in share token meta.
Testing: Verify archive extract returns identical structure.
Acceptance Criteria: Archive present; checksum file accurate; share create output shows archive path.
Rollback: Delete exports/ and remove logic.
Edge Cases: Large snapshot memory usage—use streaming tar if possible.
Agent Instruction Block: Implement archive generation and metadata extension.

T6.4 Pre-signed URL Support (Optional) (Expanded)
Context: Efficient remote sharing via S3 temporary URLs.
Objective: If snapshot already pushed to S3 remote, generate pre-signed GET URLs for files inside token metadata.
Rationale: Avoid local re-hosting.
Dependencies: S3 storage implementation with signing helper (presign.php exists?).
Scope (In): share create --remote=<name> --presign.
Out of Scope: Multi-cloud provider support now.
Implementation Steps:
 1. Confirm remote type is s3.
 2. For each file (manifest-v2 or meta list), produce presigned URL with expiry matching token expiry.
 3. Store in token meta {presigned:[ {file, url, expires_utc} ]}.
 4. share fetch: If presigned present and local snapshot missing, download each file and reconstruct manifest.
Testing: Generate token; fetch; verify files downloaded.
Acceptance Criteria: Downloads succeed; token consumed.
Rollback: Remove presign logic from share create.
Edge Cases: Large files—simple sequential download ok for first iteration.
Agent Instruction Block: Implement S3 pre-signed generation and fetch usage.

-------------------------------------------------------------------
Milestone 7: Optional Encryption + Integrity Enhancements (Expanded)
-------------------------------------------------------------------
T7.1 Archive-Level Encryption (Share Only) (Expanded)
Context: Users may want to protect shared archives.
Objective: Encrypt exported archive with symmetric passphrase (scrypt KDF + libsodium secretbox or OpenSSL AES-256-GCM fallback).
Rationale: Confidentiality during transit.
Dependencies: Archive export (T6.3).
Scope (In): snappy encrypt during share create --encrypt.
Out of Scope: Persistent encryption of local snapshot store.
Implementation Steps:
 1. Check for libsodium extension; else fallback to openssl.
 2. Derive key: scrypt (N=2^15,r=8,p=1) from passphrase (prompt user or SNAPPY_PASSPHRASE env).
 3. Encrypt archive bytes streaming into .enc file; store header JSON (version, algo, salt, nonce) + binary ciphertext.
 4. Delete plaintext archive if encryption successful (unless --keep-plaintext).
 5. Update share token meta: encrypted=true, encryption_algo, kdf, salt (base64), nonce.
 6. Provide decrypt utility (share decrypt <encfile> -> original tar.gz).
Testing: Round-trip decrypt matches original checksum before encryption.
Acceptance Criteria: Encrypted file created; decrypt successful; metadata recorded; raw archive absent (unless keep flagged).
Rollback: Remove encryption code.
Edge Cases: Very large files—stream chunk encryption (XChaCha20 preferred) rather than loading into memory.
Agent Instruction Block: Implement encryption pipeline with passphrase KDF.

T7.2 Manifest Signature (Optional) (Expanded)
Context: Detect tampering of manifest during share transit.
Objective: HMAC sign manifest-v2.json producing manifest-v2.sig stored adjacent.
Rationale: Integrity validation.
Dependencies: Manifest v2.
Scope (In): If SNAPPY_SIGN_KEY env present, use it as secret key for HMAC-SHA256.
Out of Scope: Public key signatures.
Implementation Steps:
 1. On snapshot creation (or push) if key env present, compute base64(hmac_sha256(manifest_json, key)).
 2. Write manifest-v2.sig.
 3. verify command (to be added or extended) checks signature; warn if missing or mismatch.
Testing: Modify manifest manually; verify reports mismatch.
Acceptance Criteria: Sign file present when key exists; verify outputs success/failure.
Rollback: Remove signing logic.
Edge Cases: Key rotation not handled—document limitation.
Agent Instruction Block: Implement optional HMAC signature & verification.

-------------------------------------------------------------------
Milestone 8: Tagging & Search (Expanded)
-------------------------------------------------------------------
T8.1 Tag Add/Remove (Expanded)
Context: Need organizational labels.
Objective: Add snapshot tag add/remove/list functionality updating manifest & index.
Rationale: Filtering & retention safety.
Dependencies: Manifest v2, index.
Scope (In): Commands: snapshot tag <uid> add <tag>, snapshot tag <uid> remove <tag>.
Out of Scope: Bulk tagging patterns (later maybe).
Implementation Steps:
 1. Validate tag format: ^[a-z0-9][a-z0-9_-]{0,31}$.
 2. Load manifest (v2 preferred) update tags array; write back atomically.
 3. Update index entry for that uid (call IndexManager->addOrUpdate).
 4. Provide snapshot show displays tags.
Testing: Add tag, list with filter (pending T8.2) eventually.
Acceptance Criteria: Tag persists; index updated.
Rollback: Remove tag manipulation functions.
Edge Cases: Duplicate tag ignored; removing absent tag no error (warn?).
Agent Instruction Block: Implement tag add/remove logic.

T8.2 Filter Syntax (Expanded)
Context: Need selective listings.
Objective: Implement basic filter expressions for list: field=value AND field2<value2.
Rationale: Narrowing results programmatically.
Dependencies: Index (for performance) & tags.
Scope (In): Parser supporting tokens: TAG, UID, AGE, TYPE comparisons; operators: =, <, >; logical AND only (OR future).
Out of Scope: Complex parentheses.
Implementation Steps:
 1. Add FilterParser with parse(string) -> array of conditions.
  2. Conditions supported:
     - tag=NAME
     - age<7d (convert to timestamp comparison)
     - age>2h
     - type=sql
     - uid=exactprefix (treat as startsWith match) maybe alias.
 3. Apply conditions over index rows before formatting output.
Testing: Create 3 snapshots with tags; filter returns correct subset.
Acceptance Criteria: filter yields deterministic results; invalid syntax yields clear error.
Rollback: Remove parser & integration.
Edge Cases: Unknown tag filter returns empty list (not error).
Agent Instruction Block: Implement simple parser and integrate into list.

-------------------------------------------------------------------
Milestone 9: Simple Retention (Expanded)
-------------------------------------------------------------------
T9.1 Prune By Count (Expanded)
Context: Disk usage may grow.
Objective: Implement prune --keep-last=N removing oldest non-protected snapshots.
Rationale: Maintenance.
Dependencies: Index (must rely on created_utc sorting) & tagging (protected tags later).
Scope (In): Command prune with dry-run default; support --apply to execute.
Out of Scope: Complex policy language.
Implementation Steps:
  1. Accept --keep-last=N (int >0).
 2. Load index; sort by created_utc descending.
 3. Protected tags: baseline (hard-coded initial set) not deleted.
 4. Identify snapshots beyond N excluding protected; output plan if dry-run.
 5. If apply: remove snapshot directories & delete from index.
Testing: Create N+2 snapshots; run dry-run; apply; confirm only expected removed.
Acceptance Criteria: Index updated; no protected deletions.
Rollback: Restore from baseline tag if needed (document limitation—no built-in undo).
Edge Cases: N greater than existing count -> nothing removed.
Agent Instruction Block: Implement prune count logic with dry-run.

T9.2 Prune By Age (Expanded)
Context: Need time-based cleanup.
Objective: Add --max-age=30d style option.
Rationale: Keep only recent snapshots.
Dependencies: T9.1.
Scope (In): Combine with --keep-last logic if both present (intersection removal set).
Out of Scope: CRON scheduling.
Implementation Steps:
 1. Parse duration (e.g. 30d, 12h, 90m) => seconds.
 2. Compute cutoff timestamp.
 3. Select snapshots older than cutoff excluding protected.
 4. Merge with count logic if both set.
Testing: Adjust created_utc in one manifest manually to simulate age (or manipulate system time variable via env override SNAPPY_NOW_OVERRIDE).
Acceptance Criteria: All snapshots older than threshold removed (apply mode) or listed (dry-run).
Rollback: Same as T9.1.
Edge Cases: Negative or zero age input -> error.
Agent Instruction Block: Implement age-based prune extension.

-------------------------------------------------------------------
Milestone 10: Test & CI Foundation (Expanded)
-------------------------------------------------------------------
T10.1 Test Framework Setup (Expanded)
Context: No automated tests.
Objective: Introduce PHPUnit (or Pest) with bootstrap and first example tests.
Rationale: Regression prevention.
Dependencies: Composer (T1.1).
Scope (In): composer require --dev phpunit/phpunit; phpunit.xml; tests/unit/.
Out of Scope: Full coverage.
Implementation Steps:
 1. Update composer.json dev requirement.
 2. Create tests/bootstrap.php (autoload vendor + set test env paths).
 3. Write first test: ensures snapshot UID generator returns unique pattern.
 4. Add GitHub Actions workflow (if repo remote) or local script test.sh.
Testing: Run vendor/bin/phpunit.
Acceptance Criteria: Tests pass locally; CI config exists (if applicable).
Rollback: Remove test config & dependencies.
Edge Cases: None.
Agent Instruction Block: Add PHPUnit with one passing test.

T10.2 Integration Tests (Local) (Expanded)
Context: Need end-to-end scenario validation.
Objective: Implement test that creates snapshot, lists, tags, verifies.
Rationale: Ensure core flows stable.
Dependencies: T10.1, manifest loader.
Scope (In): tests/integration/SnapshotFlowTest.php.
Out of Scope: Remote (S3) interactions.
Implementation Steps:
 1. Setup temp directory isolation (use sys_get_temp_dir() . '/snappy_test_' . uniqid()).
 2. Point SNAPPY_SNAPSHOT_ROOT env there.
 3. Fabricate dump provider stub to avoid real DB (inject via test flag) producing small dummy file.
 4. Run CLI commands via ProcessRunner or shell.
 5. Assertions: snapshot created, meta files exist, list returns 1 entry.
Testing: Execute via phpunit group integration.
Acceptance Criteria: Integration test green.
Rollback: Remove test file.
Edge Cases: Path permissions—skip test if not writable.
Agent Instruction Block: Write integration test using stub provider.

T10.3 S3 MinIO Integration Test (Expanded)
Context: Validate remote push/pull.
Objective: Use MinIO docker to test S3 operations.
Rationale: Confidence in remote reliability.
Dependencies: Docker available.
Scope (In): test script spins up container, config remote, push, pull.
Out of Scope: Parallel uploads.
Implementation Steps:
 1. Add docker-compose snippet or dynamic docker run command (minio/minio image).
 2. Wait for readiness, create bucket if required.
 3. Configure remote via CLI remote add.
 4. Push snapshot; remove local copy folder; pull back; verify checksum.
Testing: phpunit test group remote.
Acceptance Criteria: Test passes with no leftover containers (cleanup in tearDown). 
Rollback: Remove test.
Edge Cases: Docker unavailable -> skip gracefully.
Agent Instruction Block: Implement MinIO-backed integration test.

T10.4 Share Token Flow Test (Expanded)
Context: Sharing critical feature.
Objective: Test create + fetch single-use semantics.
Rationale: Prevent regression.
Dependencies: T6.1, T6.2.
Scope (In): tests/integration/ShareTokenTest.php.
Out of Scope: Presigned URL variant.
Implementation Steps:
 1. Create snapshot using stub provider.
  2. share create -> capture token.
 3. share fetch with token -> success.
 4. share fetch again -> failure expected.
Testing: Assertions on exit codes.
Acceptance Criteria: Single-use enforced.
Rollback: Remove test.
Edge Cases: Expiry set to short window—simulate by manipulating system time variable (optional).
Agent Instruction Block: Add test verifying single-use tokens.

-------------------------------------------------------------------
Milestone 11: Optional Content Addressing / Dedup (Expanded)
-------------------------------------------------------------------
T11.1 Hash Store Prototype (Expanded)
Context: Space optimization experiment.
Objective: Store artifacts by hash under objects/ while manifest references logical file name + content hash.
Rationale: Dedup identical dumps.
Dependencies: Manifest v2.
Scope (In): Optional flag --content-addressable on snapshot create.
Out of Scope: Retro-migration of existing snapshots.
Implementation Steps:
 1. After artifact creation (and compression), compute sha256.
 2. Path: $ROOT/objects/sha256/<first2>/<fullhash>.
 3. If file exists, discard new copy; link/copy (symlink if platform supports) into snapshot directory or store pointer in manifest (object_ref).
 4. manifest-v2: For each file entry add object_hash, stored_inline(bool), path.
Testing: Create two identical dumps (simulate by copying dummy file) ensure only one object file present.
Acceptance Criteria: Second snapshot references existing object; size savings realized.
Rollback: Remove optional flag logic.
Edge Cases: Hash collision negligible; ignore.
Agent Instruction Block: Implement optional content-addressable storage path.

T11.2 GC for Unreferenced Objects (Expanded)
Context: Orphaned objects may accumulate after deletions.
Objective: Scan all manifests, build live hash set, remove any objects not referenced.
Rationale: Reclaim space.
Dependencies: T11.1.
Scope (In): Command gc objects.
Out of Scope: Dry-run? (Actually include --dry-run for safety.)
Implementation Steps:
 1. Gather all manifest-v2 files; accumulate object_hash values.
 2. Iterate objects directory; if file hash not in set -> delete (or move to quarantine/ for --dry-run skip).
Testing: Create snapshot, delete snapshot dir manually, run gc ensures orphan removed.
Acceptance Criteria: Orphans removed; summary printed.
Rollback: None for deleted data (document caution) unless quarantine implemented.
Edge Cases: Concurrent snapshot creation—advise not to run gc concurrently.
Agent Instruction Block: Implement gc objects command with dry-run.

-------------------------------------------------------------------
Milestone 12: Diagnostics & Observability (Expanded)
-------------------------------------------------------------------
T12.1 Doctor Command (Expanded)
Context: Users need quick health check.
Objective: doctor command verifying config, index, disk space, remote connectivity (best effort), manifest integrity.
Rationale: Faster troubleshooting.
Dependencies: Index, manifest loader.
Scope (In): Checks producing PASS/WARN/FAIL lines; final summary exit code 0 unless FAIL present.
Out of Scope: Auto repair (except optional index rebuild suggestion).
Implementation Steps:
 1. Checks:
    - Config readable & valid JSON.
    - Index present & JSON parse OK.
    - Snapshot directories accessible.
    - Free disk space > configurable threshold (default 200MB).
    - For each remote: attempt list root prefix minimal (timeout 3s).
    - Random sample of 1 snapshot integrity: file exists & checksum match.
 2. Output format plain & JSON aware.
Testing: Corrupt index to simulate failure; run doctor.
Acceptance Criteria: Clear PASS/FAIL lines.
Rollback: Remove command.
Edge Cases: Large stores—limit random sample count.
Agent Instruction Block: Implement doctor with defined checks.

T12.2 Metrics Summary (Expanded)
Context: Quick insight into snapshot landscape.
Objective: Provide summary counts: total snapshots, total size, top tags, age histogram buckets.
Rationale: Capacity planning.
Dependencies: Index.
Scope (In): metrics command.
Out of Scope: Persistent metrics storage.
Implementation Steps:
 1. Parse index; aggregate counts.
 2. Age buckets: <1d, 1–7d, 8–30d, >30d.
 3. Output table or JSON.
Testing: Create staged snapshots (manually modify created timestamp for test) verifying bucket counts.
Acceptance Criteria: Metrics reflect underlying data.
Rollback: Remove command.
Edge Cases: Empty index -> zeros.
Agent Instruction Block: Implement metrics summarization.

-------------------------------------------------------------------
Milestone 13: Documentation & Onboarding (Expanded)
-------------------------------------------------------------------
T13.1 New README Structure (Expanded)
Context: README outdated after refactors.
Objective: Rewrite with Quick Start, Concepts, Commands (new verbs), Sharing, Indexing, Roadmap link.
Rationale: Accurate onboarding.
Dependencies: Post CLI redesign stable (T5.* + T6.*).
Scope (In): Overhaul README; retain legacy section referencing changes.
Out of Scope: API docs (separate).
Implementation Steps:
 1. Sections: Tagline, Features, Installation, Quick Start, Snapshot Lifecycle, Sharing, Index, Compression, Tagging, Prune, Verify, Roadmap pointer.
 2. Update examples to new command names.
 3. Add compatibility note: legacy commands maintained until version bump.
Testing: Manual review.
Acceptance Criteria: README references only new command set primarily.
Rollback: Restore previous README version (git history).
Edge Cases: None.
Agent Instruction Block: Rewrite README per structure.

T13.2 Developer Guide (Expanded)
Context: Contributors need architecture overview.
Objective: docs/dev/architecture.md describing layers, data flows, extension points.
Rationale: Reduce onboarding friction.
Dependencies: Refactor completion.
Scope (In): File with diagrams (ASCII ok) and section per layer.
Out of Scope: Auto diagram generation.
Implementation Steps: Summarize domain model, manifest anatomy, index maintenance, provider interface.
Testing: None automated.
Acceptance: File exists with >= 500 words.
Agent Instruction Block: Create architecture.md with required sections.

T13.3 User Guide for Sharing (Expanded)
Context: Sharing adds workflows needing clarity.
Objective: docs/share/guide.md step-by-step share create/fetch/encrypt.
Rationale: Reduce misuse risk.
Dependencies: Share & encryption features.
Scope (In): Document token lifecycle, expiration, security tips.
Out of Scope: Web service integration.
Implementation Steps: Provide examples, caution boxes.
Acceptance: Guide includes at least one encrypted share scenario.
Agent Instruction Block: Write share guide.

-------------------------------------------------------------------
Milestone 14: Backlog / Stretch Ideas (Expanded)
(These remain intentionally high-level; convert to full spec only if promoted.)
-------------------------------------------------------------------
- Incremental snapshots: Track base_uid + diff referencing WAL/binlog.
- Streaming restore: Pipe decompressed dump into DB process.
- Multi-artifact snapshots: Add subdirectories for config/files.
- Anonymization transforms: Pluggable transformation stage pre manifest write.
- REST wrapper & Web UI: Thin HTTP over Application layer.
- zstd compression provider: Higher ratio & speed.
- BLAKE3 checksums: Speed trade-offs vs SHA256.
- Parallel multipart uploads for large S3 objects.

END OF EXPANDED SPECIFICATIONS
