# Manifest v2 Schema Change Protocol

This project enforces a frozen contract for `manifest_v2` via a canonical JSON golden hash.

Why: The snapshot export artifact (`<uid>.tar.gz`) must be reproducible. Tooling (future IntegrityService / export + import) will rely on a stable manifest shape for:
- Deterministic archive ordering
- Integrity verification (sha256 of canonical manifest)
- Backwards/forwards compatibility decisions

## Canonical Form
We canonicalize by:
- Recursively sorting object keys lexicographically (byte order)
- Preserving array order
- Emitting JSON without pretty print, without escaped slashes/unicode

Implementation: `Snappy\Util\canonical_json::encode()`.

## Golden Hash Test
`tests/schema/ManifestV2FreezeTest.php` computes:
1. Read `schema/manifest_v2.example.json`
2. Decode, canonical encode
3. SHA-256 hash
4. Compare with hard-coded GOLDEN_HASH

A failure means the schema or example changed (keys added/removed/renamed, value shape changes) OR incidental reordering in the example file (which should not matter because canonicalization sorts keys). Whitespace changes alone will NOT change the hash.

Current golden hash: `b4633d20c4dafd4f61bf16dab400923dfbb93554fe623e1a3379b0b7f51ad3c7`

## Making an Intentional Schema Change
1. Justify the change: list rationale & migration considerations here under a new dated heading.
2. Update `schema/manifest_v2.json` (authoritative schema) and `schema/manifest_v2.example.json` to include the new/changed fields.
3. Recompute canonical hash:
   ```bash
   php compute_manifest_hash.php | grep '^HASH='
   ```
4. Update `GOLDEN_HASH` constant in `tests/schema/ManifestV2FreezeTest.php`.
5. Append a changelog section below.
6. Commit with ticket reference, e.g. `feat(schema): add <field> to manifest v2 (TXXY)`.

Do NOT batch unrelated schema tweaks—each requires explicit reasoning.

## Rollback
If a change proves problematic, revert the schema & example, restore previous GOLDEN_HASH from git history.

## Future Evolution (v3 trigger)
If breaking changes accumulate (removals, incompatible type shifts), introduce `schema_version: 3` instead of mutating v2. Maintain both examples during transition and update consumers accordingly.

---

## Changelog
(Empty – no intentional changes since freeze.)

