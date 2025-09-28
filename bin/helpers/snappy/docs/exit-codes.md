# Snappy CLI Exit Codes

Snappy uses a structured exception hierarchy to provide predictable non‑zero exit codes for scripting.

Base exception: `Snappy\Support\Exception\SnappyException` (and subclasses). The CLI entry point (`tsnap_cli.php`) catches these and maps them to exit codes via `ExitCodes`.

## Mapped Exception Exit Codes
| Code | Exception Class | Meaning |
|------|------------------|---------|
| 2 | ValidationException | User input / argument / semantic validation failure (invalid name, unsupported type, checksum mismatch, etc.) |
| 3 | SnapshotNotFoundException | Requested snapshot (or its remote objects) not found / ambiguous token resolution |
| 4 | RemoteException | Remote / network / storage / hosting errors (S3 operations, tunnel/endpoint issues, remote decode errors) |
| 5 | ProcessFailedException | External process or system operation failed (e.g. database backup not produced) |
| 6 | ConfigException | Configuration read/write/parse errors |
| 99 | <unmapped> | Any other uncaught throwable (internal / unknown error) |

On success commands return `0`.

## Non‑Exception Return Codes (Legacy / Direct Returns)
Some commands still return small integers directly (e.g. usage errors or specific operational statuses) without throwing exceptions. These remain for now and are not part of the exception mapping table:

- 1: Generic usage / argument error (e.g. missing required positional arg) or unknown command.
- Other small values (2–7) may be used ad‑hoc inside certain commands (e.g. `pull` share token states, archive extraction issues) pending future unification.

Future tasks (e.g. JSON output / richer error schema) will migrate these ad‑hoc codes to the structured exception set or an extended table.

## Script Integration Guidance
- Treat code `0` as success.
- If exit code is one of (2,3,4,5,6,99) you can rely on the stderr prefix format: `ERROR(<code>): <message>`.
- For portability, reserve any new structured exception codes above `10` or document them here.

## Example
```bash
# Attempt to push unknown snapshot
$ tsnap push deadbeef origin
ERROR(3): Unknown snapshot deadbeef
$ echo $?   # => 3
```

## Notes
- The mapping logic lives in `Snappy\Support\Exception\ExitCodes::codeFor()`.
- Unmapped subclass additions must be registered in `ExitCodes` to avoid falling back to `99`.

