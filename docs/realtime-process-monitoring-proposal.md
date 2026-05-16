# PBB Maestro Realtime Process Monitoring Proposal

## Summary

`PBB Maestro` should monitor Realtime's websocket/runtime process as a normal worker-like runtime instance, without taking over process control.

The immediate target is `php artisan realtime:serve`, which is the process responsible for draining queued server events and serving live websocket sessions.

This proposal defines a small telemetry contract Realtime can emit to Maestro so operators can see whether the websocket daemon is running, fresh, stale, or stopped.

## Problem

Hotline Beta has already shown the real operational gap:

- Realtime can accept backend-published events
- the events can queue successfully
- but if `realtime:serve` is not running, those events do not fan out to connected browser clients

Maestro should be able to surface that state clearly.

## Scope

This proposal is intentionally narrow.

In scope:

- monitor the Realtime websocket daemon as one worker identity
- track heartbeat freshness
- track clean start and stop lifecycle events
- show the process as running, busy, idle, stale, or stopped
- correlate the daemon with host, pid, and role metadata

Out of scope:

- starting the process
- stopping the process
- restarting the process
- supervising system services
- replacing the Realtime gateway runtime

## Recommended Telemetry Contract

Realtime should emit the same worker telemetry shape Maestro already accepts for other background processes.

### Heartbeat endpoint

`POST /api/v1/telemetry/workers/heartbeat`

### Required payload fields

- `app_code`: `realtime`
- `worker_id`: stable process identity for this daemon instance
- `host_name`
- `process_id`
- `status`
- `started_at`
- `last_heartbeat_at`
- `processed_count`
- `failed_count`

### Recommended optional payload fields

- `current_job_id`: `null` for the websocket daemon
- `queue_name`: optional, if the process should be associated with a queue label
- `meta`: daemon-specific details

### Recommended `meta` fields

- `command`: `realtime:serve`
- `role`: `websocket-gateway`
- `listen_host`
- `listen_port`
- `build_version` or `release_id` if available

## Event Contract

Realtime should also emit lifecycle events so Maestro can preserve a clean history.

Suggested event types:

- `worker.started`
- `worker.heartbeat`
- `worker.stopped`

Suggested event payload shape:

```json
{
  "app_code": "realtime",
  "worker_id": "realtime:serve:host:pid:started_at:suffix",
  "event_type": "worker.started",
  "occurred_at": "2026-04-06T23:30:00+08:00",
  "host_name": "realtime-host",
  "process_id": 12345,
  "queue_name": null,
  "job_id": null,
  "meta": {
    "command": "realtime:serve",
    "role": "websocket-gateway"
  }
}
```

## Suggested Worker Identity

Each running websocket daemon should generate a stable runtime identity on boot.

Recommended format:

`realtime:serve:<host>:<pid>:<started_at>:<random_suffix>`

This is stable for the life of the process and unique enough for Maestro to distinguish restarts.

## Status Handling In Maestro

Maestro already derives worker state from the latest known heartbeat and lifecycle data.

Recommended interpretation for Realtime:

- `starting`: process started recently
- `idle`: fresh heartbeat and no current job
- `busy`: fresh heartbeat with active work
- `stale`: heartbeat freshness expired
- `stopped`: clean shutdown was reported

If Realtime dies without a clean stop event, Maestro should mark the daemon stale after heartbeat expiry.

## Operational Expectations

For useful visibility, Realtime should:

- start emitting a `worker.started` event when `realtime:serve` boots
- emit a heartbeat about every 15 seconds
- emit `worker.stopped` on clean shutdown
- keep daemon metadata consistent across restarts

## Maestro Display Goal

Maestro should be able to show:

- whether Realtime's websocket daemon is up
- whether the daemon is fresh or stale
- the host and process id behind the daemon
- the last heartbeat timestamp
- recent lifecycle events for the daemon

## Acceptance Criteria

This proposal is satisfied when:

1. Realtime emits telemetry for `realtime:serve` using the Maestro worker contract.
2. Maestro lists the Realtime daemon in the workers grid.
3. Maestro marks the daemon stale when heartbeats stop.
4. Maestro shows clean start/stop and heartbeat history for the daemon.
5. The monitoring path works without Maestro controlling the process itself.

## Recommendation

Adopt the telemetry contract above for `realtime:serve` first.
If that proves useful, Realtime can later expose additional background processes using the same pattern.
