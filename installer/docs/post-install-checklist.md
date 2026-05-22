# PBB Maestro Post-Install Checklist

1. Point the web server document root at `public/`.
2. Confirm `APP_URL`, HTTPS, and secure-cookie settings match the deployed URL.
3. Run the generated scheduler service artifact or register an equivalent once-per-minute scheduler.
4. Open `/up` and confirm HTTP 200.
5. Open `/api/bootstrap` and confirm JSON is returned.
6. Log in with the configured admin account.
7. Run Maestro Data Prep Prepare Data, or confirm Kit has run it, so Relay and Realtime application profiles exist from `resources/data/maestro/applications.json`.
8. Confirm Relay and Realtime each have an active `Primary` telemetry token hash; raw token values should only exist in Kit/runtime config and producer app settings.
9. Confirm producer apps are configured with the exact Maestro URL, app code, and telemetry token:
   - Relay: `https://maestro.pbb.ph`, `app_code=relay`
   - Realtime: `https://maestro.pbb.ph`, `app_code=realtime`
10. Restart producer services after settings are applied where required:
    - Relay requires `pbb-relay-worker` restart after `.env` changes.
    - Realtime can read DB-backed settings at runtime, but restart `pbb-realtime-websocket` and `pbb-realtime-media-dispatcher` for the fastest clean heartbeat.
11. Run Maestro Data Prep Verify. In final post-apply verification, Kit may set `maestro.data_prep.verify.require_fresh_heartbeat=true` and `freshness_threshold_seconds=60`.
12. If heartbeat status is `missing`, check producer logs first:
    - Relay `404` heartbeat sends usually mean a stale local `RELAY_MAESTRO_BASE_URL`, such as `http://localhost/pbb/maestro/public`.
    - Realtime `cURL error 60` means the telemetry HTTP client does not trust Maestro HTTPS yet and needs the Kit CA-bundle/trust handoff or agreed TLS setting.
13. Confirm fresh producer heartbeat rows appear in the Workers page.
