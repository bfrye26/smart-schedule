# Changelog

## 1.1.0 (2026-11-17)

Security and robustness release implementing a full code audit.

- **Server-side published-post safeguard:** `apply_utc_intent` now rejects moving a published/private post to a future date (error `smart_schedule_published_future`). Previously this rule was only enforced in the editor UI and could be bypassed by a direct REST request. An explicit `status=draft`/`pending` in the same request is still allowed as a deliberate unpublish-and-reschedule.
- **UTC range hardening:** `from_utc()` now enforces the same 1970–2100 year bounds as `candidates()`, rejecting year-0000 and year-9999 intents.
- **Classic Editor:** the picker reads the live post status from the form instead of the page-load snapshot (fixes stale status after an in-page Publish), and sets `edit_date` before triggering the native timestamp save.
- **REST outage fallback:** if the initial `/format` call fails, the picker initializes locally via `Intl` and shows an offline-preview notice; Apply still re-validates on the server.
- The initial draft slot now respects the configured `lead_minutes` (no hardcoded 2-minute floor).
- Accessibility: calendar **Today** button, arrow-key/Home/End/PageUp/PageDown navigation with roving tabindex; the inline popover no longer traps Tab focus (non-modal), while the full-screen dialog keeps its focus trap.
- The picker enforces a single open instance, preventing element-ID collisions.
- Timezone search is debounced; the apiFetch nonce middleware is only added when one is not already present.
- Consolidated `scheduler.css` (merged the RC4 base and RC5 override blocks into one rule set).
- REST field registration is gated on the plugin being enabled and the post-type allowlist.
- Added a versioned upgrade routine (`smart_schedule_version` option) and uninstall cleanup for it.
- Internationalization: all PHP and JS strings use the `smart-schedule` text domain; added `languages/smart-schedule.pot` and `tests/make-pot.php`; script translations registered.
- Added `Tested up to: 7.1` and an `Update URI` header (blocks wordpress.org same-slug updates).
- Editor integration now warns (console + one snackbar) if the Gutenberg publish-date toggle markup is missing, instead of failing silently.
- Diagnostics lookup on the settings page is cached for one minute.
- Tests: new regression coverage for the published-future guard, year bounds, save verification (match and mismatch), intent clearing on native date edits, REST fallback, lead-time floor, calendar keyboard navigation, and Classic live status. Browser tests use Playwright's bundled Chromium (override with `SMART_SCHEDULE_CHROMIUM`) and write screenshots to a temp directory.
- **New live WordPress integration test** (`tests/test-integration.php`): real REST requests against the installed site covering conversion, permissions, exact-UTC scheduling, cron event creation, date-mismatch rejection, the published-future guard, backdating, year bounds, and both DST fold occurrences. Creates and deletes its own temporary posts.

## 1.0.0 (2026-11-17)

- Promoted the approved RC5 compact WordPress scheduling popover and side-by-side advanced view without further layout changes.
- Fixed per-editor clock-format preference precedence: a stored 12-hour preference now overrides a site default set to 24-hour time, and vice versa.
- New schedules for drafts whose stored WordPress date is already in the past now start at a valid future quarter-hour slot, while scheduled posts and existing published dates retain their timestamps.
- Preserved built-in WordPress scheduling and the exact-UTC Gutenberg REST handoff, including DST overlaps, published-post backdating and custom post type support.
- Added tests covering preference inheritance and stale draft dates; reran PHP, JavaScript, Chromium mock editor, and archive integrity checks.
- Live site scheduling and WP-Cron remain deployment acceptance tests and were not executed in this build environment.

## 1.0.0-rc.5 (2026-11-17)

- Enabled all compatible custom post types by default, including editor-facing Articles, News and Reviews; administrators can opt into a restricted allowlist in settings. This also applies on upgrades from older RC defaults.
- Fixed Gutenberg and Classic Editor date-picker replacement for **published and private posts**, which RC4 incorrectly left on the native control.
- The selected date on an existing published post loads from its saved `date_gmt`, including WordPress REST dates that omit a trailing `Z`.
- Allows an editor to change the existing publication date to another past date. Future dates remain deliberately blocked for published/private posts to avoid inadvertently taking a live story offline.
- Preserves the exact selected UTC instant in the REST handoff for both scheduled posts and existing publication-date edits.
- Fixed a browser event race where field blur could disable the Apply button before its click registered.
- Reduced the compact picker width from 400 px to 340 px and the advanced two-column maximum from 760 px to 640 px. Tightened calendar and footer spacing to align with core WordPress sizing.
- Added automated published-post, fixed-offset, compact-width, and advanced-width regression assertions.

## 1.0.0-rc.4 (2026-11-17)

- Redesigned the Publish popover to closely follow WordPress's original compact scheduling picker: **TIME** first, discreet 12/24-hour control, **DATE** fields (day/month/year), and a compact calendar.
- Replaced the confusing **Scheduling timezone** presentation with **Enter time in**, clearly labelling the WordPress website timezone or fixed UTC offset.
- Replaced the bulky default two-card conversion section with a single concise **Website publishes** line, displayed only when the editor chooses a timezone other than the site timezone.
- Moved all full conversion details, timezone search, favourite zones, conversion policy and fixed-offset diagnostics into in-place **Advanced options**.
- Removed the persistent explanatory header, redundant large preview cards in the compact view, and redundant Reset/format labels.
- Added separate native-looking day/month/year inputs, with impossible calendar dates rejected and a selected day kept in sync with the calendar.
- Preserved Gutenberg/Classic Editor entry points, timezone conversion and exact UTC handoff from RC2.
- Added browser assertions for the compact/advanced presentation, site-UTC fixed offsets, date-field validation and responsive positioning.

## 1.0.0-rc.2 (2026-11-17)

- **Replaced the primary Gutenberg Publish date popup** by intercepting its existing rendered scheduling toggle. A normal click now opens Smart Schedule at that row, without adding a redundant document sidebar panel.
- Added a compact, Gutenberg-styled popover with calendar, direct date field, time, clock-format switch, timezone selector and website conversion.
- Added the **Advanced options** button **inside that popover**. It expands the same popover to show timezone search, favourites, UTC details and timezone-change policy, and collapses via **Fewer options**.
- Reworked Classic Editor integration: intercept its real `.edit-timestamp[href="#edit_timestamp"]` link; removed redundant Publish metabox Smart Schedule widget.
- Added outside-click, Escape, keyboard-focus restore and responsive popover positioning.
- Added Gutenberg and Classic Editor mocked Chromium integration tests and visual screenshots, without localhost navigation.
- Updated capability checks for custom post types with non-default edit capabilities.
- Avoided dispatching a new editor edit immediately after a successful schedule save (which could re-mark the article dirty).
- Kept native WordPress scheduling for published/private posts and as a compatibility fallback if the editor markup changes.

## 1.0.0-rc.1 (2026-11-17)

- Initial standalone Gutenberg scheduling panel and modal, Classic Editor supplemental control, timezone and DST conversion engine, per-editor preferences, UTC intent handoff, settings and diagnostics.
