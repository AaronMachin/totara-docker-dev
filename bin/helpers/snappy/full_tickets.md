Full Ticket Specifications
==========================

Ticket Format Legend
--------------------
Each ticket below is self-contained and copy/paste ready. Fields included: ID, Title, Project Name, Project Purpose, Rewrite Note, Global Constraints, Context Recap, Objective, Rationale, Dependencies, Preconditions, Scope (In), Scope (Out), Implementation Steps, Data Structures / Schemas, File Targets (Create/Modify), Testing & Validation, Acceptance Criteria, Edge Cases, Rollback Strategy, Risks & Mitigations, Follow-Up Tasks, Time Estimate, Deliverables, Agent Execution Checklist.

-------------------------------------------------------------------
T0.1 Baseline Tag & Inventory
-------------------------------------------------------------------
ID: T0.1
Title: Baseline Tag & Inventory
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Current code is pre-refactor. Need a frozen reference state to compare subsequent changes.
Objective: Capture the current CLI behavior, structure, and a representative snapshot; tag repository baseline_pre_refactor.
Rationale: Enables regression comparison and rollback clarity after structural modifications.
Dependencies: None.
Preconditions: Git repository clean (no uncommitted changes). CLI runnable.
Scope (In): Collect help outputs, tree, sample meta, tag creation.
Scope (Out): Any modifications to runtime logic.
Implementation Steps:
 1. Ensure working tree clean (abort if dirty; instruct to commit/stash).
 2. Create docs/baseline/ directory (mkdir -p).
 3. Dump global help: php bin/helpers/snappy/tsnap_cli.php help > docs/baseline/help_all.txt.
 4. Dump per-command help: snap, push, pull, list, remote, cat, get, fetch, share (if exists), help -> individual txt files.
 5. Produce directory tree of bin/helpers/snappy/src (depth 5) into docs/baseline/tree.txt.
 6. Attempt to create an actual snapshot; if DB tooling unavailable fabricate sample_meta.json with current format fields.
 7. Copy current README.md to docs/baseline/README.pre_refactor.md.
 8. Git add + commit with message "Baseline capture for refactor".
 9. Git tag -a baseline_pre_refactor -m "Baseline before refactor (timestamp)".
