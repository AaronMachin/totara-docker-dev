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



 ===================================================Ticket ID: T15A Title: Snapshot Delete Command (Remove Local Snapshot by UID or Prefix) Objective: Add a safe snapshot delete command: tsnap snapshot delete <uid|prefix> that removes one local snapshot directory (snaps/<uid>/) and prunes index.json atomically without affecting other snapshots.</uid>
Context: Snapshots accumulate under $SNAPPY_SNAPSHOT_BASE/snaps. Manual filesystem deletion risks stale index.json entries and user mistakes (ambiguous prefixes). Current lifecycle lacks a sanctioned deletion path; disk use can grow unbounded in CI/dev churn.
Rationale: Provide controlled cleanup, preserve integrity of index, reduce manual error risk, enable automation scripts.
Dependencies: Existing snapshot_manager (create/list/show/export/import/metrics), index_manager (writes index.json), snapshot_loader (used for resolution), output_formatter, command_router.
Preconditions:
Local snapshot store initialized.
UID/prefix corresponds to at least one snapshot.
No locking mechanism yet (multi-process race is out of scope; handled best-effort).
Scope (In):
New CLI command class snapshot_delete (name() returns snapshot.delete).
Usage: tsnap snapshot delete <uid|prefix>
Resolves prefix via existing snapshot_manager->resolve_uid(prefix,'local').
If ambiguous: exit code 64 (usage) with concise error.
If not found: exit code 2 (validation).
On success: remove directory snaps/<uid>/ recursively, update index.json (remove entry), return exit code 0.</uid>
JSON output: { deleted_uid, index_pruned:true, size_bytes?:<int|null> } command field snapshot.delete.
Text output: deleted <uid> (<bytes> bytes) – bytes optional if determinable by summing files under snapshot dir pre-removal.</bytes></uid>
Idempotency: second attempt returns not found (exit 2).
Tests covering success, ambiguous, not found, idempotent second delete, JSON parity.
Scope (Out):
Bulk deletion (multi-UID).
Remote snapshot deletion.
Interactive confirmations / trash bin.
Retention policy logic (future ticket).
Concurrency lock.
Implementation Sketch:
Add snapshot_manager->delete(string $uid): array|false returning ['uid'=>..., 'bytes'=>int] or false if not found.
Implement recursive delete (depth-first, ignore transient file errors only after attempt; fail fast if directory removal fails).
After successful directory removal, call index_manager to rebuild or prune entry (remove entry by UID then atomic write).
New command snapshot_delete: parse argument; run resolution; call delete; aggregate metrics; output JSON + text.
Register in tsnap_cli.php (snapshot group).
Ensure output_formatter JSON canonical command = snapshot.delete.
Data Structures / Schemas:
index.json unchanged.
No new schema files.
Tests:
SnapshotDeleteTest: creates two snapshots; delete one; verify absent in subsequent list.
AmbiguousPrefixTest: two snapshots with shared starting chars; attempt short prefix -> exit 64.
NotFoundTest: random UID -> exit 2.
IdempotentTest: deleting same UID again -> exit 2.
JsonOutputTest: --json mode includes command snapshot.delete and deleted_uid.
Documentation:
README: Add subsection “Delete a snapshot” with example.
No ticket cross-references needed.
Acceptance Criteria:
Command available and discoverable in help (group: Snapshot).
Behavior & exit codes match spec.
Index entry removed exactly once.
JSON/text outputs correct and canonical name emitted.
All tests (existing + new) green.
Edge Cases:
Partial removal failure (permissions) → exit 1 (generic error).
Directory missing after resolution (race) → treat as not found (exit 2).
Very large snapshot: still streaming deletion; memory constant.
Risks & Mitigations:
Ambiguous prefix deletion risk → explicit fail on ambiguity.
Time-of-check/time-of-use race → best-effort; if directory disappears during removal treat as not found.
Rollback Strategy: Revert commit; no data migration. Deleted snapshots cannot be restored by tooling (documented).
Time Estimate: S (small).
Deliverables:
snapshot_delete command class
snapshot_manager delete method
tsnap_cli.php registration
Tests
README update
Commit Message: T15A feat(snapshot): add snapshot delete command
Agent Execution Checklist: <input></input> Add delete method to snapshot_manager <input></input> Implement snapshot_delete command <input></input> Register command in tsnap_cli.php <input></input> Add tests (success/ambiguous/notfound/idempotent/json) <input></input> Update README <input></input> Run full PHPUnit <input></input> Commit (T15A feat(snapshot): add snapshot delete command)
<hr></hr>
 ===================================================Ticket ID: T15B Title: Snapshot Module Refactor (Separation of Concerns) Objective: Refactor snapshot subsystem for readability & maintainability by extracting creation, resolution, and deletion responsibilities into dedicated classes without altering observable behavior.
