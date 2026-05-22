# PBB Maestro

PBB Maestro is the worker monitoring service in the PBB ecosystem. It is a Laravel 12 browser app plus telemetry ingestion API that gives operators visibility into background workers across PBB applications.

Current V1 scope is monitoring-first:

- register monitored applications
- issue telemetry tokens per application
- ingest worker heartbeats
- ingest worker lifecycle and job events
- compute worker freshness and status
- show applications, workers, worker events, queues, and summary views in an operator UI

Current V1 does not manage process lifecycles. It does not start, stop, scale, or supervise workers. It records and presents worker state.

## Role In The PBB Ecosystem

Maestro is the central worker visibility surface for PBB projects.

Current ecosystem alignment from this repository:

- `PBB Maestro`: owns worker monitoring, heartbeat/event ingestion, stale detection, and operator visibility
- `PBB Relay`: telemetry producer using Maestro app code `relay`
- `PBB Realtime`: telemetry producer using Maestro app code `realtime`
- `helpers.pbb.ph`: shared UI runtime vendored locally for offline-capable operation
- shared PBB browser-session conventions: login, logout, current-user, CSRF refresh, and re-auth flow

Cross-project chat-log context in `C:\wamp64\www\pbb\chat_log.md` confirms that Relay is the first live telemetry integration and that Maestro expects either bearer auth or the `X-Telemetry-Token` header for telemetry ingestion.

## Stack

- PHP `8.2` (`C:/wamp64/bin/php/php8.2.29/php.exe` in the local WAMP setup)
- Laravel `12`
- MySQL for normal app runtime
- Vite for frontend asset build
- vanilla JavaScript frontend in `public/js/maestro.app.js`
- vendored `helpers.pbb.ph` runtime in `public/vendor/helpers.pbb.ph`

## What The App Contains

### Operator Browser App

The root route `/` serves a single-page operator shell backed by:

- bootstrap payload from [`app/Support/MaestroBootstrap.php`](C:\wamp64\www\pbb\maestro\app\Support\MaestroBootstrap.php)
- host view [`resources/views/welcome.blade.php`](C:\wamp64\www\pbb\maestro\resources\views\welcome.blade.php)
- frontend runtime [`public/js/maestro.app.js`](C:\wamp64\www\pbb\maestro\public\js\maestro.app.js)
- shell styling [`public/css/maestro.shell.css`](C:\wamp64\www\pbb\maestro\public\css\maestro.shell.css)

Current UI pages:

- Dashboard
- Workers
- Worker Events
- Applications
- Queues

The frontend is helper-first and uses the vendored shared components for navbar, grids, form modals, login, re-auth, account, change-password, alerts, toasts, and empty states.

### Operator API

Browser-authenticated endpoints live under `routes/api.php`:

- `GET /api/bootstrap`
- `GET /api/csrf-token`
- `POST /api/login`
- `GET /api/user`
- `POST /api/user`
- `POST /api/user/password`
- `GET /api/session/ping`
- `POST /api/logout`
- `GET /api/v1/applications`
- `POST /api/v1/applications`
- `POST /api/v1/applications/{app_code}/tokens`
- `GET /api/v1/workers`
- `GET /api/v1/worker-events`

These routes use Laravel session auth. Protected API routes return `401` when unauthenticated.

### Telemetry API

Machine-to-machine ingestion endpoints:

- `POST /api/v1/telemetry/workers/heartbeat`
- `POST /api/v1/telemetry/worker-events`

Telemetry auth is enforced by [`app/Http/Middleware/EnsureTelemetryToken.php`](C:\wamp64\www\pbb\maestro\app\Http\Middleware\EnsureTelemetryToken.php).

Accepted auth forms:

- `Authorization: Bearer <plain-text-token>`
- `X-Telemetry-Token: <plain-text-token>`

The header name is configurable through `MAESTRO_TELEMETRY_TOKEN_HEADER` and defaults to `X-Telemetry-Token`.

Telemetry requests must include `app_code`, and the token must belong to that same application or Maestro returns `403`.

