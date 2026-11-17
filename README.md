# Smart Schedule for WordPress

**Version:** 1.1.0  
**Requirements:** WordPress 6.6+; PHP 7.4+; JavaScript enabled.  
**Status:** 1.1.0 audit-remediation release. Unit, mocked-editor, and **live WordPress REST/cron integration tests pass** on the development install. Timed end-to-end publication (waiting for WP-Cron to fire) and assistive-technology checks remain staging acceptance items.

## What it does

Smart Schedule upgrades the usual WordPress **Publish** date popover, without a separate sidebar panel. The compact default view looks and works like the native picker: hour, minute, AM/PM or 24-hour time, day/month/year, monthly calendar and a single **Time shown in** timezone selector. It is **340 px wide** on desktop.

**Advanced options**, inside the same popup, expands to a **640 px** two-column layout with timezone search, favourites, choice to keep either the publishing instant or the displayed clock time when changing zones, and a preview of the website-local and UTC times. On narrow screens it adapts to the viewport.

The calendar includes a **Today** button and full arrow-key navigation (Left/Right/Up/Down, Home/End, PageUp/PageDown). The anchored popover is non-modal (Tab moves freely through the editor); Escape and outside-click dismiss it. If the conversion REST endpoint is unreachable when the picker opens, it initializes from the browser's own timezone data and shows an offline-preview notice — Apply always re-validates on the server before staging.

The selected date/time and timezone are validated on the WordPress server, including nonexistent daylight-saving times, repeated local times, half-hour and quarter-hour offsets and fixed-offset site timezones. The site-local date and corresponding UTC instant are staged in the editor; users still click WordPress **Schedule** or **Update** to save.

Smart Schedule does **not** replace WP-Cron or implement a separate post-publishing queue.

## Installation or upgrade

1. Back up the WordPress database and install on **staging** first.
2. Go to **Plugins → Add New Plugin → Upload Plugin** and upload `smart-schedule-1.0.0.zip`. Select **Replace current with uploaded** if prompted.
3. Open **Settings → Smart Schedule**. Confirm the website timezone, clock format and the **Enable on all compatible post types** option. These settings preserve preferences from earlier RC versions.
4. Open a draft, future/scheduled post or published article. Click its existing **Publish / Immediately / date** control in the Post sidebar.
5. Enter the date and time. **Time shown in** means “Which timezone should this date and clock time represent?” **Website time** means no conversion is necessary. Choosing another city previews the WordPress website-local equivalent.
6. Click **Apply** (or **Apply date** for an already published post), then the ordinary WordPress **Schedule** or **Update** button. Reload and verify the saved date and UTC instant.

### Timezone preferences

The plugin defaults to the WordPress site's configured timezone for editors who have not chosen a different one. If **Remember each editor's timezone and clock format** is enabled, that editor's last selection takes precedence. Editors upgrading from an RC can therefore still see their previously selected city, even if the default is Website time. They can select **Website time** from the timezone selector to return to the site timezone; no WordPress global timezone setting is changed.

Switching the clock-format button only changes how time is displayed, never the scheduled instant. Changing the *timezone* defaults to keeping the publishing instant; Advanced options can instead preserve the entered clock time.

### Draft, scheduled and published posts

- Drafts with an old stored publishing timestamp initialize to a future quarter-hour slot, rather than opening on a past date. Existing valid scheduled/future dates are preserved.
- Changing an existing scheduled post keeps using core WordPress scheduling.
- Already published/private posts load their saved dates and allow editing to another **past** publication date using **Apply date**, followed by **Update**. The plugin blocks a move directly to the future to avoid unexpectedly unpublishing content. This rule is enforced **server-side** in the REST handoff as well as in the UI; a REST request may only move a live post to a future date when it explicitly demotes the post to draft/pending in the same request.
- Existing WordPress scheduled posts are unaffected by plugin deactivation.

### Custom post types

All compatible post types, including custom News, Reviews and Articles types, are enabled by default. WordPress Gutenberg post types must support REST editing and appropriate editing capabilities. Administrators can disable automatic post-type coverage and use the allowlist in **Settings → Smart Schedule**.

### Classic Editor

The **Publish → Edit** timestamp link opens the same compact picker. The plugin sets the built-in WordPress date and time controls and triggers the native timestamp Save action. Because Classic Editor cannot reliably distinguish the two occurrences of a repeated **website-local** DST hour, that case is blocked for safe editing in Classic Editor. Use Gutenberg for an exact DST-overlap scheduling instant.

## Known compatibility boundaries

The Gutenberg publish-date dropdown does not have a stable public replacement hook. Smart Schedule captures clicks on the current `.editor-post-schedule__dialog-toggle`; if a WordPress or Gutenberg update changes that markup, the native picker remains available as fallback and the plugin logs a console warning plus a one-time editor notice so the regression is visible. Other pre-publish date controls can still be native. Test after major WordPress/editor changes and alongside other sidebar-modifying plugins.

The REST handoff passes an exact one-save UTC timestamp to preserve DST overlaps. The selected UTC time is validated against the entered site-local date, with permission checks. The server's PHP timezone is diagnostic only; WordPress's configured website timezone controls storage.

A scheduled article's actual publication time still depends on functioning WP-Cron or a correctly configured external cron runner. If WordPress's page-load cron is disabled, confirm that an external cron invokes it. See **Settings → Smart Schedule → Scheduling diagnostics**.

## Files

- `assets/scheduler.js`, `assets/scheduler.css`: scheduling UI and validation preview
- `assets/editor.js`: Gutenberg native Publish row integration and exact UTC staging
- `assets/classic.js`: Classic Editor timestamp integration
- `includes/class-smart-schedule-time.php`: strict timezone and DST conversion engine
- `includes/class-smart-schedule.php`: settings, REST endpoints, permissions and diagnostics
- `languages/smart-schedule.pot`: translation template (regenerate with `php tests/make-pot.php`)
- `tests/test-integration.php`: live WordPress REST/cron integration test (CLI, self-cleaning)
- `TESTING.md`: post-install testing instructions and automated commands
- `BUILD-REPORT.md`: executed tests and remaining live verification
- `CHANGELOG.md`: version history

The test suite uses PHP and Python Playwright with Chromium (bundled; override with the `SMART_SCHEDULE_CHROMIUM` environment variable). Mocked-editor tests are complemented by the live integration test, which exercises the real REST endpoints, UTC handoff, and cron scheduling against the installed site. Timed publication and assistive-technology verification still belong on staging.

All user-facing strings are translatable under the `smart-schedule` text domain. Drop compiled `.mo`/`.json` translations into `languages/`.