Context: snapshot_manager currently combines:
Creation workflow (dump provider selection, manifest writing, index updating)
UID resolution & ambiguity handling
(New from T15A) deletion logic
Listing & manifest access Coupling obstructs future enhancements (retention, differential exports, faster indexing). Tests cover current behaviors allowing safe structural change.
Rationale: Reduce complexity, isolate change impact, prepare for retention/prune and concurrency features.
Dependencies: Stable test suite; T15A (if merged) to relocate delete logic.
Preconditions: All snapshot commands functional; no pending structural tickets overlapping.
Scope (In):
Introduce src/snapshot/core/:
snapshot_creator (create logic; orchestrates dump provider, files, manifest, index update)
snapshot_resolver (resolve_uid, ambiguity detection)
snapshot_deleter (delete logic from T15A)
Introduce src/snapshot/provider/ relocation of existing provider classes (dump_provider_interface, dump_provider_resolver, fake_dump_provider, tdb_dump_provider).
snapshot_manager becomes façade delegating to new components; public method signatures unchanged.
snappy_autoload.php updated for new directories.
Adjust imports in commands/tests accordingly.
Add minimal unit test (SnapshotFacadeRefactorTest) asserting create + resolve + show still functional (smoke).
Scope (Out):
Behavior changes or new features.
Performance optimizations.
Renaming public CLI commands or output changes.
Schema alterations.
Implementation Sketch:
Create new directories.
Move provider classes (update namespaces).
Extract create() internals to snapshot_creator::create(...) returning uid & metadata.
Extract resolve_uid() logic to snapshot_resolver.
Extract delete() to snapshot_deleter (if present).
snapshot_manager composes instances (lazy or constructor).
Update commands (snapshot_create, snapshot_delete, others) to continue calling snapshot_manager only (no direct use of new classes).
Run entire suite after each stage.
Data Structures: Unchanged.
Tests:
Existing snapshot tests must pass unchanged.
New structural smoke test verifying outputs (not asserting internal class presence, just behavior).
Ensure no changes to JSON payload schemas.
Documentation:
Optional note in refactor_notes.md summarizing LOC and class extraction (brief).
Acceptance Criteria:
All tests green; zero diff in command outputs except possibly order of internal debug lines (none printed in production).
snapshot_manager file size reduced; responsibilities delegated.
Edge Cases: Namespace autoload breakage—caught by failing tests.
Risks & Mitigations: Hidden logic difference—tests ensure parity. Autoload misconfiguration—adjust snappy_autoload.php early.
Rollback Strategy: Revert commit; restore original snapshot_manager.
Time Estimate: M (medium).
Deliverables:
New core & provider directories/classes
Updated snapshot_manager
Autoloader adjustments
Smoke test
Refactor note
Commit Message: T15B feat(snapshot): refactor snapshot module into core/provider components
Agent Execution Checklist: <input></input> Create directories core/ & provider/ <input></input> Move provider classes & update namespaces <input></input> Extract creator/resolver/deleter classes <input></input> Refactor snapshot_manager to delegate <input></input> Update autoloader <input></input> Add smoke test <input></input> Run full PHPUnit <input></input> Commit (T15B feat(snapshot): refactor snapshot module)
<hr></hr>
 ===================================================Ticket ID: T15C Title: Project Review (Architecture & Roadmap Foundation) Objective: Produce comprehensive review document (docs/review.md) analyzing architecture, strengths, weaknesses, risks, and improvement opportunities (sections 1–8).