## Data Model

Core storage tables:

- `maestro_applications`: monitored apps such as Relay or HQ
- `maestro_telemetry_tokens`: hashed ingestion tokens per application
- `maestro_workers`: current worker snapshot/state per `worker_id`
- `maestro_worker_events`: idempotent worker/job event log keyed by `event_id`

Schema files:

- [`database/migrations/2026_03_17_040500_create_maestro_applications_table.php`](C:\wamp64\www\pbb\maestro\database\migrations\2026_03_17_040500_create_maestro_applications_table.php)
- [`database/migrations/2026_03_17_040510_create_maestro_telemetry_tokens_table.php`](C:\wamp64\www\pbb\maestro\database\migrations\2026_03_17_040510_create_maestro_telemetry_tokens_table.php)
- [`database/migrations/2026_03_17_040520_create_maestro_workers_table.php`](C:\wamp64\www\pbb\maestro\database\migrations\2026_03_17_040520_create_maestro_workers_table.php)
- [`database/migrations/2026_03_17_040530_create_maestro_worker_events_table.php`](C:\wamp64\www\pbb\maestro\database\migrations\2026_03_17_040530_create_maestro_worker_events_table.php)

## Worker Status Rules

Worker status is derived by [`app/Services/Maestro/WorkerStatusResolver.php`](C:\wamp64\www\pbb\maestro\app\Services\Maestro\WorkerStatusResolver.php), not blindly trusted from the sender.

Current computed states:

- `starting`: worker started recently and is not yet clearly active
- `idle`: worker is fresh and has no current job
- `busy`: worker is fresh and has a current job id
- `stale`: heartbeat is older than the configured stale threshold
- `stopped`: worker has a `stopped_at` timestamp

Current thresholds from [`config/maestro.php`](C:\wamp64\www\pbb\maestro\config\maestro.php):

- `MAESTRO_STARTING_THRESHOLD_SECONDS=15`
- `MAESTRO_STALE_THRESHOLD_SECONDS=45`
- `MAESTRO_CLOCK_SKEW_THRESHOLD_SECONDS=60`

Stale reconciliation is also persisted every minute by the scheduled console command in [`routes/console.php`](C:\wamp64\www\pbb\maestro\routes\console.php):

- `php artisan maestro:reconcile-stale-workers`

## Telemetry Contract

### Heartbeat Payload

Required fields for `POST /api/v1/telemetry/workers/heartbeat`:

```json
{
  "app_code": "relay",
  "worker_id": "relay-node-01:18244:2026-03-17T14:30:00Z:9f3a",
  "host_name": "relay-node-01",
  "started_at": "2026-03-17T14:30:00Z",
  "last_heartbeat_at": "2026-03-17T14:30:10Z",
  "processed_count": 128,
  "failed_count": 3
}
```

Optional fields:

- `queue_name`
- `process_id`
- `status`
- `current_job_type`
- `current_job_id`
- `memory_mb`
- `meta`

Behavior:

- upserts the worker record by `worker_id`
- recalculates status server-side
- clears `stopped_at`
- stores clock-skew metadata under `meta._maestro`
- returns `202 Accepted`

### Worker Event Payload

Required fields for `POST /api/v1/telemetry/worker-events`:

```json
{
  "event_id": "a6f447b0-79c1-4fe7-8a86-5d312d9b7f48",
  "app_code": "relay",
  "worker_id": "relay-node-01:18244:2026-03-17T14:30:00Z:9f3a",
  "event_type": "job.completed",
  "occurred_at": "2026-03-17T14:31:02Z"
}
```

Optional fields:

- `queue_name`
- `job_type`
- `job_id`
- `outcome`
- `notes`
- `payload`

Behavior:

- event ingestion is idempotent by `event_id`
- duplicates return `200` with `duplicate: true`
- new events return `202 Accepted`
- worker snapshots are updated as events arrive
- supported event patterns currently handled explicitly:
  - `worker.started`
  - `worker.heartbeat`
  - `worker.stopped`
  - `job.started`
  - `job.completed`
  - `job.failed`

