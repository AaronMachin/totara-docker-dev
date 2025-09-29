# Refactor Notes (T14A Trim Baseline)

Date: 2025-09-29

## Removed Source Files
| Path | Approx LOC |
|------|------------|
| src/cli/commands/doctor_run.php | 108 |
| src/cli/commands/verify_run.php | 27 |
| src/cli/commands/prune_run.php | ~90 (est) |
| src/cli/commands/share_create.php | 220 |
| src/cli/commands/share_fetch.php | 105 |
| src/cli/commands/share_list.php | 47 |
| src/cli/commands/snapshot_tag.php | 52 |
| src/snapshot/remote_index_manager.php | 85 |
| src/snapshot/remote_snapshot_cache.php | 160 |
| src/share/share_registry.php | 72 |
| src/hosting/manager.php | 162 |
| src/hosting/ngrok_provider.php | 97 |
| src/hosting/options.php | 124 |
| src/hosting/provider.php | 30 |
| src/hosting/remote_codec.php | 36 |
| (Subtotal source) | ~1,415 |

## Removed Test Files
| Path | Approx LOC |
|------|------------|
| tests/Cli/DoctorCommandTest.php | ~120 |
| tests/Cli/ShareArchiveTest.php | ~70 |
| tests/Cli/ShareFetchTest.php | ~60 |
| tests/Cli/SharePresignReconstructTest.php | ~90 |
| tests/Cli/ShareTokenTest.php | ~90 |
| tests/Cli/SnapshotCreateTagsTest.php | ~55 |
| tests/Cli/SnapshotHashStoreTest.php | ~70 |
| tests/Cli/SnapshotPruneAgeTest.php | ~60 |
| tests/Cli/SnapshotPruneCountTest.php | ~55 |
| tests/Cli/SnapshotTagTest.php | ~65 |
| tests/Cli/SnapshotListFilterTest.php | ~120 |
| tests/Snapshot/RemoteIndexManagerTest.php | ~140 |
| tests/Snapshot/RemoteMinioTest.php | ~150 |
| tests/integration/ShareTokenFlowIntegrationTest.php | ~90 |
| tests/integration/SnapshotFlowTest.php | 37 |
| tests/Snapshot/SnapshotManagerProcessTest.php | 159 |
| (Subtotal tests) | ~1,467 |

## Retained Notable Tests
- tests/Snapshot/ManifestValidatorScriptTest.php (kept to validate manifest-v2 schema; provenance added back to manifest builder)

> NOTE: Exact LOC for some historical files estimated where prior commit retrieval not available post-removal.

## Added / Modified Key Files
- tsnap_cli.php (re-registered minimal commands; removed prune/verify/doctor/share/tag)
- src/cli/context.php (removed cache/remote index wiring)
- src/cli/command_router.php (group ordering updated: Snapshot, Remote, Maintenance, Config, Other)
- src/cli/base_command.php (new Remote group; removed Share group usage in baseline)
- src/cli/commands/snapshot_create.php (trimmed flags: removed tags, hash-store, keep-failed)
- src/cli/commands/snapshot_list.php (local only; removed filters, remote options)
- src/cli/commands/remote_add|list|remove.php (baseline remote config CRUD; redaction for key/secret)
- src/cli/commands/gc_objects.php (retained legacy; tests reduced to baseline expectations)
- src/snapshot/snapshot_manager.php (removed push/pull/verify/tag/hash-store/object map logic; simplified list; added provenance & compression_algo for v2 manifest)
- src/cli/output_formatter.php (always output error lines; removed suppression heuristic)
- README.md (rewritten for T14A baseline)
- docs/artifact_spec.md (new)
- docs/remotes.md (new)
- docs/deferred.md (new)
- docs/refactor_notes.md (this file)

## Net Effect
- Source LOC reduction: ~1.4K lines removed plus simplifications inside surviving files.
- Test LOC reduction: ~1.47K lines removed; suite shrunk to 43 baseline tests.

## Rationale Recap
Focus on deterministic local snapshot creation + manifest generation to unblock upcoming T14B (canonical hashing) and T14C (IntegrityService) without noise from legacy features.

## Follow Ups
- T14B: manifest canonicalization + golden hash test.
- T14C: central hashing service.
- T14D/T14E: export/import pipeline.
- Reintroduce remote listing & share only after artifact path stabilized.