Context: Project matured through snapshot lifecycle (create/list/show/export/import/metrics/share/aliases/config/remote). Structural clarity and forward planning needed before deeper features (retention, concurrency, validation CLI).
Rationale: Establish data-driven roadmap; reduce ad-hoc decisions; align future tickets.
Dependencies: None (pure documentation).
Preconditions: Current code builds & tests green.
Scope (In):
Create docs/review.md with sections:
Overview
Architecture Layering (CLI, snapshot, share, remote, config, util, support)
Strengths
Weaknesses
Gaps / Missing Capabilities
Risks (technical, operational, security)
Recommendations (Short, Medium, Long term)
Seed Ticket List (bulleted)
Evidence: cite representative files (brief, no large code quotes).
Run tests for baseline sanity.
Scope (Out):
Code changes.
Prioritization locking (recommendations only).
Implementation Sketch: Draft by inspection; ensure actionable phrasing (“Introduce advisory lock layer”, not vague).
Tests: None (documentation only); full suite still run to confirm no incidental breakage.
Documentation: review.md only; optional README link (one-liner “See docs/review.md”).
Acceptance Criteria: review.md present; sections complete; at least 5 Short, 5 Medium, 3 Long recommendations.
Edge Cases: N/A (doc only).
Risks & Mitigations: Subjectivity → mitigate by referencing concrete modules.
Rollback Strategy: Delete review.md.
Time Estimate: S.
Deliverables: review.md (sections 1–8).
Commit Message: T15C docs(review): add holistic project assessment
Agent Execution Checklist: <input></input> Inspect codebase <input></input> Draft review.md <input></input> (Optional) Link from README <input></input> Run full PHPUnit <input></input> Commit (T15C docs(review): add holistic project assessment)
<hr></hr>
 ===================================================Ticket ID: T15D Title: Technical Deep-Dive (Performance, Integrity, Concurrency) Objective: Expand review.md with Section 9 Technical Deep-Dive analyzing streaming patterns, integrity guarantees, complexity, concurrency assumptions, error handling, extensibility, security posture.
Context: Following high-level review (T15C), deeper systematic engineering appraisal needed to justify optimization & safety tasks.
Rationale: Identify hot paths & structural risks before adding retention, locks, differential exports.
Dependencies: T15C review.md exists.
Preconditions: Existing tests pass.
Scope (In): Add Section 9 to review.md with subsections: 9.1 Streaming & Memory (per operation; buffers) 9.2 Hashing & Integrity Flow 9.3 Complexity (Big-O for create, list, export, import, delete) 9.4 Concurrency & Atomicity (where atomic, where not) 9.5 Error Handling Patterns 9.6 Configuration & Persistence 9.7 Extensibility Hooks 9.8 Security Posture (credentials, injection risk) 9.9 Hotspot Candidates / Proposed Benchmarks
Scope (Out): Implementing improvements.
Implementation Sketch: Analyze code paths; derive complexity (e.g., list O(n) snapshots, export O(f) files, etc.); document atomic rename usage.
Tests: None (doc); run suite for assurance.
Acceptance Criteria: All subsections filled with specific, actionable points and at least 6 hotspot items.
Edge Cases: N/A.
Risks & Mitigations: Over-detail → keep concise bullet style.
Rollback Strategy: Remove added section.
Time Estimate: S.
Deliverables: Updated review.md with Section 9.
Commit Message: T15D docs(review): add technical deep-dive section
Agent Execution Checklist: <input></input> Analyze code paths <input></input> Append Section 9 <input></input> Run full PHPUnit <input></input> Commit (T15D docs(review): add technical deep-dive section)
<hr></hr>
 ===================================================Ticket ID: T15E Title: Usability Review (CLI & Developer UX) Objective: Evaluate all CLI commands + aliases; append Section 10 Usability Review to review.md with table (Command | Strengths | Issues | Recommendations).
Context: Commands: snapshot (create/list/show/export/import/delete/metrics), share (create/import), remote (add/list/remove/pull), config (get/set), gc (objects/temp), alias (future), help & root aliases (create/list/show). Need systematic UX evaluation.
Rationale: Improve consistency & clarity before expanding surface area (alias management, validation).
Dependencies: review.md with Sections 1–9.
Preconditions: All commands working.
Scope (In):
Manual execution in isolated temp environment for each command (text + JSON).
Table summarizing each command group.
Identify at least one improvement per command group.
Add suggestions for wording, error codes, help enhancements, alias discoverability.
Scope (Out): Implementing improvements.
Implementation Sketch: Create matrix; run commands; capture representative outputs; synthesize into concise table.
Tests: None (doc).
Acceptance Criteria: Section 10 present with table and actionable recommendations.
Edge Cases: N/A.
Risks: Subjectivity → ground recommendations in observed output.
Rollback: Remove section.
Time Estimate: S.
Deliverables: Updated review.md (Section 10).
Commit Message: T15E docs(review): add CLI usability assessment
Agent Execution Checklist: <input></input> Run each command (text & JSON) <input></input> Build table & recommendations <input></input> Append Section 10 <input></input> Run full PHPUnit <input></input> Commit (T15E docs(review): add CLI usability assessment)
<hr></hr>
 ===================================================Ticket ID: T15F Title: Ticket Synthesis from Reviews Objective: Translate review.md Sections 7, 9, 10 recommendations into a set of 8–15 future ticket stubs (T16A+), appended to full_tickets.md ahead of backlog.
