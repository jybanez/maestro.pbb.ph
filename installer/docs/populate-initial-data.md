# PBB Maestro Initial Data Population

`tools/populate-initial-data.php` is an optional Kit Setup-compatible population tool for registering known telemetry-producing apps in Maestro after the core installer has run.

It supports:

- `--config <path>`
- `--report <path>`
- `--dry-run`
- `--mode initial|repair|refresh|demo`
- idempotent application upserts by `app_code`
- optional telemetry token creation by label

Expected config lives under `maestro.populate`. Telemetry token secrets may be supplied as `plain_text_token` or `token_hash`; reports never print raw token values.

Example:

```powershell
php tools/populate-initial-data.php --config installer/docs/maestro-populate.sample.json --report storage/app/installer/maestro-populate-report.json --dry-run
```
