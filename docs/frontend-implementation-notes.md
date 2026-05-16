# Maestro Frontend Implementation Notes

## Purpose

This note documents the current Maestro browser frontend as it exists today, with emphasis on:

- current structure and responsibilities
- helper-backed integration points
- temporary decisions that should be revisited later
- likely next work areas as Maestro moves beyond first-pass operator testing

Use this as the working frontend map before making more UI changes.

## Current Entry Points

- App host view: [resources/views/welcome.blade.php](C:/wamp64/www/pbb/maestro/resources/views/welcome.blade.php)
- Frontend runtime: [public/js/maestro.app.js](C:/wamp64/www/pbb/maestro/public/js/maestro.app.js)
- Shell/page CSS: [public/css/maestro.shell.css](C:/wamp64/www/pbb/maestro/public/css/maestro.shell.css)

## Bootstrapping Model

The root page injects a bootstrap payload into `window.__PBB_BOOTSTRAP__`.

There is now also a matching baseline-style bootstrap endpoint at [routes/api.php](C:/wamp64/www/pbb/maestro/routes/api.php):

- `GET /api/bootstrap`

Current bootstrap responsibilities:

- app metadata
- authenticated account state
- CSRF token
- session lifetime in seconds
- API route URLs

The frontend runtime treats this payload as the initial source of truth and then mutates in-memory state after login, logout, re-auth, and protected data reloads.

## Current Frontend Architecture

The frontend is currently a single vanilla-JS runtime in [public/js/maestro.app.js](C:/wamp64/www/pbb/maestro/public/js/maestro.app.js).

High-level runtime responsibilities:

- helper component loading through `uiLoader`
- shell rendering
- page routing via `window.location.hash`
- authenticated session handling
- re-auth flow
- CSRF token refresh
- protected data fetching
- temporary polling
- per-page rendering for:
  - `Dashboard`
  - `Workers`
  - `Worker Events`
  - `Applications`
  - `Queues`

Current state buckets:

- `state.account`
- `state.authenticated`
- `state.csrfToken`
- `state.session`
- `state.polling`
- `state.management`
- `state.data`
- `state.filters`

This is intentionally pragmatic for V1, but it is already large enough that future frontend work should treat it as a candidate for modularization rather than continuing to grow it indefinitely.

## Helper Integration

Helpers are vendored locally and loaded from local paths.

Current helper-backed components in use:

- `ui.navbar`
- `ui.grid`
- `ui.form.modal`
- `ui.form.modal.login`
- `ui.form.modal.reauth`
- `ui.form.modal.account`
- `ui.form.modal.change.password`
- `ui.dialog.alert`
- `ui.toast`
- `ui.empty.state`

Load pattern:

- CSS from `/vendor/helpers.pbb.ph/css/ui/...`
- JS from `/vendor/helpers.pbb.ph/js/ui/ui.loader.js`
- registry lookups through `uiLoader.get(...)`

Important implementation note:

When using helper grids, any `renderCell(...)` callback that must render rich content must return a real `HTMLElement`, not an HTML string. Strings are rendered as plain text by the helper grid.

## Grid Presentation Model

Maestro grid pages are now intentionally aligned to the HQ/Relay presentation pattern:

- helper-owned dark table body
- no pagination
- virtualized row rendering
- internal grid-body scrolling instead of page-level paging
- minimal app-local styling layered on top of helper defaults
- column resizing enabled for data pages

Current grid defaults in [public/js/maestro.app.js](C:/wamp64/www/pbb/maestro/public/js/maestro.app.js):

- `chrome: false`
- `enablePagination: false`
- `enableColumnResize: true`
- `enableVirtualization: true`
- `virtualRowHeight: 40`
- `virtualThreshold: 60`
- `wrapCellContent: false`

This is the intended interim grid standard for:

- `Workers`
- `Worker Events`
- `Applications`
- `Queues`

## Layout Model

The shell follows the shared browser-app structure:

1. top header
2. sidebar
3. routed page shell

The top header is now helper-owned through `createNavbar(...)` and carries:

- app brand
- page navigation items
- right-side actions
- compact authenticated user menu

The authenticated user menu now follows the shared spec:

- `Account`
- `Logout`

`Change Password` is exposed as a secondary action from the helper-owned account modal through the preset `extraActions` path, not as a top-level shell button.