Context: Reviews identified structured improvements; need formalized backlog entries.
Rationale: Operationalize analysis → actionable roadmap.
Dependencies: Completion of T15C, T15D, T15E (review.md populated).
Preconditions: review.md includes recommendations.
Scope (In):
Parse recommendations.
Create stubs: ID (T16A ... sequential), Title, Objective, Rationale, Scope (In/Out), Acceptance Criteria, Time Estimate (S/M/L).
Append to full_tickets.md just before Backlog / Stretch.
Scope (Out): Detailed implementation specs (stubs only). Implementations themselves.
Implementation Sketch: Enumerate recommendations, cluster by theme (integrity, UX, performance, security), assign IDs.
Tests: None.
Acceptance Criteria: 8–15 stubs added; IDs unique; no other edits.
Edge Cases: Less than 8 actionable items → combine or refine until minimum met.
Risks: Overlapping scopes → ensure each distinct.
Rollback: Remove stubs.
Time Estimate: S.
Deliverables: Updated full_tickets.md (stubs inserted).
Commit Message: T15F docs(planning): synthesize improvement ticket stubs
Agent Execution Checklist: <input></input> Extract recs from review.md <input></input> Draft stubs T16A+ <input></input> Insert into full_tickets.md <input></input> Run full PHPUnit <input></input> Commit (T15F docs(planning): synthesize improvement ticket stubs)
<hr></hr>
 ===================================================Ticket ID: T15G Title: Snapshot Retention & Prune Policy (Specification / Optional Implementation) Objective: Define (and optionally implement) a retention policy system to automatically prune snapshots (e.g., keep last N, keep daily for 7 days, weekly for 8 weeks). Initial scope: specification only unless explicitly toggled to implement.
Context: Deletion (T15A) manual; disk growth uncontrolled; need policy abstraction decoupled from ad-hoc shell scripts.
Rationale: Provide deterministic, auditable pruning with dry-run evaluation.
Dependencies: T15A (delete), snapshot_manager listing, index_manager.
Preconditions: Snapshots enumerated reliably; deletion safe.
Scope (In) – Spec Phase (default):
Document policy grammar (YAML or inline expression) e.g. keep:last=30,keep:daily=7,keep:weekly=8.
Define evaluation algorithm (sort by created desc → classify).
Outline CLI: tsnap snapshot prune [--policy='...'] [--policy-file=path] [--dry-run]
Dry-run output: list of candidate deletions with size total & summary.
JSON schema for output.
Identify exit codes (0 success, 64 usage, 2 invalid policy). If Implementation Approved:
Implement parser + evaluator.
Integrate with snapshot_manager & deletion.
Add tests for policy evaluation & dry-run vs real run.
Scope (Out): Remote pruning; time zone manipulation; partial retention per type beyond placeholders.
Implementation Sketch (If Implementing): Policy parse → evaluate snapshot groups → mark final survivors → produce deletion set; dry-run prints; non-dry-run executes deletes sequentially.
Tests:
Policy parsing (valid/invalid).
Dry-run candidate list correctness.
Execution reduces snapshot count accordingly.
JSON mode parity.
Acceptance Criteria (Spec): Document produced & committed. Acceptance Criteria (Implementation): All tests green; command outputs match spec.
Edge Cases: Fewer snapshots than retention requirements (delete none). Ambiguous policy definitions (error).
Risks & Mitigations: Accidental deletion → dry-run strongly encouraged; ambiguous policies rejected.
Rollback Strategy: Remove command & doc; no persistent state.
Time Estimate: S (spec), L (implementation).
Deliverables: Spec doc OR implemented command + tests.
Commit Message: T15G docs(spec): snapshot retention policy (if spec) OR T15G feat(snapshot): implement retention prune command
Agent Execution Checklist (Spec): <input></input> Draft spec doc <input></input> Add to docs/ <input></input> Run tests <input></input> Commit (Implementation adds: code, tests, registration)
<hr></hr>
 ===================================================Ticket ID: T15H Title: Benchmark & Performance Harness Objective: Add reproducible benchmarking scripts to measure snapshot create/export/import/delete metrics and memory usage using synthetic datasets.
