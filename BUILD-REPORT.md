# Smart Schedule 1.2.0: QA report

**Build date:** 2026-11-17  
**Origin:** 1.1.0 audit-remediation release plus the website-timezone default change below.

## 1.2.0 change

The "Time shown in" selector always opens in the website timezone; other timezones appear only by explicit selection and reset on reopen. Remembered timezone preferences and the `default_zone` setting no longer affect the initial display (setting removed, upgrade routine cleans stored options). Re-verified: 21/21 timezone assertions, intent/post-type/preference simulations (including the new site-default and legacy-`default_zone` cases), both Chromium suites (site-zone default, conversion after selection, reset-on-reopen), and **16/16 live WordPress integration assertions**.

## 1.1.0 audit findings implemented

1. **P1 — server-side published→future guard** in `apply_utc_intent` (was client-only; verified bypassable before the fix). Explicit draft/pending demotion in the same request remains allowed.
2. **P2 — `from_utc` year bounds** (1970–2100), matching `candidates()`; year-0000/9999 intents rejected.
3. **P2 — Classic Editor live post status** read from the form; `edit_date` set before the native save click.
4. **P2 — REST-outage fallback** for the picker's initial time (local `Intl` conversion + offline notice; Apply re-validates server-side).
5. **P2 — save-verification flow test coverage** (previously untested: mock store now simulates saving→saved transitions).
6. **P3 — lead-time floor** respects configured `lead_minutes`; **Today** button + calendar arrow-key navigation; inline popover made non-modal; single-instance dialog guard; debounced timezone search; nonce middleware no longer stacks; CSS consolidated; REST registration gated on enabled/allowlist; versioned upgrade routine; full i18n (PHP + JS + POT); `Tested up to` / `Update URI` headers; Gutenberg toggle-missing warning; settings diagnostics cached; favourites error message corrected; uninstall cleans new options.

## Verified in this environment (Windows, Laragon)

- PHP syntax checks on all plugin files (PHP 8.5 CLI).
- JavaScript syntax checks on all three plugin scripts.
- **21/21** PHP timezone assertions, including new year-bound rejections.
- PHP REST UTC-intent simulation: DST folds, date-mismatch guard, published/private backdating, draft past rejection, **published-future rejection for null/future/publish/private statuses, and explicit draft demotion allowed**.
- PHP post-type and editor-preference simulations.
- Chromium mocked Gutenberg: all 1.0.0 assertions plus **calendar Today/arrow keys, save verification (match + mismatch), native-edit intent clearing, lead-time floor, REST fallback with server-validated Apply**.
- Chromium mocked Classic Editor: timestamp replacement, published date edit, **live post-status detection (stale page-load draft vs form publish)**.
- **Live WordPress integration test: 16/16 assertions passed** against the actual install (PHP 8.3 with mysqli), including real REST scheduling, exact `post_date_gmt`, `publish_future_post` cron event creation, 409 mismatch, published-future guard, backdating, year-9999 rejection, and **both DST fold occurrences** saved with exact UTC. Temporary posts created and deleted automatically.

## Not verified here

- Timed end-to-end publication (waiting for WP-Cron to fire at the scheduled moment).
- Real Gutenberg/Classic admin DOM beyond the mocked harness (staging checklist in TESTING.md).
- Interactions with all installed editorial plugins or live custom post type configurations.
- Accessibility verification with assistive technologies.
- Translation accuracy (template generated; no translations bundled).

## Integration considerations

WordPress has no public API for swapping the Publish date dropdown. The Gutenberg control is intercepted through its current DOM class with a native fallback **and a visible warning if the markup disappears**. Core WordPress continues to publish scheduled posts; external cron is required if internal WP-Cron is disabled.

`TESTING.md` provides the staged deployment acceptance checklist and all automated commands, including the live integration test.