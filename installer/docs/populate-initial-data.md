# PBB Maestro Initial Data Population

`tools/populate-initial-data.php` is an optional Kit Setup-compatible population tool for registering known telemetry-producing apps in Maestro after the core installer has run.

It supports:

- `--config <path>`
- `--report <path>`
- `--dry-run`
- `--mode initial|repair|refresh|demo`
- idempotent application upserts by `app_code`
- optional telemetry token creation by label

Expected config lives under `maestro.populate`. When `applications` is omitted, the tool loads packaged defaults from `resources/data/maestro/applications.json` for Relay and Realtime. Telemetry token secrets may be supplied at runtime as `plain_text_token` or `token_hash`; reports never print raw token values and only publish `token_supplied`.

Kit may inject generated token values without replacing the packaged application profile source:

```json
{
  "maestro": {
    "populate": {
      "telemetry_tokens": {
        "relay": [{ "label": "Primary", "plain_text_token": "<kit-generated>" }],
        "realtime": [{ "label": "Primary", "plain_text_token": "<kit-generated>" }]
      }
    }
  }
}
```

Example:

```powershell
php tools/populate-initial-data.php --config installer/docs/maestro-populate.sample.json --report storage/app/installer/maestro-populate-report.json --dry-run
```