Context: Before optimization (locks, retention), need baseline timing & memory to detect regressions.
Rationale: Enable quantitative evaluation & future performance targets.
Dependencies: Stable snapshot lifecycle.
Preconditions: FAKE dump mechanism available (SNAPPY_FAKE_DUMP) for controlled size generation.
Scope (In):
benchmark/ directory with PHP scripts:
bench_create.php (loop N snapshots with configurable message size)
bench_export_import.php
bench_full_cycle.php
Each script outputs JSON lines: {op, count, elapsed_ms, rss_bytes, avg_ms_per_op}
README section “Benchmarks” describing usage & environment variables (BENCH_SNAPSHOT_COUNT, BENCH_DUMP_SIZE_KB).
Optional helper to aggregate results.
Scope (Out): Automated CI gating; external profiling integration.
Implementation Sketch: Use hrtime(true) and memory_get_usage(); isolate temp root per run; warm-up once.
Tests: Minimal (invoke scripts with very small N to ensure non-fatal) – optional; not full performance assertions.
Acceptance Criteria: Scripts run locally producing structured JSON; documentation clear.
Edge Cases: Large N causing long runtime—document recommended limits.
Risks: Misuse in production env → clearly mark “DEV / BENCH USE ONLY”.
Rollback: Remove benchmark/ directory.
Time Estimate: M.
Deliverables: benchmark scripts + README changes.
Commit Message: T15H feat(benchmark): add snapshot performance harness
Agent Execution Checklist: <input></input> Create benchmark scripts <input></input> Update README <input></input> (Optional) Add smoke test <input></input> Run full PHPUnit <input></input> Commit (T15H feat(benchmark): add snapshot performance harness)
<hr></hr>
 ===================================================Ticket ID: T15I Title: Concurrency Guard (Advisory File Locking) Objective: Introduce advisory locking to prevent concurrent mutating operations (create/delete/export/import) from colliding and corrupting index or partial artifacts.
Context: No locks currently; simultaneous operations risk race conditions (index truncation, partial delete mid-export).
Rationale: Improve integrity reliability under parallel invocations (CI or multi-shell use).
Dependencies: snapshot_manager mutation paths; index_manager atomic writes.
Preconditions: Filesystem supports flock (POSIX).
Scope (In):
lock_manager (src/support/process/lock_manager.php) implementing acquire($name), release().
Use single global lock file at $SNAPPY_SNAPSHOT_BASE/.lock OR separate names per operation if justified (“snapshot_mutation”).
Wrap create/delete/export/import calls (CLI entrypoint before action).
On busy lock: exit code 65 (document).
JSON output: {error: “operation locked”, lock: name}.
Tests: simulated contention (fork or second process invocation) verifying second fails fast.
Scope (Out): Cross-host distributed locking; read/write lock distinction.
Implementation Sketch:
lock_manager uses fopen + flock(LOCK_EX|LOCK_NB).
Acquire at start of each mutating command run(); ensure try/finally release.
Provide helper in context or utility function.
Data Structures: None new beyond lock file.
Tests:
Contention test (spawn background php creating lock then attempt second command).
Normal path unaffected.
Acceptance Criteria: Mutating operations mutually exclusive; non-mutating commands (list/show/metrics) unaffected.
Edge Cases: Stale lock file after crash—flock semantics auto-release on process exit.
Risks & Mitigations: Over-serialization → acceptable initial tradeoff.
Rollback Strategy: Remove lock usage.
Time Estimate: M.
Deliverables: lock_manager, command updates, tests, doc note.
Commit Message: T15I feat(concurrency): add advisory lock for snapshot mutations
Agent Execution Checklist: <input></input> Implement lock_manager <input></input> Integrate into mutating commands <input></input> Add contention test <input></input> Update README (Concurrency) <input></input> Run full PHPUnit <input></input> Commit (T15I feat(concurrency): add advisory lock)
<hr></hr>
 ===================================================Ticket ID: T15J Title: Error Code Catalog & Consistency Audit Objective: Document and standardize CLI exit codes across commands; produce docs/error_codes.md; align inconsistent usages.
