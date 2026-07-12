# glom 🥭

The simplest calorie tracker on the planet. One PHP file, one SQLite database, three numbers per day (weight, kcal, protein). Everything else in the world of calorie tracking is overkill.

![glom day view](https://img.shields.io/badge/php-8.1%2B-3E9B4F) ![license](https://img.shields.io/badge/license-MIT-F2A93B)

## What it does

- Tracks daily **kcal**, **protein**, and **weight**. Nothing else, ever.
- **Favourite foods** (kcal and protein per 100 g) and **favourite meals** (named combos of foods and/or raw numbers, like "glob").
- Repeat logging is the whole point. Favourite meals log in one tap, favourite foods in two (the grams field prefills your last amount).
- Editable daily targets with motivational progress bars. Going over protein stays green, because that is a win.
- Browse and edit any past day, plus a 30-day trend view (weight, kcal, protein) built on a vendored Chart.js.
- Installable PWA. The shell opens offline, logging needs network.
- Entries store snapshots, so editing a favourite food later never rewrites history.
- A token-guarded `?api=ingest` endpoint accepts `{date, metric, value}` rows for automated weight and steps pushes (iOS Shortcuts, Health Auto Export, or a scale-vendor cron).
- **Withings scale sync** (optional). Register a free app at developer.withings.com, paste the keys into the config block, tap Connect in settings. After that, the first time you open glom each day it pulls your morning weigh-in automatically. A free Withings account is enough.

## Deploy

1. Copy `index.php` to any PHP 8.1+ host with pdo_sqlite (enabled almost everywhere).
2. Make sure the web server can **write** to the directory (it creates `glom.sqlite` on first load).
3. Put your real credentials in a `glom-config.local.php` next to it (PIN, cookie secret, ingest token, Withings keys) using the same `define()` calls as the config block in `index.php`. The main file ships with placeholders only and never needs editing.
4. Deny direct HTTP access to `glom.sqlite*` (snippets for Apache and nginx are in the file header).
5. Open the URL, log in, set your targets, add your favourite foods.

Local development runs with `php -S localhost:8000`.

## Non-goals

Micronutrients, barcodes, social features, accounts, cloud sync, coaching, streaks, gamification, and everything else. Simple simple simple.

## License

MIT
