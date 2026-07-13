---
name: verify
description: How to run and verify glom locally (single-file PHP + SQLite PWA)
---

# Verifying glom changes

## Launch

```bash
php -S localhost:8123 index.php   # run from repo root, in background
```

Uses the repo's `glom.sqlite` (gitignored) and `glom-config.local.php` (real PIN, OpenRouter key). Schema self-creates and self-migrates on first API hit. Fresh-install testing = delete `glom.sqlite*` (but the repo copy may hold local state; prefer a scratch copy).

## API smoke

```bash
U='http://localhost:8123/index.php'
COOKIE=$(curl -si -X POST "$U?api=login" -d '{"pin":"<PIN from glom-config.local.php>"}' \
  | grep -i set-cookie | sed 's/.*glom_auth=\([^;]*\).*/\1/')
curl -s -b "glom_auth=$COOKIE" "$U?api=selftest"       # schema + meal math
curl -s -b "glom_auth=$COOKIE" "$U?api=day&date=YYYY-MM-DD"
```

## UI

Playwright MCP against localhost:8123 at 390x844 (mobile) and 1280x800. Login screen may be skipped if a valid auth cookie persists in the browser profile. Screenshots must be read back with the Read tool (saved under `.playwright-mcp/`, gitignored).

Scan flows: clicking a 📷 button opens a file chooser; handle with browser_file_upload (files must live inside the repo or `.playwright-mcp/`). Synthetic label/food images can be drawn on a canvas in-page, exported as dataURL, and base64-decoded to PNG. Real OpenRouter calls work locally (key in glom-config.local.php) and cost fractions of a cent on the default gemini-flash model.

## Gotchas

- Clean up any rows you seed (foods, entries, weights) — the local db doubles as Simo's dev state.
- No test framework by design; don't add one.
- Withings flows: use a local mock on port 8001 (see docs/STATUS.md), never commit the repointed API const.