Context: Exit codes (usage=64, validation=2, generic=1, etc.) appear but not centrally documented; future automation benefits from stable mapping.
Rationale: Predictable scripting & monitoring; reduces future divergence.
Dependencies: Existing commands.
Preconditions: Working suite.
Scope (In):
Enumerate current exit codes per command.
Map constants (if any) to meaning.
Adjust obvious inconsistencies (e.g., ambiguous prefix vs not found).
Provide docs/error_codes.md with table: Code | Meaning | Example Command / Scenario.
Minor updates to commands to standardize.
Scope (Out): Adding new codes beyond rationalization.
Implementation Sketch: Scan tests for expected codes; update mismatches; minimal edits for message consistency.
Tests: Adjust assertions if needed (update tests expecting old codes).
Acceptance Criteria: Doc complete; all tests green; no unreferenced codes used.
Edge Cases: Legacy codes desired to stay—retain if not harmful.
Risks & Mitigations: Breaking consumer scripts—document changes clearly in doc.
Rollback: Revert doc + code changes.
Time Estimate: S.
Deliverables: error_codes.md + code adjustments.
Commit Message: T15J docs(error-codes): catalog and align exit codes
Agent Execution Checklist: <input></input> Inventory codes <input></input> Adjust command returns <input></input> Create error_codes.md <input></input> Update tests <input></input> Run full PHPUnit <input></input> Commit (T15J docs(error-codes): catalog and align exit codes)
<hr></hr>
 ===================================================Ticket ID: T15K Title: Security Posture Review & Secret Handling Audit Objective: Analyze credential & secret handling, logging exposure risks, propose mitigations, and optionally implement a central redaction helper.
Context: Remote configs store key/secret; outputs attempt redaction; need explicit confirmation no leakage paths (errors, exceptions, debug logs).
Rationale: Prevent inadvertent secret exposure early; baseline before remote push/pull or signing.
Dependencies: remote_add/list/remove, config_manager, output_formatter.
Preconditions: Stable code.
Scope (In):
Add section to review.md or new doc security_review.md:
Secret Storage Locations
Redaction Paths
Potential Leakage Vectors (error messages, stack traces suppressed anyway)
Proposed Mitigations (central helper redact($value))
Future Hardening (rotation hooks)
Optional implement util/redact.php with redact(string $s): string (hash / fixed placeholder) and apply in remote list / error contexts.
Scope (Out): Encryption at rest, key rotation implementation.
Implementation Sketch: Survey code for echo/fwrite containing “secret”, “key”.
Tests: If helper added, ensure remote list still redacts properly.
Acceptance Criteria: Documentation produced; if helper added, tests still green; no new exposure identified.
Edge Cases: User intentionally sets key in snapshot message (out-of-scope).
Risks: Over-redaction reducing usability → only redact known secret fields.
Rollback: Remove helper/doc.
Time Estimate: S.
Deliverables: Doc (+ optional helper & minimal integration).
Commit Message: T15K docs(security): add security posture review (or feat if helper added)
Agent Execution Checklist: <input></input> Survey code paths <input></input> Draft doc/section <input></input> (Optional) Add redact helper & integrate <input></input> Run full PHPUnit <input></input> Commit (T15K docs(security): security posture review)
<hr></hr>
 ===================================================Ticket ID: T15L Title: Snapshot Restore Consistency Specification Objective: Define specification (no implementation) for snapshot restore command applying a snapshot’s SQL (and future assets) to a target database environment safely and deterministically.
Context: Lifecycle currently ends at import; applying to DB often manual; future restore requires structured plan aligning with existing deterministic, streaming principles.
Rationale: Prevent ad-hoc restore feature; plan safety (dry-run preview, idempotency guard).
Dependencies: snapshot_manager (manifest/file access), potential DB driver (out-of-scope now).
Preconditions: Snapshots contain SQL dumps (backup.sql[.gz]).
Scope (In): Spec Document:
Proposed command: tsnap snapshot restore <uid|prefix> [--dry-run] [--db-url=DSN] [--strategy=replace|fail-if-exists]
Dry-run: manifest introspection only; show size, estimated statements count (optional).
Output JSON schema (plan vs executed).
Exit codes: 0 success, 64 usage, 3 restore conflict, 2 not found, 1 execution error.
Safety: confirmation flag (maybe future). Scope (Out): Actual DB execution; multi-engine restore logic.
Implementation Sketch: Document streaming decompress + pipe to DB client approach (no memory load).
Tests: None (spec only).
Acceptance Criteria: Spec integrated in docs/ (restore_spec.md or added to review.md recommendations).
Risks: Spec drift if delayed—mitigate by anchoring to manifest fields.
Rollback: Remove spec file.
Time Estimate: M.
Deliverables: restore_spec.md (or section) with all above.
Commit Message: T15L docs(spec): snapshot restore command design
Agent Execution Checklist: <input></input> Draft restore spec <input></input> Add to docs/ <input></input> Run tests <input></input> Commit (T15L docs(spec): snapshot restore command design)
<hr></hr>
 ===================================================Ticket ID: T15M Title: Alias Management Command (List/Add/Remove) Objective: Provide first-class alias management via CLI (alias list/add/remove) for root snapshot command aliases currently configured through config set.