Per-page structure is:

1. page head/badges
2. page toolbar
3. page content row

Current layout rules worth preserving:

- avoid full-page browser scroll for normal operator usage
- use `min-height: 0` on containers that host internal scrolling
- keep sidebar and content scrolling independent
- page layout owns the rows
- widget/grid owns only its own scrollable body where possible

The `Applications` page now uses a split management layout with:

- left grid panel
- right application-management panel

This area is still under active tuning and should be treated as not fully settled.

## Session And Re-Auth Model

Current session behavior is app-owned, not helper-owned.

Helper role:

- render login modal
- render re-auth modal

App role:

- detect expiry
- open re-auth modal
- refresh CSRF token
- retry/resume protected data flow after successful re-auth

Current expiry triggers:

- `401` / `419` on authenticated requests
- local app-side session timer derived from bootstrapped session lifetime

Current CSRF behavior:

- root page bootstraps an initial token
- login/logout/current-user responses return refreshed CSRF state
- account/password update responses also return refreshed CSRF state
- frontend updates `meta[name="csrf-token"]`
- re-auth/login now refresh CSRF first through `GET /api/csrf-token`

Current keepalive behavior:

- the frontend tracks user activity separately from server touches
- `GET /api/session/ping` is used as the lightweight authenticated keepalive endpoint
- keepalive only triggers near expiry when the page is visible and the user has recent activity
- successful keepalive refreshes the local session deadline and may refresh CSRF state

## Data Update Model

Current update model is temporary polling, not streaming.

Current behavior:

- protected data loads after login
- explicit `Refresh Data` forces reload
- temporary polling runs every `15` seconds
- polling pauses when:
  - user is logged out
  - re-auth modal is active
  - tab is hidden
- when the tab becomes visible again, Maestro performs a silent refresh
- polling is separate from the keepalive layer and should not be treated as the long-term session-preservation strategy

### Temporary

This polling model is intentionally temporary.

Target direction later:

- replace polling with push/streaming
- likely SSE or websocket-based updates for:
  - workers
  - worker events
  - dashboard summaries
  - application/queue counts

When streaming is introduced, remove or greatly reduce the polling path rather than layering both forever.

## Applications Management

The `Applications` page now does more than monitoring.

Current management capabilities:

- create application
- issue telemetry token
- show token metadata
- reveal newly issued plain-text token once

Current backend dependencies:

- `POST /api/v1/applications`
- `POST /api/v1/applications/{application:app_code}/tokens`
- `GET /api/v1/applications`

### Temporary Or Incomplete Areas

- no revoke-token UI yet
- no edit-application UI yet
- no deactivate/reactivate-application UI yet
- no richer onboarding flow or token copy/audit UX yet
- token reveal is functional but still basic

## Known Temporary / Transitional Decisions

These are intentional but should be revisited:

1. Single-file frontend runtime
- `public/js/maestro.app.js` is doing too much.
- Good enough for V1 bootstrapping.
- Should later be split into modules such as:
  - shell
  - auth/session
  - polling/streaming
  - applications management
  - workers/events pages
  - shared formatters/renderers

2. Polling instead of streaming
- acceptable for current testing
- not the preferred long-term update model

3. CSS tuned iteratively per page
- some page-specific layout fixes have been added pragmatically
- future work should consolidate around a cleaner reusable page-shell pattern

4. Applications management layout
- functional now
- still a likely refinement area as more management features are added

5. Vendored helper lifecycle
- helpers are local and working
- future refreshes should come from the official helper repository only
- avoid ad hoc file copying

## Recommended Next Frontend Work

Short-term:

- finish live worker-monitoring validation with Relay telemetry
- continue cleaning layout issues page by page
- add token revoke/deactivate management
- improve application onboarding UX

Next structural pass:

- modularize `maestro.app.js`
- define reusable page-shell variants
- centralize helper-grid cell render helpers
- centralize API error handling and retry behavior

Longer-term:

- replace polling with push/streaming
- add richer operator drilldowns
- add real management workflows beyond token issuance

## Working Rule For Future Changes

When changing the frontend:

- preserve helper-backed patterns where possible
- keep the page shell responsible for layout
- keep data widgets responsible only for their own internal content rendering
- treat polling, single-file runtime structure, and some page-specific layout patches as temporary implementation scaffolding, not final architecture
