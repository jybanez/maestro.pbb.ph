# Vendored Dependencies

## helpers.pbb.ph

Vendored from: `https://github.com/jybanez/helpers.pbb.ph`
Upstream commit: `b29730e58971a9511e44110ed1984988db0029ae`
Update date: `2026-04-16`

Vendored paths:
- `resources/vendor/helpers.pbb.ph`
- `public/vendor/helpers.pbb.ph`

Included in this project:
- `css/ui/*`
- `js/ui/*`
- `docs/*`
- `README.upstream.md`
- `CHANGELOG.upstream.md`

Notes:
- The relative `js/ui` and `css/ui` layout is preserved so `ui.loader.js` can resolve stylesheet URLs correctly.
- Maestro loads the vendored helper runtime from `/vendor/helpers.pbb.ph/js/ui/ui.loader.js`.
- Base helper CSS is loaded from `/vendor/helpers.pbb.ph/css/ui/ui.tokens.css` and `/vendor/helpers.pbb.ph/css/ui/ui.components.css`.
- App integrations should prefer `uiLoader` by registry key rather than direct per-component path imports.