Context: Aliases presently configured by editing config (aliases.*). Managing via explicit commands improves discoverability and consistency (notably after default root aliases create/list/show).
Rationale: Enhance UX, reduce manual config editing complexity, ensure validation (only snapshot.* targets).
Dependencies: command_router alias support, config_manager.
Preconditions: Existing alias system functioning; config persists aliases.* keys.
Scope (In):
Commands:
alias list -> lists current alias mappings (text table + JSON).
alias add <alias> <snapshot.command></alias>
alias remove <alias></alias>
Validation: canonical must start with snapshot.; alias must be lowercase ^[a-z][a-z0-9_-]*$; reject collisions with existing primary groups (snapshot, remote, share, gc, config, alias).
Update help root grouping (Aliases section includes management commands).
JSON output consistent: command field alias.list / alias.add / alias.remove.
Tests: add/list/remove lifecycle; invalid target; collision; JSON parity.
Scope (Out): Bulk import/export of aliases; editing existing alias without remove/add pattern.
Implementation Sketch:
alias_list, alias_add, alias_remove command classes.
In add: modify config->set('aliases.<alias>', 'snapshot.xxx', true).</alias>
In remove: set to null (remove key) or use config->remove path.
Refresh alias registration only occurs at next process start (document) OR (optional) re-register in-process after change (in-scope if trivial).
Data Structures: Reuse config aliases.* object.
Tests:
Add new alias (metrics) then use alias command invocation.
Remove alias; invocation now fails (unknown root verb).
Invalid canonical (e.g., remote.list) exit 2.
Documentation:
README: “Managing aliases” section.
Help: ensure displayed.
Acceptance Criteria: All alias commands operational; existing default aliases preserved; tests green.
Edge Cases: Adding alias identical to existing default -> update mapping. Removing non-existent alias -> exit 2 with error.
Risks & Mitigations: User confusion about immediate availability—document re-run requirement if not hot-loaded.
Rollback: Remove command classes and help entries.
Time Estimate: S.
Deliverables: Commands, tests, README section.
Commit Message: T15M feat(cli): add alias management commands
Agent Execution Checklist: <input></input> Implement alias_* commands <input></input> Register commands <input></input> Add tests <input></input> Update README <input></input> Run full PHPUnit <input></input> Commit (T15M feat(cli): add alias management commands)
<hr></hr>
 ===================================================Ticket ID: T15N Title: Manifest Validation CLI Objective: Add snapshot validate <uid|prefix> command to verify manifest integrity by recomputing file hashes & manifest canonical hash (streaming) and reporting mismatches.
Context: Integrity currently implicit during import; post-import tampering detection missing; need on-demand check for CI / debugging.
Rationale: Enhances trust; allows periodic audits; precondition for diff or retention actions.
Dependencies: integrity_service (hashing), snapshot_manager, canonical_json.
Preconditions: Snapshot artifact directory intact.
Scope (In):
Command snapshot validate <uid|prefix>
Outputs (JSON): { uid, valid: bool, manifest_sha256_expected, manifest_sha256_actual, file_mismatches: [{name, expected, actual}], missing_files: [], extra_files: [] }
Text: “valid” or per-line mismatch summary.
Exit codes: 0 (valid), 3 (integrity mismatch), 2 (not found), 64 (usage).
Streaming: do not load entire files.
Support compressed backup.sql.gz detection (hash content uncompressed? Use existing stored hash semantics—if only size + stored checksum present, recompute same representation consistent with export logic).
Scope (Out): Remote validation; artifact regeneration; auto-fix.
Implementation Sketch:
Resolve UID.
Read manifest; canonicalize & hash; compare with stored checksums.
Iterate files list; verify presence & sha256 (using integrity_service).
Detect extra unexpected files (present not in manifest).
Compile results.
Tests:
Valid snapshot returns valid.
Tampered file (modify bytes) yields mismatch exit 3.
Deleted file yields missing entry.
Added extra file yields extra_files entry.
Acceptance Criteria: Accurate detection; correct exit codes; JSON shape; streaming maintained.
Edge Cases: Large files: maintain O(1) memory by chunk hashing.
Risks & Mitigations: Hash mismatch due to earlier inconsistent canonicalization—use same canonical routine as export.
Rollback: Remove command.
Time Estimate: S.
Deliverables: Command, tests.
Commit Message: T15N feat(snapshot): add manifest validation command
Agent Execution Checklist: <input></input> Implement validate command <input></input> Add hashing logic (reuse integrity_service) <input></input> Add tests (valid/tampered/missing/extra) <input></input> Update help <input></input> Run full PHPUnit <input></input> Commit (T15N feat(snapshot): add manifest validation command)
<hr></hr>
 ===================================================Ticket ID: T15O Title: Manifest Schema Version Enforcement & Upgrade Objective: Detect outdated manifests or non-canonical ordering and optionally upgrade to canonical v2 (manifest-v2.json) while preserving semantic content.
