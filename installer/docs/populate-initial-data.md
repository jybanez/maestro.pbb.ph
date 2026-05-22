# PBB Maestro Data Prep And Initial Population

`tools/populate-initial-data.php` is an optional Kit Setup-compatible population tool for registering known telemetry-producing apps in Maestro after the core installer has run.

It supports:

- `--config <path>`
- `--report <path>`
- `--dry-run`
- `--mode initial|repair|refresh|demo`
- idempotent application upserts by `app_code`
- optional telemetry token creation by label

## Prepare Data

Expected config lives under `maestro.populate`. When `applications` is omitted, the tool loads packaged defaults from `resources/data/maestro/applications.json` for Relay and Realtime:

- `relay`, display name `PBB Relay`, environment `production`, base URL `https://relay.pbb.ph`
- `realtime`, display name `PBB Realtime`, environment `production`, base URL `https://realtime.pbb.ph`

Static source data does not store raw telemetry tokens. Token secrets may be supplied at runtime as `plain_text_token` or `token_hash`; reports never print raw token values and only publish `token_supplied`.

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

## Data Prep Metadata

`release.json` declares the current standalone Data Prep contract:

- `prepare_data`: `tools/populate-initial-data.php`
- `apply_settings`: disabled for Maestro
- `verify`: `tools/data-prep/verify.php`

Maestro owns preparing and verifying the server-side application profiles and token hashes. Relay and Realtime own applying their own Maestro endpoint, app code, and telemetry token settings.

## Verify

`tools/data-prep/verify.php` is a read-only verification tool. It accepts the common Kit Data Prep CLI contract:

```powershell
php tools/data-prep/verify.php --mode initial --config <config.json> --report <report.json> --dry-run
```

It verifies:

- expected Maestro application profile exists
- expected `environment` matches the source/config
- active Primary telemetry token hash exists
- latest worker heartbeat status per expected app

Heartbeat status values:

- `fresh`: latest heartbeat is within `freshness_threshold_seconds` and the worker is not stale/stopped
- `stale`: latest heartbeat exists but is too old or resolves to stale/stopped
- `missing`: profile/token checks passed but no worker heartbeat exists yet
- `rejected`: profile or token checks failed, so heartbeat acceptance cannot be trusted

Heartbeat report fields are secret-safe:

```json
{
  "heartbeat": {
    "app_code": "relay",
    "status": "fresh",
    "last_seen_at": "2026-05-22T09:57:59.000000Z",
    "age_seconds": 1,
    "freshness_threshold_seconds": 60,
    "worker_id": "worker-id",
    "worker_status": "idle"
  }
}
```

By default, non-fresh heartbeat states are warnings so the verifier remains useful before producer services emit telemetry. Kit can make heartbeat freshness blocking after Apply Settings and service restart with:

```json
{
  "maestro": {
    "data_prep": {
      "verify": {
        "require_fresh_heartbeat": true,
        "freshness_threshold_seconds": 60
      }
    }
  }
}
```

## Recent Data Prep Troubleshooting Notes

If final Data Prep Verify reports `heartbeat.status=missing`, check the producer app logs before changing Maestro:

- Relay should send to `https://maestro.pbb.ph` with `app_code=relay`. A stale base URL such as `http://localhost/pbb/maestro/public` causes Apache `404` responses before requests reach Maestro.
- Realtime should trust the Maestro HTTPS certificate. Repeated `cURL error 60: SSL certificate problem: unable to get local issuer certificate` means the Realtime telemetry client needs the Kit CA-bundle/trust handoff or an agreed TLS setting before heartbeat requests can reach Maestro.
- A direct unauthenticated POST to `https://maestro.pbb.ph/api/v1/telemetry/workers/heartbeat` should return `401`; that confirms the public Maestro ingestion route is present.