Data Structures / Schemas: sample_meta.json uses existing meta.json structure {uid, created, type, message, files, file_checksums}.
File Targets: docs/baseline/* (new files only).
Testing & Validation: Confirm each help file non-empty; ensure tag listed (git tag --list baseline_pre_refactor).
Acceptance Criteria: Tag exists; baseline files complete; no code logic changed.
Edge Cases: Missing DB tool -> fabricate sample_meta.json with note field reason.
Rollback Strategy: Delete docs/baseline and remove tag (git tag -d baseline_pre_refactor) then redo.
Risks & Mitigations: Minimal risk; ensure clean working tree to avoid merging baseline noise later.
Follow-Up Tasks: Reference baseline for performance or functional comparisons.
Time Estimate: XS.
Deliverables: docs/baseline directory + annotated tag.
Agent Execution Checklist:
 - [ ] Verify clean git state
 - [ ] Create docs/baseline
 - [ ] Capture help outputs
 - [ ] Generate tree.txt
 - [ ] Create sample_meta.json (real or fabricated)
 - [ ] Copy README
 - [ ] Commit & tag

-------------------------------------------------------------------
T1.1 Introduce Composer & PSR-4
-------------------------------------------------------------------
ID: T1.1
Title: Introduce Composer & PSR-4 Autoloading
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Custom autoload (snappy_autoload.php) currently used; need modern dependency and autoload infrastructure.
Objective: Add composer.json with PSR-4 mapping (Snappy\\ => src/), integrate into CLI bootstrap.
Rationale: Standardization, easier integration of future libraries (e.g., phpunit), cleaner class discovery.
Dependencies: Optional: T0.1 baseline capture recommended first.
Preconditions: PHP 8.1+, Composer available (if not, document fallback).
Scope (In): composer.json creation, CLI bootstrap modification to prefer Composer autoload if available.
Scope (Out): Refactoring namespaces or directory structure (separate ticket).
Implementation Steps:
 1. In snappy root, create composer.json with fields: name, type, minimum-stability stable, require php>=8.1, autoload psr-4 Snappy\\ src/.
 2. Run composer install (if environment supports) to generate vendor/autoload.php (not mandatory for commit if vendor ignored; ensure .gitignore includes /vendor/).
 3. Modify tsnap_cli.php: if vendor/autoload.php exists include it first; else fallback to snappy_autoload.php.
 4. Add composer.lock to repo (optional decision; if added, commit it).
 5. Verify running php tsnap_cli.php help still functions.
Data Structures: composer.json standard schema.
File Targets: composer.json (new), tsnap_cli.php (modify), .gitignore (ensure vendor/ entry).
Testing & Validation: Execute help command; confirm no class-not-found errors.
Acceptance Criteria: Autoloader available; CLI unaffected; vendor dir ignored by git.
Edge Cases: Composer missing—document manual requirement in README later.
Rollback Strategy: Remove composer.json, any vendor references, revert tsnap_cli.php changes.
Risks & Mitigations: Path detection issues—guard conditional require.
Follow-Up Tasks: Namespace reorganization (T1.2), test framework (T10.*).
Time Estimate: S.
Deliverables: composer.json, updated tsnap_cli.php.
Agent Execution Checklist:
 - [ ] Create composer.json
 - [ ] Update .gitignore
 - [ ] Modify tsnap_cli.php bootstrap
 - [ ] Validate CLI commands
 - [ ] Commit changes

-------------------------------------------------------------------
T1.2 Directory Restructure
-------------------------------------------------------------------
ID: T1.2
Title: Introduce Layered Directory Structure
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: All domain, infrastructure, CLI logic intertwined; need separation for maintainability.
Objective: Reorganize code into Domain/, Application/, Infrastructure/, CLI/, Support/ while maintaining behavior.
Rationale: Improves modularity, future extension (providers, indexing, sharing) without coupling.
Dependencies: T1.1 (autoload) recommended to ease namespace updates.
Preconditions: Running baseline tests (manual) pass.
Scope (In): Physical file moves, namespace updates, minimal shims if necessary.
Scope (Out): Logic refactors, renaming public method APIs (handled by later tickets if needed).
Implementation Steps:
 1. Plan mapping: snapshot -> Domain/Snapshot; storage -> Infrastructure/Storage; config -> Infrastructure/Config; util -> Support/Util; cli -> CLI/Core + CLI/Commands.
 2. Create new directories and move files accordingly.
 3. Update namespaces uniformly (e.g., Snappy\Snapshot => Snappy\Domain\Snapshot).
 4. Update references across code (search/replace).
 5. Run CLI help, create snapshot to validate.
 6. Add docs/dev/architecture.md initial stub listing new layer purpose.
Data Structures: None changed.
File Targets: Moves of all PHP in src; create docs/dev/architecture.md.
Testing & Validation: Manual run of snap, list, push (if available) to ensure no fatal errors.
Acceptance Criteria: All previously working commands succeed; new structure present; architecture doc stub exists.
Edge Cases: Autoload case sensitivity issues on some filesystems.
Rollback Strategy: Git revert commit.
Risks & Mitigations: High churn—do early; keep commit atomic.
Follow-Up Tasks: Exception hierarchy (T1.3), ProcessRunner (T1.4).
Time Estimate: M.
Deliverables: Reorganized directory structure, updated namespaces, architecture stub.
Agent Execution Checklist:
 - [ ] Create new directories
 - [ ] Move files
 - [ ] Update namespaces
 - [ ] Adjust references
 - [ ] Validate commands
 - [ ] Add architecture stub
 - [ ] Commit

-------------------------------------------------------------------
T1.3 Unified Error & Exception Hierarchy
-------------------------------------------------------------------
ID: T1.3
Title: Implement Exception Hierarchy & Exit Codes
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Mixed RuntimeException usage provides inconsistent error semantics.
Objective: Introduce SnappyException base + domain-specific subclasses mapped to documented exit codes.
Rationale: Predictable scripting integration & structured JSON output later.
Dependencies: T1.2 (namespaces stable).
Preconditions: Directory restructure completed.
Scope (In): New exceptions, central catch in CLI entry, docs/exit-codes.md.
Scope (Out): JSON output (handled later in T5.2), retry logic.
Implementation Steps:
 1. Create Support/Exception/ directory.
 2. Define base Snappy\Support\Exception\SnappyException extends Exception.
 3. Add: SnapshotNotFoundException, RemoteException, ValidationException, ProcessFailedException, ConfigException.
 4. Define Support/Exception/ExitCodes.php returning associative map: class => int.
 5. Update key throw sites replacing RuntimeException with specific exceptions.
 6. Modify CLI dispatcher: wrap execution in try/catch; map exception class to exit code; print formatted "ERROR(code): message" to STDERR.
 7. Create docs/exit-codes.md explaining codes.
Data Structures: Exit codes map e.g., Validation=2, NotFound=3, Remote=4, Process=5, Config=6, Unknown=99.
File Targets: New exception files, tsnap_cli.php modifications, docs/exit-codes.md.
Testing & Validation: Force errors (non-existent snapshot pull) and verify exit code & message.
Acceptance Criteria: Distinct exit codes; messages uniform; documentation present.
Edge Cases: Unmapped subclass falls back to Unknown 99.
Rollback Strategy: Revert modifications & remove exception files.
Risks & Mitigations: Missed conversion—leave TODO markers for remaining generic RuntimeException instances.
Follow-Up Tasks: JSON formatting (T5.2) will leverage hierarchy.
Time Estimate: S.
Deliverables: Exception classes, updated dispatcher, docs.
Agent Execution Checklist:
 - [ ] Add exception classes
 - [ ] Implement exit code map
 - [ ] Refactor throws
 - [ ] Update dispatcher
 - [ ] Add docs
 - [ ] Validate with test cases
 - [ ] Commit

-------------------------------------------------------------------
T1.4 Central Process Wrapper
-------------------------------------------------------------------
ID: T1.4
Title: Add ProcessRunner abstraction
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: system() with redirection hides errors; no stdout/stderr capture.
Objective: Replace system() usage with ProcessRunner capturing stdout, stderr, exit code, duration.
Rationale: Improves error reporting; enables later logging and compression pipeline reliability.
Dependencies: Prefer after T1.2 (paths stable). Not strictly dependent on exceptions but integrates better once T1.3 done.
Preconditions: Snapshot creation currently working.
Scope (In): Support/Process/ProcessRunner, integration in snapshot creation path.
Scope (Out): Async exec, streaming progress.
Implementation Steps:
 1. Create ProcessResult value object (exitCode, stdout, stderr, durationMs).
 2. Implement ProcessRunner::run(array $command, array $env=[], ?int $timeout=null): ProcessResult using proc_open.
 3. Replace snapshot_manager::create_sql_backup system() call with runner; build command array rather than shell string.
 4. If exitCode != 0 throw ProcessFailedException with truncated stderr (first 10 lines) and full stored for future use (T3.3).
 5. Provide environment override SNAPPY_TDB_BIN for binary path.
Data Structures: ProcessResult class.
File Targets: New ProcessRunner file; modify snapshot_manager.
Testing & Validation: Force failure by setting invalid SNAPPY_TDB_BIN; verify exception & exit code.
Acceptance Criteria: Successful snapshot unaffected; failures produce informative error.
Edge Cases: Large outputs—truncate memory stored output after 1MB.
Rollback Strategy: Restore system() call and remove ProcessRunner class.
Risks & Mitigations: Potential path quoting issues—use array form to avoid shell escaping.
Follow-Up Tasks: Logging (T3.3) will consume captured streams.
Time Estimate: S.
Deliverables: ProcessRunner, updated snapshot_manager.
Agent Execution Checklist:
 - [ ] Create ProcessRunner
 - [ ] Integrate into snapshot creation
 - [ ] Test success & failure paths
 - [ ] Commit

-------------------------------------------------------------------
T2.1 Manifest Schema v2 Draft
-------------------------------------------------------------------
ID: T2.1
Title: Define Manifest v2 Schema
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: meta.json minimal; need richer metadata for indexing, filtering, compression, provenance.
Objective: Author schema/manifest_v2.json + example illustrating all fields.
Rationale: Provides contract for dual writing & future validation.
Dependencies: None (post T1.* helpful but not mandatory).
Preconditions: Directory structure stable.
Scope (In): schema file, example manifest, optional validator script.
Scope (Out): Reader changes (handled T2.3), writing logic (T2.2).
Implementation Steps:
 1. Create schema directory if absent.
 2. Define JSON schema (draft 2020-12 or custom) with fields: schema_version=2, uid, created_utc (Z), snapshot_type, message, tags[], files[{name,size_bytes,compressed,compression_algo?}], checksums{algo:"sha256", files{filename:hash}}, size_total_bytes, compression{enabled,algo?,original_size_bytes?,compressed_size_bytes?,ratio?}, provenance{command_line, host, user, php_version}, custom_metadata(object), db(optional {engine, version}).
 3. Create manifest_v2.example.json populating sample realistic values.
 4. (Optional) validator script validate_manifest.php loads schema + example; outputs PASS/FAIL.
Data Structures: JSON schema + example.
File Targets: schema/manifest_v2.json, schema/manifest_v2.example.json, optional validate_manifest.php.
Testing & Validation: Run validator script; ensure example passes.
Acceptance Criteria: Schema & example committed; validator (if built) returns success.
Edge Cases: Optional sections omitted should still validate.
Rollback Strategy: Remove schema directory content.
Risks & Mitigations: Over-engineering—ensure only fields needed by roadmap are present.
Follow-Up Tasks: T2.2 dual write, T2.3 loader.
Time Estimate: S.
Deliverables: Schema file + example.
Agent Execution Checklist:
 - [ ] Create schema file
 - [ ] Add example
 - [ ] (Optional) Add validator
 - [ ] Commit

-------------------------------------------------------------------
T2.2 Dual Write (v1 + v2)
-------------------------------------------------------------------
ID: T2.2
Title: Dual Manifest Writing
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need to produce new manifest-v2.json while retaining meta.json until loader unifies.
Objective: Modify snapshot creation to write manifest-v2.json alongside legacy meta.json.
Rationale: Transitional compatibility and immediate enrichment.
Dependencies: T2.1 schema.
Preconditions: Snap creation functional (after T1.*).
Scope (In): Modify snapshot_manager create routine; compute new fields.
Scope (Out): Reading changes.
Implementation Steps:
 1. After dump completion gather file stats (size bytes).
 2. Build manifest v2 data per schema including compression.enabled=false initially.
 3. Capture provenance (implode $argv, host, user, php version).
 4. Calculate size_total_bytes (sum file sizes).
 5. Write manifest-v2.json; keep meta.json logic unchanged.
 6. Add lightweight integrity assertion (#files match).
Data Structures: manifest-v2 JSON.
File Targets: snapshot_manager (modify), new manifest-v2.json per snapshot.
Testing & Validation: Create snapshot; verify both files exist & manifest matches schema via validator.
Acceptance Criteria: Two manifest files present; no errors.
Edge Cases: Partial creation failure => ensure neither file left inconsistent (atomic write: write temp then rename).
Rollback Strategy: Remove v2 write block.
Risks & Mitigations: Race conditions minimal (single-process assumption).
Follow-Up Tasks: Loader (T2.3).
Time Estimate: S.
Deliverables: Updated snapshot creation + manifest-v2.json outputs.
Agent Execution Checklist:
 - [ ] Modify creation code
 - [ ] Generate new manifest
 - [ ] Validate schema
 - [ ] Commit

-------------------------------------------------------------------
T2.3 Unified Read via Adapter
-------------------------------------------------------------------
ID: T2.3
Title: SnapshotLoader (Unified Manifest Reader)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need single internal structure irrespective of manifest version.
Objective: Implement loader selecting manifest-v2.json if present else meta.json, returning normalized structure.
Rationale: Simplifies list, index, tagging, compression features.
Dependencies: T2.2 dual writing.
Preconditions: At least one snapshot created with both manifests.
Scope (In): SnapshotLoader class; update listing & read_meta calls.
Scope (Out): JSON output formatting.
Implementation Steps:
 1. Create Domain/Snapshot/SnapshotLoader.php.
  2. load($uidOrPath): detect file; parse JSON; map to unified array: {uid, created_utc, type, message, tags[], files[{name,size_bytes,compressed}], checksums{file=>hash}, size_total_bytes, raw_version}.
 3. Fallback: If only meta.json exists: infer created_utc=created, type=type, tags=[], size_total_bytes= sum of existing file sizes.
 4. Add minimal validation (missing critical fields => throw ValidationException).
 5. Refactor snapshot_manager::read_meta to call loader (rename to read_manifest or keep wrapper calling loader->load()).
 6. Adjust list command to use normalized output (message first line extraction maintained).
Data Structures: Normalized snapshot array.
File Targets: New loader, modifications in snapshot_manager and listing command.
Testing & Validation: Create old + new; remove v2 to simulate fallback; list still works.
Acceptance Criteria: List output unchanged; loader handles missing v2 gracefully.
Edge Cases: Corrupt v2 but valid meta => fallback with warning to STDERR.
Rollback Strategy: Revert loader usage.
Risks & Mitigations: Partial parse errors—explicit try/catch to fallback.
Follow-Up Tasks: Index (T4.1) & compression.
Time Estimate: M.
Deliverables: Loader class + integrated usage.
Agent Execution Checklist:
 - [ ] Create loader
 - [ ] Integrate with snapshot_manager
 - [ ] Update list logic
 - [ ] Test scenarios
 - [ ] Commit

-------------------------------------------------------------------
T3.1 Dump Provider Interface
-------------------------------------------------------------------
ID: T3.1
Title: Introduce DumpProvider Abstraction
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Snapshot creation directly invokes tdb via system call.
Objective: Create interface for dump providers enabling future DB sources and test stubbing.
Rationale: Extensibility & testability.
Dependencies: ProcessRunner (T1.4).
Preconditions: Manifest v2 writing functional.
Scope (In): Interface, default provider (TdbDumpProvider), provider resolver.
Scope (Out): Additional provider implementations.
Implementation Steps:
 1. Define Domain/Snapshot/DumpProviderInterface.php (supports(context), dump(uid, targetDir, options)).
 2. Create DumpResult value object: {files: [{name,path}], metadata: {engine, version}}.
 3. Implement TdbDumpProvider: builds command using SNAPPY_TDB_BIN or 'tdb'; runs via ProcessRunner.
 4. ProviderResolver returns first provider supports() (only one now returns true).
 5. Modify snapshot creation: provider->dump(); iterate result.files copying into snapshot dir; build checksums.
Data Structures: DumpResult class.
File Targets: New interface & provider classes; snapshot_manager modifications.
Testing & Validation: Snapshot creation success; simulate failure (bad binary path) yields ProcessFailedException.
Acceptance Criteria: Behavior unchanged externally; provider mechanism present.
Edge Cases: No provider supports => throw ValidationException.
Rollback Strategy: Inline logic removal revert.
Risks & Mitigations: Minimal; ensure provider registration executed before creation.
Follow-Up Tasks: Compression (T3.2).
Time Estimate: M.
Deliverables: Interface + default provider + resolver integration.
Agent Execution Checklist:
 - [ ] Add interface & classes
 - [ ] Integrate in snapshot creation
 - [ ] Test success/failure
 - [ ] Commit

-------------------------------------------------------------------
T3.2 Compression Support (gzip baseline)
-------------------------------------------------------------------
ID: T3.2
Title: Add Optional Gzip Compression
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Dumps stored raw; no size optimization.
Objective: Implement --compress flag to gzip primary dump file; record compression details.
Rationale: Space efficiency and metadata completeness.
Dependencies: T2.2 dual write; T3.1 provider (for clean integration) beneficial.
Preconditions: Snapshot creation stable.
Scope (In): CLI flag, compression logic, manifest updates, file rename to backup.sql.gz.
Scope (Out): Advanced algos (zstd), transparent lazy decompress.
Implementation Steps:
 1. Extend snap command parser to accept --compress.
 2. After provider dump, if flag set: gzip file (use PHP gzencode or external gzip) -> write backup.sql.gz; remove original.
 3. Update checksum calculation to use compressed file.
 4. meta.json files list becomes ["backup.sql.gz"] (breaking allowed); manifest-v2 compression.enabled=true, compression.algo=gzip, original_size_bytes, compressed_size_bytes, compression_ratio.
 5. Update loader fallback recognizing .gz extension.
Data Structures: Manifest compression fields.
File Targets: snap command, snapshot_manager (or service class), loader adjustments.
Testing & Validation: Create compressed snapshot; verify backup.sql.gz exists; gunzip -t; checksum stable.
Acceptance Criteria: Compression works; metadata fields correct; non-compressed path unaffected.
Edge Cases: Missing zlib extension => fallback to external gzip; if both unavailable error clearly.
Rollback Strategy: Remove compression branch & revert file naming.
Risks & Mitigations: Data loss risk if deletion before success—perform atomic rename after successful compress.
Follow-Up Tasks: Archive export uses compressed file.
Time Estimate: S.
Deliverables: Compressed snapshot capability.
Agent Execution Checklist:
 - [ ] Add flag parsing
 - [ ] Implement compression
 - [ ] Update manifests
 - [ ] Test both paths
 - [ ] Commit

-------------------------------------------------------------------
T3.3 Exit Code & Log Capture
-------------------------------------------------------------------
ID: T3.3
Title: Capture Dump Logs & Failure Handling
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: ProcessRunner collects output; not persisted yet; failures leave limited trace.
Objective: Persist dump stdout/stderr to logs/dump.log on failure (and optionally summary on success) with --keep-failed flag to retain artifacts.
Rationale: Diagnostic clarity.
Dependencies: T1.4 ProcessRunner; T3.1 provider integration.
Preconditions: Provider returns output.
Scope (In): Logging directory creation, failure handling cleanup.
Scope (Out): Log rotation, central log index.
Implementation Steps:
 1. Before dump, create temp path $SNAPPY_SNAPSHOT_ROOT/tmp/<uid>/.
 2. Run provider; on failure write logs/dump.log (include command, exitCode, timestamps, stdout, stderr).
 3. If success: optionally move subset of log or skip (configurable later).
 4. If failure & --keep-failed not set: delete temp directory; else preserve for analysis.
 5. On success, promote temp to final snapshot dir.
Data Structures: Plain text log.
File Targets: snapshot creation logic.
Testing & Validation: Force failure; confirm log presence; ensure no empty snapshot folder persisted unless keep flag set.
Acceptance Criteria: Failure path yields log + non-zero exit; success path unchanged.
Edge Cases: Write permission failure -> display fallback inline truncated stderr.
Rollback Strategy: Remove logging code block.
Risks & Mitigations: Disk accumulation—future retention may purge temp.
Follow-Up Tasks: doctor command might check orphaned temp dirs.
Time Estimate: S.
Deliverables: Failure log capture.
Agent Execution Checklist:
 - [ ] Add temp directory logic
 - [ ] Persist logs on failure
 - [ ] Implement --keep-failed
 - [ ] Test success/failure
 - [ ] Commit

-------------------------------------------------------------------
T4.1 Local Snapshot Index
-------------------------------------------------------------------
ID: T4.1
Title: Implement Local Index (snapshots/index.json)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Listing iterates dirs; scaling poor beyond dozens of snapshots.
Objective: Maintain snapshots/index.json summarizing snapshot essentials for O(1) listing.
Rationale: Performance and foundation for filtering & prune.
Dependencies: T2.3 loader for normalized records.
Preconditions: Several snapshots exist.
Scope (In): IndexManager, incremental update on create/delete, rebuild command.
Scope (Out): Remote index (T4.2), advanced staleness detection.
Implementation Steps:
 1. Schema: {version:1, generated_utc, snapshots:[{uid, created_utc, message_first, tags, size_total_bytes, compression:{enabled,algo}, files_count}]}.
 2. Write IndexManager with addOrUpdate(uid), remove(uid), rebuild().
 3. Hook snapshot creation to addOrUpdate.
 4. Create CLI command snapshot index rebuild.
 5. Modify list to: if index exists use it unless --no-index passed.
Data Structures: index.json.
File Targets: New IndexManager, list command modifications.
Testing & Validation: Create N snapshots; benchmark before/after (manual acceptable). Remove one snapshot dir manually then rebuild.
Acceptance Criteria: list uses index; shows consistent data; rebuild restores accuracy after manual tampering.
Edge Cases: Corrupt index => automatic rebuild fallback.
Rollback Strategy: Remove index logic & revert list changes.
Risks & Mitigations: Stale index risk—rebuild command available.
Follow-Up Tasks: Tag filtering (T8.*), prune (T9.*).
Time Estimate: M.
Deliverables: index.json maintenance & CLI integration.
Agent Execution Checklist:
 - [ ] Implement IndexManager
 - [ ] Hook into creation
 - [ ] Add rebuild command
 - [ ] Modify list
 - [ ] Test scenarios
 - [ ] Commit

-------------------------------------------------------------------
T4.2 Remote Summary Index
-------------------------------------------------------------------
ID: T4.2
Title: Remote Summary Index (snaps/index.json)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Remote listing currently scans object store prefix; inefficient.
Objective: Maintain remote index file on push to accelerate list --remote.
Rationale: Reduces API calls and latency for remote operations.
Dependencies: T4.1 local index model.
Preconditions: Working push command; remote registry functioning.
Scope (In): RemoteIndexManager, update on push, remote listing consumption.
Scope (Out): Concurrency conflict resolution (simple last write wins acceptable).
Implementation Steps:
 1. Read existing remote snaps/index.json if exists; parse; else initialize empty structure.
 2. Merge/update summary row for pushed snapshot(s).
  3. Write temp file snaps/index.json.tmp then overwrite snaps/index.json (atomic enough for S3).
 4. Modify list remote path to prefer index file; fall back to scan if absent.
Data Structures: Same schema as local index.
File Targets: Remote index manager, push & list remote code modifications.
Testing & Validation: Push snapshot; verify remote index includes entry by reading object; list remote returns quickly.
Acceptance Criteria: Remote listing uses index; fallback works if index missing or corrupt.
Edge Cases: Concurrent push lost update—acceptable initial risk.
Rollback Strategy: Remove index handling code.
Risks & Mitigations: Partial writes—accept ephemeral; next push repairs.
Follow-Up Tasks: Share features leveraging remote summaries.
Time Estimate: M.
Deliverables: Remote index support.
Agent Execution Checklist:
 - [ ] Implement RemoteIndexManager
 - [ ] Update push logic
 - [ ] Adjust remote list
 - [ ] Test push/list cycle
 - [ ] Commit

-------------------------------------------------------------------
T5.1 Command Namespace Restructure
-------------------------------------------------------------------
ID: T5.1
Title: Hierarchical Command Restructure (Replace Legacy Names)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Single-word commands limit clarity; new design requires grouped verbs.
Objective: Implement router supporting primary verb + subcommand (snapshot create/list/show, share create/list, prune, verify, config get/set) and eliminate legacy names.
Rationale: Improves discoverability & future extensibility.
Dependencies: Index (T4.1) helpful; not strictly required.
Preconditions: Existing commands working baseline.
Scope (In): New router, new command classes, removal of old snap/push/pull/list remote command names.
Scope (Out): JSON output (T5.2), help enhancements (T5.3).
Implementation Steps:
 1. Add CLI/Framework/CommandRouter parsing argv[1..]. pattern: primary + sub.
 2. Define mapping table of (primary, sub) to handler class.
 3. Implement new command classes snapshot.create, snapshot.list, snapshot.show, share.create, share.list, prune.run (or prune), verify.run, config.get/set.
 4. Remove old command registration files (or adapt them to new naming if code reused).
 5. Update tsnap_cli.php to instantiate router and dispatch.
 6. Adjust README (in later docs milestone) placeholder note.
Data Structures: Router internal map {"snapshot:create"=>Class}.
File Targets: New router file; new command files; removal or modification of old command classes.
Testing & Validation: Run snapshot create, snapshot list; ensure old 'snap' fails with error.
Acceptance Criteria: New commands operational; old names gone; error on old usage is clear.
Edge Cases: Missing subcommand prints usage summary.
Rollback Strategy: Reintroduce legacy mapping (not desired per rewrite note).
Risks & Mitigations: User confusion—document new names quickly.
Follow-Up Tasks: Add JSON output (T5.2), help generator (T5.3).
Time Estimate: M.
Deliverables: Router + new command set.
Agent Execution Checklist:
 - [ ] Implement router
 - [ ] Create new command handlers
 - [ ] Remove legacy commands
 - [ ] Validate new commands
 - [ ] Commit

-------------------------------------------------------------------
T5.2 JSON / Quiet / Color Flags
-------------------------------------------------------------------
ID: T5.2
Title: Global Output Modes (--json, --quiet, --no-color)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Current output plain text; scripts require structured JSON.
Objective: Introduce OutputFormatter supporting tabular & JSON modes, quiet suppression, ANSI color toggle.
Rationale: Automation & readability.
Dependencies: T5.1 router for early flag parsing.
Preconditions: New command structure exists.
Scope (In): Global flag parse, output abstraction, consistent error formatting integration with exceptions.
Scope (Out): Pagination, advanced TTY detection heuristics.
Implementation Steps:
 1. Parse global flags before command dispatch: --json, --quiet, --no-color.
 2. Implement OutputFormatter with API: info(msg), table(headers, rows), json(data), error(msg, code).
 3. If --json: all normal outputs aggregated & printed as single JSON {command, status, data, errors?}.
 4. --quiet suppresses info/table when not JSON; errors still emitted.
 5. Colors default on if stream_isatty(STDOUT) unless --no-color.
Data Structures: Output JSON shape stable.
File Targets: New formatter file; modify router & commands to use it.
Testing & Validation: snapshot list --json piped to jq; quiet mode suppresses banner output.
Acceptance Criteria: Consistent JSON schema; non-JSON mode unaffected except color handling.
Edge Cases: Both quiet and json => JSON still output.
Rollback Strategy: Revert formatter integration.
Risks & Mitigations: Commands forgetting to use formatter—perform grep audit.
Follow-Up Tasks: Help generator uses formatter (T5.3).
Time Estimate: S.
Deliverables: OutputFormatter + integrated usage.
Agent Execution Checklist:
 - [ ] Add formatter
 - [ ] Update router & commands
 - [ ] Test JSON & quiet
 - [ ] Commit

-------------------------------------------------------------------
T5.3 Improved Help & Examples
-------------------------------------------------------------------
ID: T5.3
Title: Metadata-Driven Help System
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Help currently minimal; lacks grouping & examples.
Objective: Generate help dynamically grouped by domain (Snapshot, Share, Maintenance, Config).
Rationale: Faster onboarding; clearer discoverability.
Dependencies: T5.1 & T5.2 (formatter).
Preconditions: Commands expose metadata.
Scope (In): Command metadata interface, help command generating structured output & JSON variant.
Scope (Out): Man page generation.
Implementation Steps:
 1. Each command class implements method metadata(): {name, group, description, usage, examples[]}.
 2. help command iterates registry -> groups -> prints sections.
 3. If --json global flag set: output metadata array.
Data Structures: Metadata array.
File Targets: Help command rewrite; command base class modifications.
Testing & Validation: Run help; verify grouping; run help --json parse success.
Acceptance Criteria: All commands present with examples; JSON mode returns structured listing.
Edge Cases: Missing group defaults to "Other".
Rollback Strategy: Restore prior static help.
Risks & Mitigations: Incomplete metadata—fail CI later with metadata validator (future task).
Follow-Up Tasks: Add metadata validation test.
Time Estimate: XS.
Deliverables: Dynamic help system.
Agent Execution Checklist:
 - [ ] Add metadata methods
 - [ ] Rewrite help command
 - [ ] Test text & JSON output
 - [ ] Commit

-------------------------------------------------------------------
T6.1 Share Token Model
-------------------------------------------------------------------
ID: T6.1
Title: Implement Single-Use Share Tokens
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need ephemeral secure share referencing snapshot without copying.
Objective: share create <uid> --expire=1h returns raw token; stores hashed record with metadata.
Rationale: Enables minimal controlled sharing.
Dependencies: SnapshotLoader (T2.3) for message/tags.
Preconditions: Snapshot exists locally.
Scope (In): shares.json registry, token generation, expiry parsing, single-use marking.
Scope (Out): Remote downloading, encryption, pre-signed URLs.
Implementation Steps:
 1. Path: $SNAPPY_SNAPSHOT_ROOT/.snappy/shares.json {version:1, tokens:[...] }.
 2. Generate token raw = base64url(random_bytes(24)); store sha256(raw) as token_hash; never store raw.
 3. Parse --expire (default 24h) support suffix m,h,d.
 4. Record: uid, created_utc (UTC), expires_utc, used_utc=null, meta {tags, message_first_line}.
 5. Flush JSON atomically (temp file rename).
 6. Output raw token once.
Data Structures: shares.json schema.
File Targets: share create command new or extension.
Testing & Validation: create token; ensure raw not in file; expiry logic correct.
Acceptance Criteria: Token created; share list (future optional) shows hashed entry; raw reusable only until fetch.
Edge Cases: Duplicate hash improbable; regenerate if collision.
Rollback Strategy: Remove share command & shares.json.
Risks & Mitigations: Clock skew—treat server local time authoritative.
Follow-Up Tasks: Fetch (T6.2), archive export (T6.3).
Time Estimate: M.
Deliverables: share create implementation + shares.json.
Agent Execution Checklist:
 - [ ] Implement token generator
 - [ ] Write shares.json update logic
 - [ ] Test creation & expiry parsing
 - [ ] Commit

-------------------------------------------------------------------
T6.2 Share Retrieval Flow
-------------------------------------------------------------------
ID: T6.2
Title: share fetch <token>
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Tokens created; no consumption path yet.
Objective: Resolve token -> mark used -> return local snapshot path.
Rationale: Completes minimal sharing round-trip.
Dependencies: T6.1.
Preconditions: Valid token exists.
Scope (In): share fetch command, token validation, usage marking.
Scope (Out): Remote retrieval, presigned downloads.
Implementation Steps:
 1. Input raw token; compute sha256; search shares.json for token_hash with used_utc null and expires_utc > now.
 2. If not found or expired => error.
 3. Mark used_utc now; write file atomically.
 4. Output snapshot path & uid.
Data Structures: shares.json update.
File Targets: share fetch command file.
Testing & Validation: Create token then fetch; second fetch fails.
Acceptance Criteria: Single-use enforced; proper errors on reuse or expiry.
Edge Cases: Race condition multiple fetch attempts simultaneous—first wins; second fails.
Rollback Strategy: Remove command code.
Risks & Mitigations: None significant.
Follow-Up Tasks: Archive export & encryption.
Time Estimate: S.
Deliverables: share fetch command.
Agent Execution Checklist:
 - [ ] Implement fetch logic
 - [ ] Update shares.json usage
 - [ ] Test single-use
 - [ ] Commit

-------------------------------------------------------------------
T6.3 Optional Archive Export for Share
-------------------------------------------------------------------
ID: T6.3
Title: Archive Export (--as-archive)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Some sharing requires portable file artifact.
Objective: share create --as-archive produce tar.gz + checksum.
Rationale: Simplicity of distribution.
Dependencies: T6.1 (token), T3.2 (compression optional synergy).
Preconditions: Snapshot exists.
Scope (In): Tar/gzip packaging, checksum file, metadata referencing archive path.
Scope (Out): Encryption (T7.1), remote upload.
Implementation Steps:
 1. Create exports/ under snapshot root if absent.
  2. Tar directory (exclude exports/ to prevent nesting) -> snapshot_<uid>.tar then gzip -> .tar.gz.
 3. Generate sha256 -> snapshot_<uid>.tar.gz.sha256 containing HASH filename.
 4. Add archive_path & archive_checksum to token meta when using --as-archive.
Data Structures: Extended token meta.
File Targets: share create command modifications.
Testing & Validation: Extract archive; compare file count & checksum.
Acceptance Criteria: Archive & checksum files generated; meta updated.
Edge Cases: Large snapshot memory—ensure streaming tar implementation.
Rollback Strategy: Remove archive code path & exports directory contents.
Risks & Mitigations: Disk space—clean old exports via future prune.
Follow-Up Tasks: Encryption T7.1.
Time Estimate: S.
Deliverables: Archive creation capability.
Agent Execution Checklist:
 - [ ] Implement tar/gzip
 - [ ] Generate checksum
 - [ ] Update meta
 - [ ] Test extraction
 - [ ] Commit

-------------------------------------------------------------------
T6.4 Pre-signed URL Support (Optional)
-------------------------------------------------------------------
ID: T6.4
Title: S3 Presigned Share Links
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: For remote snapshots already pushed to S3, direct download preferable.
Objective: share create --remote=<name> --presign to embed presigned GET URLs.
Rationale: Avoid local re-transfer.
Dependencies: Working S3 storage + push flow; token model.
Preconditions: Snapshot present on remote.
Scope (In): Presign generation, share meta injection, fetch logic to download if local snapshot missing.
Scope (Out): Multi-cloud providers.
Implementation Steps:
 1. Validate remote type s3.
 2. For each file + manifest-v2 generate URL valid until token expiry.
 3. Append meta.presigned = [{file,url,expires_utc}].
 4. share fetch: if snapshot absent locally & presigned set -> download files -> reconstruct manifest & meta.json.
Data Structures: Presigned array meta.
File Targets: share create & fetch commands; storage S3 add presign helper if missing.
Testing & Validation: Create token with presign; fetch on clean machine path; verify files.
Acceptance Criteria: Download success; token consumed; local snapshot created.
Edge Cases: Expired URL before fetch -> error advising new share.
Rollback Strategy: Remove presign branch logic.
Risks & Mitigations: URL leakage risk—advice to keep token secure.
Follow-Up Tasks: Encryption of archive separate.
Time Estimate: M.
Deliverables: Presigned share feature.
Agent Execution Checklist:
 - [ ] Implement presign creation
 - [ ] Update token meta
 - [ ] Enhance fetch logic
 - [ ] Test download path
 - [ ] Commit

-------------------------------------------------------------------
T7.1 Archive-Level Encryption (Share Only)
-------------------------------------------------------------------
ID: T7.1
Title: Encrypt Shared Archive (--encrypt)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Archives may contain sensitive data; optional encryption needed.
Objective: share create --as-archive --encrypt passphrase -> produce .enc file + remove plaintext archive by default.
Rationale: Protect confidentiality in transit.
Dependencies: T6.3 archive.
Preconditions: libsodium or OpenSSL available.
Scope (In): Scrypt KDF, XChaCha20-Poly1305 (libsodium) or AES-256-GCM fallback.
Scope (Out): Key management (user supplies passphrase), streaming restore pipeline.
Implementation Steps:
 1. Prompt for passphrase (if not SNAPPY_PASSPHRASE env) with confirmation.
 2. Generate salt (random 16 bytes); derive key via scrypt(N=2^15,r=8,p=1).
 3. Encrypt archive streaming to snapshot_<uid>.tar.gz.enc; include header JSON (algo, salt, nonce, version) followed by ciphertext.
 4. Compute sha256 of ciphertext store as snapshot_<uid>.tar.gz.enc.sha256.
 5. Unless --keep-plaintext remove original tar.gz.
 6. Update token meta: encrypted=true, encryption_algo, kdf, salt_b64, nonce_b64.
 7. Provide share decrypt <file> command to reverse.
Data Structures: Header JSON at start of .enc file.
File Targets: share create modifications, new decrypt command.
Testing & Validation: Encrypt + decrypt round-trip; verify archive checksum matches pre-encryption.
Acceptance Criteria: Encrypted file produced; decryption restores valid tar.gz; metadata updated.
Edge Cases: Weak passphrase warning (length < 8) -> confirm override.
Rollback Strategy: Remove encryption code path.
Risks & Mitigations: Performance overhead—acceptable for initial size ranges.
Follow-Up Tasks: Possibly integrate with verify to assert encryption state.
Time Estimate: M.
Deliverables: Encryption + decryption commands.
Agent Execution Checklist:
 - [ ] Implement KDF & encryption
 - [ ] Add decrypt command
 - [ ] Test round-trip
 - [ ] Commit

-------------------------------------------------------------------
T7.2 Manifest Signature (Optional)
-------------------------------------------------------------------
ID: T7.2
Title: HMAC Sign manifest-v2.json
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Want simple tamper detection when sharing.
Objective: If SNAPPY_SIGN_KEY env present, produce manifest-v2.sig (HMAC-SHA256 base64) and verify command.
Rationale: Integrity assurance without full PKI.
Dependencies: Manifest v2 writing.
Preconditions: sign key environment variable set for tests.
Scope (In): Signing on creation/push, verify command logic.
Scope (Out): Public key cryptography.
Implementation Steps:
 1. On snapshot creation: if key present compute hmac; write manifest-v2.sig.
 2. Add verify manifest <uid> command or extend verify.
 3. Verification: recompute HMAC; compare; output pass/fail.
Data Structures: Small .sig file with base64 string.
File Targets: snapshot creation code, verify command.
Testing & Validation: Modify manifest manually; verify fails.
Acceptance Criteria: Signature file present when key set; verify indicates status.
Edge Cases: Missing key when verifying signed manifest -> warning.
Rollback Strategy: Remove signing branch.
Risks & Mitigations: Key rotation unsupported—document limitation.
Follow-Up Tasks: Possible future public key signatures.
Time Estimate: S.
Deliverables: Signature generation & verification.
Agent Execution Checklist:
 - [ ] Add signing code
 - [ ] Extend verify command
 - [ ] Test tamper detection
 - [ ] Commit

-------------------------------------------------------------------
T8.1 Tag Add/Remove
-------------------------------------------------------------------
ID: T8.1
Title: Tag Management Commands
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need structured classification beyond message text.
Objective: Allow adding/removing tags to snapshot; persist & index.
Rationale: Enables filtering (T8.2) and retention safety.
Dependencies: Index (T4.1), Manifest v2 loader.
Preconditions: Snapshot exists.
Scope (In): snapshot tag <uid> add/remove <tag> updates manifest-v2 & index.
Scope (Out): Bulk operations by pattern.
Implementation Steps:
 1. Validate tag regex ^[a-z0-9][a-z0-9_-]{0,31}$.
 2. Load manifest; modify tags array; sync index.
 3. Atomic write manifest-v2.json (temp rename).
 4. Provide show command printing tags line.
Data Structures: tags[] string list.
File Targets: New tag command or subcommand file, index update call.
Testing & Validation: Add then remove tag; verify index reflects.
Acceptance Criteria: Tag persists; duplicates ignored silently.
Edge Cases: Removing non-existent tag returns success with note.
Rollback Strategy: Remove tag command.
Risks & Mitigations: Concurrent tag operations low probability.
Follow-Up Tasks: Filtering (T8.2), prune protected tags.
Time Estimate: S.
Deliverables: Tagging capability.
Agent Execution Checklist:
 - [ ] Implement add/remove
 - [ ] Update index
 - [ ] Test scenarios
 - [ ] Commit

-------------------------------------------------------------------
T8.2 Filter Syntax
-------------------------------------------------------------------
ID: T8.2
Title: Implement List Filtering (--filter)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Index contains metadata; need filtering for workflows.
Objective: Support basic AND expressions (tag=, type=, age<, age>, uid=prefix).
Rationale: Efficient targeted listing.
Dependencies: Tags (T8.1), index.
Preconditions: Index with sample data.
Scope (In): Parser, evaluator, integration in list command.
Scope (Out): OR, parentheses, regex.
Implementation Steps:
 1. Tokenize filter string by spaces; expect pattern field<op>value joined by AND.
 2. Supported ops: =, <, > for age comparators.
 3. age value parse suffix (m,h,d) to seconds.
 4. Evaluate conditions sequentially over index snapshot rows.
 5. If invalid syntax -> ValidationException.
Data Structures: Condition array [{field, op, value}].
File Targets: FilterParser class, list command modifications.
Testing & Validation: Use multiple snapshots with tags & varying ages; assert results.
Acceptance Criteria: Correct subsets returned; invalid input yields clear error.
Edge Cases: No snapshots => empty result without error.
Rollback Strategy: Remove filter parser integration.
Risks & Mitigations: Over-parsing complexity—keep minimal.
Follow-Up Tasks: Extend grammar later if needed.
Time Estimate: M.
Deliverables: Filtering capability.
Agent Execution Checklist:
 - [ ] Add parser
 - [ ] Integrate evaluation
 - [ ] Test cases
 - [ ] Commit

-------------------------------------------------------------------
T9.1 Prune By Count
-------------------------------------------------------------------
ID: T9.1
Title: Prune Old Snapshots (Keep Last N)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Storage may bloat; need simple retention.
Objective: prune --keep-last=N remove older non-protected snapshots (protected tag baseline).
Rationale: Disk management.
Dependencies: Index, tags.
Preconditions: Multiple snapshots present.
Scope (In): Prune command with dry-run (default) and --apply.
Scope (Out): Complex policy DSL.
Implementation Steps:
 1. Parse N; load index; sort by created_utc desc.
 2. Mark protected snapshots (tag baseline) never deleted.
 3. Identify candidates beyond N; display list if dry-run.
 4. If --apply: delete directories + remove from index.
Data Structures: None new.
File Targets: prune command file, index update after deletes.
Testing & Validation: Create N+2 snapshots; prune keep-last N; verify only extras removed.
Acceptance Criteria: Dry-run safe; apply deletes expected; index consistent.
Edge Cases: N >= count => no action.
Rollback Strategy: No built-in restore; communicate risk.
Risks & Mitigations: Accidental deletion—dry-run default.
Follow-Up Tasks: Age prune (T9.2).
Time Estimate: S.
Deliverables: Prune count feature.
Agent Execution Checklist:
 - [ ] Implement command
 - [ ] Test dry-run & apply
 - [ ] Commit

-------------------------------------------------------------------
T9.2 Prune By Age
-------------------------------------------------------------------
ID: T9.2
Title: Age-Based Prune (--max-age)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need time-duration based removal.
Objective: prune --max-age=30d remove snapshots older than threshold (excluding protected tags).
Rationale: Automated hygiene.
Dependencies: T9.1.
Preconditions: Snapshots with varying created_utc values.
Scope (In): Duration parsing, combination logic with keep-last.
Scope (Out): Complex expressions.
Implementation Steps:
 1. Parse duration; compute cutoff time.
 2. Filter index snapshots older than cutoff excluding protected.
 3. Combine with keep-last if both provided (intersection of candidate sets or union? Choose union for broader deletion clarity; document).
 4. Dry-run / apply actions same as T9.1.
Data Structures: None new.
File Targets: Extend prune command.
Testing & Validation: Adjust manifest created_utc manually for test; run prune.
Acceptance Criteria: Only targets older; protected tags preserved.
Edge Cases: Invalid duration format -> ValidationException.
Rollback Strategy: Remove age branch.
Risks & Mitigations: Over deletion clarity—present counts before apply.
Follow-Up Tasks: None.
Time Estimate: XS.
Deliverables: Age prune capability.
Agent Execution Checklist:
 - [ ] Add duration parsing
 - [ ] Integrate with prune logic
 - [ ] Test scenarios
 - [ ] Commit

-------------------------------------------------------------------
T10.1 Test Framework Setup
-------------------------------------------------------------------
ID: T10.1
Title: Add PHPUnit Test Harness
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: No automated tests; risk of regression.
Objective: Introduce PHPUnit (or Pest) baseline with one unit test.
Rationale: Foundation for later integration tests.
Dependencies: Composer (T1.1).
Preconditions: composer.json present.
Scope (In): phpunit dev dependency, bootstrap config, first test (UID generation uniqueness/pattern).
Scope (Out): High coverage.
Implementation Steps:
 1. composer require --dev phpunit/phpunit.
 2. Add phpunit.xml.dist in project root (tests/ as source).
  3. tests/bootstrap.php sets env SNAPPY_SNAPSHOT_ROOT to temp path.
 4. Write tests/unit/UidTest.php verifying uniqueness and length.
 5. Update .gitignore for /coverage (if coverage used later).
Data Structures: None.
File Targets: composer.json update, phpunit.xml.dist, tests/*.
Testing & Validation: vendor/bin/phpunit passes.
Acceptance Criteria: Test suite runs green.
Edge Cases: None.
Rollback Strategy: Remove dev dependency & test files.
Risks & Mitigations: Minimal.
Follow-Up Tasks: Integration tests.
Time Estimate: S.
Deliverables: Test harness.
Agent Execution Checklist:
 - [ ] Add dependency
 - [ ] Add config & bootstrap
 - [ ] Add unit test
 - [ ] Run tests
 - [ ] Commit

-------------------------------------------------------------------
T10.2 Integration Tests (Local)
-------------------------------------------------------------------
ID: T10.2
Title: Local Snapshot Flow Test
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need end-to-end coverage for snapshot creation & listing.
Objective: Implement test that simulates snapshot create, list, tag, verify.
Rationale: Detect regressions across core path.
Dependencies: T10.1, T3.1 provider abstraction (enable stub provider).
Preconditions: Test harness running.
Scope (In): Use stub provider producing small file; isolate env.
Scope (Out): Remote operations.
Implementation Steps:
 1. Add stub provider class under tests/fixtures.
 2. Inject via environment flag or provider registry override in test bootstrap.
 3. Run CLI commands via ProcessRunner inside test.
 4. Assertions: manifest-v2.json exists; list returns entry; tag add persists.
Data Structures: Stub output file.
File Targets: tests/integration/SnapshotFlowTest.php, fixtures.
Testing & Validation: Run phpunit group integration.
Acceptance Criteria: Test passes consistently.
Edge Cases: Path collisions—use unique temp dir.
Rollback Strategy: Remove integration test files.
Risks & Mitigations: Race issues minimal.
Follow-Up Tasks: Remote integration (T10.3).
Time Estimate: M.
Deliverables: Integration test.
Agent Execution Checklist:
 - [ ] Add stub provider
 - [ ] Write test
 - [ ] Run & verify
 - [ ] Commit

-------------------------------------------------------------------
T10.3 S3 MinIO Integration Test
-------------------------------------------------------------------
ID: T10.3
Title: Remote Push/Pull Integration (MinIO)
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need to validate remote operations work.
Objective: Spin up MinIO, configure S3 remote, push then pull snapshot verifying checksum.
Rationale: Confidence in remote storage layer.
Dependencies: T10.2 integration foundation, Docker available.
Preconditions: MinIO accessible (skip test if not).
Scope (In): Docker spin-up script, test case orchestrating push/pull.
Scope (Out): Multipart/parallel uploads.
Implementation Steps:
 1. Add tests/integration/RemoteMinioTest.php.
 2. In setUp: run docker to start MinIO container with ephemeral credentials.
 3. Wait for health endpoint.
 4. Configure remote via CLI remote add.
 5. Create snapshot; push to remote; remove local snapshot folder; pull; verify checksum.
 6. Tear down container.
Data Structures: None.
File Targets: Remote test file & optional helper script.
Testing & Validation: phpunit group remote.
Acceptance Criteria: Test passes; no leftover containers.
Edge Cases: Docker absent env var -> mark test skipped.
Rollback Strategy: Remove test & helpers.
Risks & Mitigations: Flakiness—add retry for health check.
Follow-Up Tasks: Presigned sharing test (future).
Time Estimate: M.
Deliverables: Remote integration test.
Agent Execution Checklist:
 - [ ] Implement test
 - [ ] Add docker run logic
 - [ ] Validate push/pull
 - [ ] Commit

-------------------------------------------------------------------
T10.4 Share Token Flow Test
-------------------------------------------------------------------
ID: T10.4
Title: Share Token Single-Use Integration Test
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Token creation & fetch logic must be reliable.
Objective: Validate share create + fetch single-use enforcement.
Rationale: Prevent reuse vulnerabilities.
Dependencies: T6.2 share fetch implemented.
Preconditions: Share features implemented.
Scope (In): Integration test simulating token creation & consumption.
Scope (Out): Presigned / encryption.
Implementation Steps:
 1. Create snapshot via stub provider.
 2. Create token (capture raw token).
 3. Fetch using token => success.
 4. Re-fetch token => expect failure.
Data Structures: None.
File Targets: tests/integration/ShareTokenTest.php.
Testing & Validation: phpunit group share.
Acceptance Criteria: Test passes; reuse fails with expected error code.
Edge Cases: Expired token scenario optional test.
Rollback Strategy: Remove test.
Risks & Mitigations: Low.
Follow-Up Tasks: Encryption test (later).
Time Estimate: S.
Deliverables: Share token integration test.
Agent Execution Checklist:
 - [ ] Write test
 - [ ] Run & verify
 - [ ] Commit

-------------------------------------------------------------------
T11.1 Hash Store Prototype
-------------------------------------------------------------------
ID: T11.1
Title: Optional Content-Addressable Storage Prototype
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Duplicate snapshot files waste space.
Objective: Store artifacts by hash under objects/ and reference them in manifest without duplication.
Rationale: Size efficiency, groundwork for dedupe.
Dependencies: Manifest v2.
Preconditions: Snapshots created normally.
Scope (In): Optional flag at creation; object path logic; manifest additions (object_hash, stored_inline bool).
Scope (Out): Retroactive migration; GC (T11.2).
Implementation Steps:
 1. After compression, compute sha256.
 2. Object path: objects/sha256/ab/<fullhash> (first two chars subdir).
 3. If not exists copy file; else discard local duplicate and symlink or copy referencing object (choose copy if symlink portability concern; store pointer in manifest?).
 4. In manifest file entry add object_hash and maybe original logical name.
 5. Add flag detection to enable path.
Data Structures: Extended file entry.
File Targets: snapshot creation logic, manifest builder.
Testing & Validation: Create two identical snapshots; second should not duplicate object file size (if using hardlink or copy detection measure).
Acceptance Criteria: Hash object reused; manifest reflects object_hash.
Edge Cases: Symlink unsupported -> fallback to copy.
Rollback Strategy: Remove flag logic & object referencing.
Risks & Mitigations: Incomplete cleanup—address in T11.2.
Follow-Up Tasks: GC.
Time Estimate: M.
Deliverables: Content-addressable option.
Agent Execution Checklist:
 - [ ] Add flag & logic
 - [ ] Implement object path storage
 - [ ] Test duplicate scenario
 - [ ] Commit

-------------------------------------------------------------------
T11.2 GC for Unreferenced Objects
-------------------------------------------------------------------
ID: T11.2
Title: Garbage Collect Orphan Objects
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Orphan object files accumulate after deleting snapshots.
Objective: Scan manifests, find referenced object hashes, delete unreferenced from objects/.
Rationale: Recover disk space.
Dependencies: T11.1.
Preconditions: Content-addressable snapshots present.
Scope (In): gc objects command with --dry-run default; --apply to execute.
Scope (Out): Quarantine area (could add later).
Implementation Steps:
 1. Collect all object_hash from manifest-v2 files.
 2. Walk objects/sha256 tree; mark files missing from set.
 3. If dry-run list candidates; if apply unlink.
 4. Summary stats printed (#kept, #removed, bytes reclaimed).
Data Structures: Set of hashes.
File Targets: gc command implementation.
Testing & Validation: Create snapshot, delete manifest snapshot folder leaving object; run gc returns candidate; apply removes.
Acceptance Criteria: Orphans removed only when apply.
Edge Cases: Concurrent creation—recommend not running gc concurrently (warn).
Rollback Strategy: None (destructive) -> caution in docs.
Risks & Mitigations: Accidental deletion—default dry-run.
Follow-Up Tasks: Optional quarantine.
Time Estimate: S.
Deliverables: GC command.
Agent Execution Checklist:
 - [ ] Implement command
 - [ ] Test dry-run & apply
 - [ ] Commit

-------------------------------------------------------------------
T12.1 Doctor Command
-------------------------------------------------------------------
ID: T12.1
Title: System Health Diagnostics
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Users need quick status to troubleshoot issues.
Objective: doctor command performing config, index, disk, remote connectivity, integrity checks.
Rationale: Reduces manual debugging.
Dependencies: Index, loader.
Preconditions: Snapshots + index present.
Scope (In): PASS/WARN/FAIL output; exit code non-zero if any FAIL.
Scope (Out): Auto repair actions.
Implementation Steps:
 1. Checks: config parse, index parse, free disk > threshold (200MB), remote list attempt (timeout 3s) each remote, random snapshot checksum verify.
 2. Provide --json mode.
 3. Summarize results.
Data Structures: Diagnostic report array.
File Targets: doctor command file.
Testing & Validation: Corrupt index manually test FAIL; remove snapshot file test checksum fail.
Acceptance Criteria: Accurate status; proper exit codes.
Edge Cases: No snapshots -> integrity check SKIP not FAIL.
Rollback Strategy: Remove command.
Risks & Mitigations: Long remote timeouts—short timeout.
Follow-Up Tasks: Add more checks later.
Time Estimate: S.
Deliverables: Doctor command.
Agent Execution Checklist:
 - [ ] Implement checks
 - [ ] Test scenarios
 - [ ] Commit

-------------------------------------------------------------------
T12.2 Metrics Summary
-------------------------------------------------------------------
ID: T12.2
Title: Snapshot Metrics Command
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Need quick stats on data set.
Objective: metrics command summarizing counts, total size, tag distribution, age buckets (<1d,1-7d,8-30d,>30d).
Rationale: Capacity insight & retention planning.
Dependencies: Index.
Preconditions: Index populated.
Scope (In): metrics command producing table & JSON.
Scope (Out): Historical trending storage.
Implementation Steps:
 1. Parse index; compute aggregates.
 2. Age bucket classification based on created_utc.
 3. Output to table or JSON if --json.
Data Structures: Metrics summary object.
File Targets: metrics command file.
Testing & Validation: Adjust created timestamps to test buckets.
Acceptance Criteria: Accurate counts; JSON matches table values.
Edge Cases: No snapshots -> zeros.
Rollback Strategy: Remove command file.
Risks & Mitigations: Minimal.
Follow-Up Tasks: Export metrics to external system (future).
Time Estimate: XS.
Deliverables: Metrics command.
Agent Execution Checklist:
 - [ ] Implement metrics compute
 - [ ] Test buckets
 - [ ] Commit

-------------------------------------------------------------------
T13.1 New README Structure
-------------------------------------------------------------------
ID: T13.1
Title: Rewrite README for New Architecture
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: README outdated after new CLI & features.
Objective: Provide authoritative README with Quick Start, Concepts, Commands, Sharing, Indexing, Tagging, Prune, Roadmap link.
Rationale: Onboarding efficiency.
Dependencies: CLI restructure (T5.*), sharing (T6.*) complete.
Preconditions: Feature set stable.
Scope (In): Overhaul README; highlight breaking changes acceptance.
Scope (Out): Developer internals (architectural doc separate T13.2).
Implementation Steps:
 1. Sections: Tagline, Features list, Installation (Composer/manual), Quick Start commands, Snapshot Lifecycle, Manifest v2 explanation, Sharing workflow, Index & Filters, Compression, Tagging & Prune, Verify & Doctor, Roadmap link.
 2. Remove references to legacy command names.
 3. Add note on rewrite strategy (no deprecation).
Data Structures: Markdown only.
File Targets: README.md.
Testing & Validation: Manual review; ensure all commands exist.
Acceptance Criteria: README matches implemented features; no stale items.
Edge Cases: None.
Rollback Strategy: Revert file.
Risks & Mitigations: Drift—update when features finalize.
Follow-Up Tasks: Architecture and share guides.
Time Estimate: S.
Deliverables: Updated README.
Agent Execution Checklist:
 - [ ] Draft new README
 - [ ] Validate references
 - [ ] Commit

-------------------------------------------------------------------
T13.2 Developer Guide
-------------------------------------------------------------------
ID: T13.2
Title: Architecture & Extension Guide
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Contributors need clarity on layers & extension points.
Objective: docs/dev/architecture.md explaining Domain, Application, Infrastructure, CLI, Support layers and data flow.
Rationale: Faster contributor onboarding; consistent design decisions.
Dependencies: Directory restructure done; Manifest v2 in place.
Preconditions: Core features implemented.
Scope (In): Layer descriptions, class role examples, manifest anatomy, index flow, share token lifecycle diagram (ASCII acceptable).
Scope (Out): API stability guarantees (not yet finalized).
Implementation Steps:
 1. Outline sections: Overview, Layer Responsibilities, Snapshot Lifecycle Sequence, Manifest v2 Fields, Index Update Flow, Share Token Lifecycle, Extension Points (DumpProvider, Storage, Compression, Encryption).
 2. Provide ASCII diagram for snapshot create path.
Data Structures: Markdown doc.
File Targets: docs/dev/architecture.md.
Testing & Validation: Spell-check optional.
Acceptance Criteria: >= 500 words; all sections present.
Edge Cases: None.
Rollback Strategy: Remove file.
Risks & Mitigations: Staleness—tie update to release checklist.
Follow-Up Tasks: Add plugin guide later.
Time Estimate: S.
Deliverables: Architecture guide.
Agent Execution Checklist:
 - [ ] Write guide
 - [ ] Commit

-------------------------------------------------------------------
T13.3 User Guide for Sharing
-------------------------------------------------------------------
ID: T13.3
Title: Sharing & Encryption User Guide
Project Name: Snappy (rewrite of prototype)
Project Purpose: Snappy is a local-first developer tool to create, store, list, verify, and share database snapshots (initially SQL dumps) enriched with strong metadata and secure one-time sharing. Goals: simplicity, reliability, rich manifest metadata (Manifest v2), fast O(1) listing via indexes, optional compression, tagging & filtering, minimal retention, secure single-use sharing tokens, and maintainable modular architecture (Domain / Application / Infrastructure / CLI / Support). Backwards compatibility with the prototype is NOT required.
Rewrite Note: Clean rewrite; breaking changes are acceptable and expected. No deprecation warnings or transitional alias layers; legacy command names will be replaced outright.
Global Constraints: Plain PHP (>=8.1) with optional Composer. Avoid unnecessary complexity. Security focus ONLY on integrity and confidentiality of shared / one-time export artifacts (not local storage hardening). Policies beyond simple retention deferred.
Context Recap: Users need explicit instructions for share tokens, archives, encryption.
Objective: docs/share/guide.md with step-by-step usage scenarios.
Rationale: Reduce misuse & clarify security boundaries.
Dependencies: Sharing features (T6.*) and encryption (T7.1) implemented.
Preconditions: Commands stable.
Scope (In): Token creation, fetching, archive export, encryption, decryption, best practices.
Scope (Out): Presigned remote specifics beyond example.
Implementation Steps:
 1. Provide scenarios: Basic share (token only), Archive share, Encrypted archive share, Presigned remote (if implemented).
 2. Add SECURITY NOTES: token is secret; expiration semantics; passphrase strength.
Data Structures: Markdown.
File Targets: docs/share/guide.md.
Testing & Validation: Manually follow steps ensure they work.
Acceptance Criteria: Clear, accurate workflow examples.
Edge Cases: None.
Rollback Strategy: Remove guide.
Risks & Mitigations: Feature drift—update with each share enhancement.
Follow-Up Tasks: Possibly add FAQ.
Time Estimate: XS.
Deliverables: Share guide.
Agent Execution Checklist:
 - [ ] Draft guide
 - [ ] Validate steps
 - [ ] Commit

-------------------------------------------------------------------
T11.1/T11.2 Already Provided (Cross-reference)
-------------------------------------------------------------------
(See above for full details; included earlier—no duplication necessary.)

-------------------------------------------------------------------
T12.*, T13.* Already Provided (Cross-reference)
-------------------------------------------------------------------
(See above for full details; included earlier—no duplication necessary.)

-------------------------------------------------------------------
Backlog / Stretch (Concept Summaries, Not Formal Tickets)
-------------------------------------------------------------------
Incremental Snapshots: Add base_uid and delta artifacts referencing DB logs for partial restore.
Streaming Restore: Pipe decompressed dump directly to DB import command without full disk write.
Multi-Artifact Snapshots: Support ancillary directories (e.g., files/, config/) enumerated in manifest files array.
Anonymization Transforms: Transformation pipeline pre-write (PII scrubbing) with manifest record of applied transforms.
REST Wrapper & Web UI: HTTP service exposing snapshot list/pull/share with authentication layer.
zstd Compression: Additional compression provider with ratio & speed metrics in manifest.
BLAKE3 Checksums: Optionally faster hashing algorithm; record algo field in manifest checksums.
Multipart Parallel S3 Upload: Performance improvement for large (>64MB) artifacts.

END OF FULL TICKET SPECIFICATIONS