Context: Future schema evolution may require re-emission; early enforcement assists compatibility and validation tooling.
Rationale: Maintain uniform canonical manifests enabling stable hashing & diff operations.
Dependencies: canonical_json utility, existing manifest structure (schema_version=2 baseline).
Preconditions: Snapshots exist; some may have non-canonical key ordering if produced by earlier versions.
Scope (In):
Command snapshot schema-enforce <uid|prefix> [--upgrade]
Without --upgrade: report status { canonical: bool, required_version:2, current_version, differences:[] } (differences as list of structural variances: key_order, missing_keys).
With --upgrade: rewrite manifest-v2.json atomically using canonical ordering & stable formatting.
JSON & text outputs.
Exit codes: 0 (canonical OK), 6 (non-canonical / outdated), 2 (not found), 64 (usage), 1 (upgrade failure).
On upgrade produce backup manifest-v2.json.bak (single copy, overwritten on next upgrade attempt).
Scope (Out): Downgrade; multi-version migration logic (only to v2).
Implementation Sketch:
Resolve UID.
Load manifest; canonicalize; compare raw file content vs canonical JSON (normalized newline).
If non-canonical and --upgrade provided: write temp file + rename; backup original first.
Re-hash if needed? (Hash field inside manifest not defined as golden—if golden exists ensure recomputation consistent with spec.)
Data Structures: No change; may add upgrade meta file (optional) skipped here.
Tests:
Non-canonical fabricated manifest corrected on upgrade.
Canonical manifest no-op upgrade (exit 0).
Not found case.
JSON difference listing.
Acceptance Criteria: Accurate detection; safe canonical rewrite; tests green; no other side effects.
Edge Cases: Read-only filesystem (exit 1 upgrade failure). Corrupt JSON (exit 6 with parse warning).
Risks & Mitigations: Incorrect rewrite altering semantics — test freeze ensures equivalence aside from ordering.
Rollback: Revert command & code.
Time Estimate: M.
Deliverables: Command, tests.
Commit Message: T15O feat(snapshot): add manifest schema enforcement & upgrade
Agent Execution Checklist: <input></input> Implement schema-enforce command <input></input> Add canonical comparison <input></input> Implement upgrade logic with backup <input></input> Add tests (canonical/non-canonical/upgrade) <input></input> Run full PHPUnit <input></input> Commit (T15O feat(snapshot): add manifest schema enforcement & upgrade)
<hr></hr>
Suggested Additional Future Tickets (Optional Stubs)
 ===================================================Ticket ID: T15P Title: Differential Export Prototype Objective: Spec for exporting only delta between two snapshots (file-level differences) to reduce artifact size.
Scope (In): Spec doc (diff algorithm outline, hash-based manifest delta).
Scope (Out): Implementation.
Acceptance: Spec added.
Time: M.
 ===================================================Ticket ID: T15Q Title: Integrity Cache Warmup Objective: Cache hashed file digests post-create to speed future validation/diff operations.
Scope (In): Optional integrity_cache.json per snapshot; update at create/export.
Scope (Out): Cross-snapshot central DB.
Acceptance: Cache file written & used; tests.
Time: M.
 ===================================================Ticket ID: T15R Title: Remote Push (Spec) Objective: Spec for authenticated upload of artifact to remote (S3-compatible).
Scope (In): Auth handling, object key layout, conflict strategy.
Scope (Out): Implementation.
Acceptance: Spec documented.
Time: M.




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