## Local Setup

### 1. Install PHP and Node dependencies

```powershell
composer install
npm install
```

### 2. Create environment file

```powershell
Copy-Item .env.example .env
```

### 3. Generate the app key

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' artisan key:generate
```

### 4. Configure the database

The repository’s example environment is currently set up for local MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pbb_maestro
DB_USERNAME=root
DB_PASSWORD=
```

### 5. Run migrations and seed operator users

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' artisan migrate --seed
```

Default seeded users from [`database/seeders/DatabaseSeeder.php`](C:\wamp64\www\pbb\maestro\database\seeders\DatabaseSeeder.php):

- `user1@pbb.ph` / `password`
- `user2@pbb.ph` / `password`

### 6. Build frontend assets

```powershell
npm run build
```

### 7. Run the app locally

For a basic local session:

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' artisan serve
```

For the full Laravel dev loop defined in `composer.json`:

```powershell
composer run dev
```

That starts:

- Laravel dev server
- queue listener
- log tail
- Vite dev server

### 8. Run the scheduler

Stale-worker reconciliation is scheduled every minute. In local development, keep a scheduler running:

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' artisan schedule:work
```

## Health Endpoints

Current health surfaces:

- `GET /up`: branded health response, HTML or JSON depending on request headers
- `GET /internal-health`: Laravel health route configured in `bootstrap/app.php`

`/up` returns JSON like:

```json
{
  "status": "ok",
  "app": "PBB Maestro",
  "timestamp": "2026-04-24T00:00:00Z"
}
```

## Vendored UI Dependency

Maestro is intentionally offline-capable and does not rely on a CDN for required shared UI assets.

Current vendored dependency documentation is in [`VENDORED.md`](C:\wamp64\www\pbb\maestro\VENDORED.md).

Important current paths:

- `resources/vendor/helpers.pbb.ph`
- `public/vendor/helpers.pbb.ph`

The current app runtime uses the bundled Helper assets from `dist/helpers.ui.bundle.min.js` and `dist/helpers.ui.bundle.min.css`.

## Kit Setup Release Package

Build the Kit-consumable release ZIP from the repository root:

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' tools/build-release-package.php
```

The builder writes `storage/app/installer-build/pbb-maestro-m1-1.0.0.zip` plus `latest-manifest.json`. The ZIP deploys directly as the runnable Laravel app root, with `release.json`, `checksums.sha256`, `installer/install-run.php`, `installer/status.php`, and the Laravel runtime all at the archive root. It stamps package-only `build.*` metadata into the bundled `release.json` and excludes local secrets, caches, logs, test files, CI/build tooling, and the package builder itself from the distributable.

## Kit Data Prep

Maestro supports the standalone Kit Data Prep workflow declared in `release.json`.

Current Data Prep metadata:

- `prepare_data`: [`tools/populate-initial-data.php`](C:\wamp64\www\pbb\maestro\tools\populate-initial-data.php)
- `apply_settings`: disabled for Maestro
- `verify`: [`tools/data-prep/verify.php`](C:\wamp64\www\pbb\maestro\tools\data-prep\verify.php)

Prepare Data loads packaged defaults from [`resources/data/maestro/applications.json`](C:\wamp64\www\pbb\maestro\resources\data\maestro\applications.json) when `maestro.populate.applications` is omitted. The defaults currently create:

- `relay`: `PBB Relay`, environment `production`, base URL `https://relay.pbb.ph`
- `realtime`: `PBB Realtime`, environment `production`, base URL `https://realtime.pbb.ph`

Telemetry token values are runtime-injected by Kit under `maestro.populate.telemetry_tokens`; raw tokens are not stored in the static JSON source and are not printed in reports.

Verify is read-only and checks:

- expected application profile exists and is active
- expected environment matches
- active `Primary` telemetry token hash exists
- heartbeat freshness for each expected app

Heartbeat states reported by Verify:

- `fresh`
- `stale`
- `missing`
- `rejected`

