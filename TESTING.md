# Smart Schedule 1.1.0: Release verification

**Required before publishing on production:** Run these checks on a staging copy of your WordPress installation. The headless browser tests simulate the WordPress editor; the live integration test (`tests/test-integration.php`) exercises the real REST and cron-scheduling path on the installed site, but **timed end-to-end publication still needs the staging checklist below**.

## Staging acceptance checklist

- [ ] Upgrade RC5 to `1.0.0`; confirm WordPress reports **Smart Schedule 1.0.0** as active and existing settings/favourites remain.
- [ ] Confirm **Settings → General → Timezone** matches the intended website timezone, preferably a named IANA region such as America/Toronto.
- [ ] Open a new draft in Gutenberg: click the existing **Publish / Immediately** control. The compact Smart Schedule picker opens instead of the native picker and is approximately 340 px wide.
- [ ] Confirm new editors default to the website timezone; confirm returning editors retain their intentionally selected timezone and 12/24-hour clock preferences.
- [ ] For an old draft with a past date, the picker should start with an eligible future time instead of a past-time warning.
- [ ] Change between **12-hour** and **24-hour** display; verify the exact scheduled UTC instant does not change.
- [ ] Open **Advanced options** inside the popup: at desktop width it expands sideways to approximately 640 px, showing favourites, search, timezone switching policies and converted times. Test a narrow screen too.
- [ ] Select **2026-11-20, 9:30 a.m. America/Los_Angeles** on a Toronto website. Preview should show **12:30 p.m. Toronto** and **17:30 UTC**.
- [ ] Select **Keep the publishing moment**, change the timezone to Toronto, verify the UTC publishing instant stays the same. Then test **Keep the entered clock time** and verify UTC changes as expected.
- [ ] Apply a valid future time, click WordPress **Schedule**, reload and verify the post remains **Scheduled** with the correct local date and UTC date. Inspect `post_date`, `post_date_gmt` and the `publish_future_post` cron event where possible.
- [ ] Reschedule a future post, save, reload and confirm its new UTC timestamp persists. Check its cron event also changed.
- [ ] Create a test post scheduled 3–5 minutes into the future, then confirm WP-Cron actually publishes it at the expected moment. Verify the public URL and log publication time.
- [ ] Edit a **Published** post: Smart Schedule should open with its saved date; update to another past date, click **Apply date** and **Update**, then reload and confirm it persisted. A future date should be rejected without changing status.
- [ ] Repeat on a Private post, and on News, Articles and Reviews custom post types with suitable REST settings.
- [ ] Verify the Classic Editor Publish timestamp Edit link, native timestamp staging, Update, reload and resulting publish date.
- [ ] Test the spring DST gap (Toronto: **2027-03-14 at 2:30 a.m.**, nonexistent) and fall DST overlap (**2027-11-07 at 1:30 a.m.**, choose first or second occurrence). Verify each permitted occurrence saves the correct UTC instant in Gutenberg.
- [ ] Test invalid dates, missing times, keyboard Tab/Escape, outside click, narrow-screen scroll, and screen reader labels where available.
- [ ] Calendar: click **Today**, then use arrow keys/Home/End/PageUp/PageDown to move between days and months.
- [ ] Simulate a REST outage when opening the picker (e.g. temporarily block `/wp-json/smart-schedule/v1/format`): it should initialize locally with an offline-preview notice, and Apply should still validate on the server.
- [ ] Confirm a direct REST update of a published post with a future `smart_schedule_utc_intent` is rejected with `smart_schedule_published_future` (the integration test covers this automatically).
- [ ] Disable the plugin: the native picker returns, and saved scheduled posts still publish normally.
- [ ] Test alongside your editorial plugins that alter the Gutenberg sidebar or publication fields; watch for JavaScript console or REST errors.

## Automated regression commands

Run in the extracted plugin root. Python tests require Playwright (`pip install playwright && playwright install chromium`); they use the bundled Chromium unless `SMART_SCHEDULE_CHROMIUM` points at another executable. Screenshots go to a temp directory printed at the end of the run.

```bash
php -l smart-schedule.php
php -l includes/class-smart-schedule.php
php -l includes/class-smart-schedule-time.php
php -l uninstall.php
node --check assets/scheduler.js
node --check assets/editor.js
node --check assets/classic.js
php tests/test-time.php
php tests/test-rest-intent.php
php tests/test-post-types.php
php tests/test-preferences.php
python tests/test-browser.py
python tests/test-classic-browser.py
```

### Live WordPress integration test

```bash
php tests/test-integration.php
```

- CLI only. Loads `wp-load.php` from the surrounding WordPress install and runs **real REST requests** as an administrator: conversion, permission rejection, exact-UTC scheduling, `publish_future_post` cron creation, date-mismatch 409, the published-future guard, backdating, year bounds, and both DST fold occurrences (when the site timezone has an upcoming fold).
- Creates temporary posts titled `[Smart Schedule Test] …` and deletes them in a `finally` block, even on failure. It does not wait for or trigger actual publication.
- Requires a PHP binary with the `mysqli` extension. On Laragon, the system `php` may lack it — use the PHP build shipped with Laragon, e.g. `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests/test-integration.php`.
- Skips with a message (exit 0) when WordPress or the plugin is not present/active.

### Translation template

```bash
php tests/make-pot.php
```

Regenerates `languages/smart-schedule.pot` from the PHP and JS sources.

## Release acceptance

Unit, mocked-editor, and live REST/cron integration tests pass in the development environment. Before enabling on a live editorial workflow, record an actual WordPress save/reload, the UTC result, and a test post **publishing at its scheduled time** via WP-Cron on staging.
