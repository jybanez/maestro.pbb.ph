# PBB Maestro Post-Install Checklist

1. Point the web server document root at `public/`.
2. Confirm `APP_URL`, HTTPS, and secure-cookie settings match the deployed URL.
3. Run the generated scheduler service artifact or register an equivalent once-per-minute scheduler.
4. Open `/up` and confirm HTTP 200.
5. Open `/api/bootstrap` and confirm JSON is returned.
6. Log in with the configured admin account.
7. Create application records and telemetry tokens for producer apps such as Relay and Realtime.
8. Configure producer apps with the exact Maestro app code and telemetry token.
9. Confirm producer heartbeat rows appear in the Workers page.