By default, non-fresh heartbeat states are warnings. Kit may make them blocking after producer Apply Settings and service restart by setting:

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

Current cross-app operational notes from Kit retesting:

- Relay must be configured with `RELAY_MAESTRO_BASE_URL=https://maestro.pbb.ph` and `RELAY_MAESTRO_APP_CODE=relay`; stale local values can produce Apache `404` responses before requests reach Maestro.
- Relay requires `pbb-relay-worker` restart after its Maestro `.env` settings are changed.
- Realtime should use `MAESTRO_BASE_URL=https://maestro.pbb.ph` and `MAESTRO_TELEMETRY_APP_CODE=realtime`.
- Realtime heartbeat sends can fail before reaching Maestro if its telemetry HTTP client does not trust the Maestro TLS certificate; check for `cURL error 60` and apply the Kit CA-bundle/trust handoff or agreed TLS setting.
- A direct unauthenticated POST to `https://maestro.pbb.ph/api/v1/telemetry/workers/heartbeat` returning `401` confirms the public Maestro ingestion route exists.

## Tests

Feature coverage currently lives in:

- [`tests/Feature/OperatorApiTest.php`](C:\wamp64\www\pbb\maestro\tests\Feature\OperatorApiTest.php)
- [`tests/Feature/TelemetryIngestionTest.php`](C:\wamp64\www\pbb\maestro\tests\Feature\TelemetryIngestionTest.php)

Run the suite with either:

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' artisan test
```

or directly with PHPUnit:

```powershell
& 'C:/wamp64/bin/php/php8.2.29/php.exe' vendor/bin/phpunit
```

## Key Files

- [`routes/web.php`](C:\wamp64\www\pbb\maestro\routes\web.php): root shell and public `/up` health endpoint
- [`routes/api.php`](C:\wamp64\www\pbb\maestro\routes\api.php): operator and telemetry APIs
- [`routes/console.php`](C:\wamp64\www\pbb\maestro\routes\console.php): stale-worker reconcile command and schedule
- [`app/Http/Middleware/EnsureTelemetryToken.php`](C:\wamp64\www\pbb\maestro\app\Http\Middleware\EnsureTelemetryToken.php): telemetry auth boundary
- [`app/Http/Controllers/Api/V1/Telemetry/WorkerHeartbeatController.php`](C:\wamp64\www\pbb\maestro\app\Http\Controllers\Api\V1\Telemetry\WorkerHeartbeatController.php): heartbeat ingestion
- [`app/Http/Controllers/Api/V1/Telemetry/WorkerEventController.php`](C:\wamp64\www\pbb\maestro\app\Http\Controllers\Api\V1\Telemetry\WorkerEventController.php): event ingestion
- [`app/Services/Maestro/WorkerStatusResolver.php`](C:\wamp64\www\pbb\maestro\app\Services\Maestro\WorkerStatusResolver.php): worker status derivation
- [`public/js/maestro.app.js`](C:\wamp64\www\pbb\maestro\public\js\maestro.app.js): current frontend runtime
- [`docs/pbb-maestro-brief.md`](C:\wamp64\www\pbb\maestro\docs\pbb-maestro-brief.md): product positioning
- [`docs/pbb-maestro-v1-implementation-proposal.md`](C:\wamp64\www\pbb\maestro\docs\pbb-maestro-v1-implementation-proposal.md): V1 implementation intent
- [`docs/frontend-implementation-notes.md`](C:\wamp64\www\pbb\maestro\docs\frontend-implementation-notes.md): current frontend structure and constraints

## Current Boundaries

What Maestro currently does well:

- centralizes worker/application visibility
- enforces app-scoped telemetry tokens
- tracks last heartbeat and recent event history
- supports operator login, account update, password change, and session keepalive
- derives queue coverage from worker and event data

What is not implemented yet in this repository:

- worker/process control
- autoscaling
- realtime streaming updates in the browser
- websocket or SSE push for dashboard state
- multi-project orchestration beyond telemetry ingestion and visibility
