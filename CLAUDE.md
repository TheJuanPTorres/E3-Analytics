# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**E3 Analytics Dashboard** is a WordPress plugin (v1.3.0) that provides a custom KPI dashboard for the Tutor LMS platform. It tracks registrations, enrollments, completion rates, dropout progress, retention (DAU/MAU), and geographic analytics. No build system or package manager is used — everything runs directly in WordPress.

> **Last updated:** 2026-07-31. Reflects the 1.3.0 release: custom date ranges, UTC-correct user queries, and the removal of all transitional scaffolding.

## Architecture

### Plugin Bootstrap

Entry point: `e3-analytics-dashboard/e3-analytics-dashboard.php`
→ hooks `plugins_loaded` → `E3_Analytics\Plugin::instance()->init()`
→ `includes/Plugin.php` requires all class files
→ `includes/Admin/Page.php` registers admin menus and enqueues assets

### Four Admin Pages

| Slug | View | Service |
|------|------|---------|
| `e3-analytics-dashboard` | `admin/views/dashboard.php` | `MetricsService` |
| `e3-analytics-dropout-progress` | `admin/views/dropout-progress.php` | `DropoutProgressService` |
| `e3-analytics-country-analysis` | `admin/views/country-analysis.php` | `CountryAnalyticsService` |

All pages require `manage_options` capability.

### Data Flow

```
HTTP Request → Admin/Page.php (routes by GET slug)
    → Service (computes metrics)
    → Repository (queries via $wpdb)
    → TutorLms integration (completion checks)
    → View (PHP template)
    → Chart.js (reads window.E3A_CHART / E3A_DROPOUT_CHART / E3A_COUNTRY_CHART)
```

Chart data is PHP-encoded to JSON and injected as inline scripts via `wp_add_inline_script()`.

### Layer Responsibilities

- **`includes/Admin/Page.php`** — menu registration, asset loading, view dispatch, export handlers, settings save handler
- **`includes/Services/`** — KPI calculation logic (MetricsService, DropoutProgressService, CountryAnalyticsService, ExportService, CountryUsersExportService)
- **`includes/Repositories/`** — raw database queries (UsersRepository, EnrollmentsRepository)
- **`includes/Integrations/TutorLms.php`** — wraps Tutor LMS functions (completion status, progress %). Key method: `is_effectively_completed($course_id, $user_id)` — considers a course complete if Tutor marks it formally OR progress=100%.
- **`includes/Support/`** — utilities: DatePeriod (period resolution), Math (growth %), Xlsx (ZIP-based Excel generator), CountryHelper (trait: country normalization + ISO2→name), BucketHelper (trait: progress % → bucket key)

### Period System

All pages accept a `?period=` query param. Accepted values:

`7` | `30` | `90` | `365` | `this_month` | `last_month` | `this_quarter` | `this_year` | `last_year` | `all` | `YYYY-MM-DD..YYYY-MM-DD`

Resolved by `DatePeriod::resolve()` into full calendar days: start at `00:00:00`, end at `23:59:59`, in site-local time. Anything unrecognised falls back to `30` and explains why in the `notice` key.

**`Admin\Page::read_period()` is the only reader of `$_GET` for the period.** The custom-range form posts `e3a_from` / `e3a_to`; `read_period()` composes them into the canonical `YYYY-MM-DD..YYYY-MM-DD` scalar. `'custom'` is a valid `<option>` value and is NOT valid for `DatePeriod` — do not add it to the allowlist, that would make "custom with no dates" representable.

**Two timezones.** `current_start` / `current_end` (and `prev_*`) are site-local and compare against `wp_posts.post_date`. The four `_utc` keys are the same instants in UTC and compare against `wp_users.user_registered`, which WP core writes in UTC. They are not interchangeable: with offset −5 the end of a local day lands on the next UTC day. Six queries read the `_utc` keys; the rest read the local ones.

Growth comparisons use the previous equal-length window, with no overlap. `Math::growth_percent()` returns `null` when the previous window was empty — consumers must render that as "no baseline", not as `0`.

Retention uses fixed windows (7/14/30/60/90/180/365 days) anchored to *now*, so its rates are not comparable across periods. DAU/MAU ignores the period entirely. Both are labelled as such in the UI.

### Database Tables Used

- `wp_users` — registrations
- `wp_usermeta` — `country_lms`, `tutor_login_*` (activity tracking)
- `wp_posts` — enrollment records (`post_type = 'tutor_enrolled'`, status `publish`/`completed`)

All queries use `$wpdb->prepare()` with `%s`/`%d` placeholders. User input sanitized with `sanitize_text_field()` + `wp_unslash()`. Exports verified with `wp_verify_nonce()`. Large `IN()` clauses are chunked at 2000 IDs to avoid MySQL limits.

### Completion Detection

**Never use `tutor_utils()->is_completed_course()` directly in KPI logic.** Always call `TutorLms::is_effectively_completed($course_id, $user_id)` instead. Reason: Tutor LMS marks courses as incomplete when open-ended quiz answers are pending review, even if progress is 100%. `is_effectively_completed()` works around this by treating a course as complete if:
1. Tutor's own flag is true, OR
2. Progress is 100%.

The feedback-quiz filter that used to gate rule 2 was removed in 1.2.9.2-b1: measured against 3,071 course-user pairs over 4 years of production data it rejected **zero** enrollments (30d 123=123, 365d 628=628, all-time 1178=1178). Quizzes are course content, so reaching 100% progress already implied having taken them. The `e3a_feedback_quiz_ids` option row was deliberately left in the database so the code can be reverted; reverting makes it take effect again immediately.

### Export Actions

POST to `admin-post.php` with these actions:
- `e3a_export_excel` → `Page::export_excel()`
- `e3a_export_dropout_users` → `Page::export_dropout_users()`
- `e3a_export_country_users` → `Page::export_country_users()`

Exports produce XLSX (via `Support/Xlsx.php` using `ZipArchive`) or CSV (native `fputcsv`). Controlled by filters `e3a_export_excel`, `e3a_export_country_users`.

The **country users export** (`CountryUsersExportService`) produces two sheets:
- **Resumen** — period metadata, user count, course count
- **Usuarios** — 26 fixed profile columns + one dynamic column per course (value = user's progress %, empty if not enrolled). Meta is batch-loaded (1 query per 2000 users) to avoid N+1.

### Frontend

- **Vanilla JS + Chart.js** (loaded from CDN)
- CSS scoped entirely under `.e3-shell` to avoid WP admin conflicts
- CSS custom properties define the design system (colors, spacing, typography)
- Google Fonts: Inter (400/500/600/700)

### Caching

`CountryAnalyticsService` caches results as WordPress transients (15-minute TTL, key = `md5` of period params).

### WordPress Hooks / Filters

- `e3a_enrollment_post_type` — override the enrollment post type slug (default `tutor_enrolled`)
- `e3a_max_custom_range_days` — cap for custom ranges, in days (default `3650`)
- `e3a_label_date_format` — date format for human-readable labels (default `j M Y`). Deliberately NOT `get_option('date_format')`: this site has `m/d/Y`, which reads as ambiguous in Spanish and travels into export filenames.
- `e3a_export_excel` — enable/disable Excel export on main dashboard
- `e3a_export_country_users` — enable/disable country users export

### WP Options

None. The plugin reads only; `uninstall.php` cleans up options left by earlier versions (`e3a_feedback_quiz_ids`, `e3a_date_mode`, `e3a_diag_enabled`) and the `e3a_country_*` transients.

## Key Files

```
e3-analytics-dashboard/
├── e3-analytics-dashboard.php              Plugin entry point & constants
├── includes/
│   ├── Plugin.php                          Singleton bootstrap, requires all files
│   ├── Admin/Page.php                      Menu, assets, routing, export + settings handlers
│   ├── Services/MetricsService.php         Main dashboard KPI logic
│   ├── Services/DropoutProgressService.php Dropout + progress bucket report
│   ├── Services/CountryAnalyticsService.php Country KPI report (cached via transients)
│   ├── Services/ExportService.php          Excel/CSV export logic for main dashboard
│   ├── Services/CountryUsersExportService.php  País users export (batch meta, dynamic course columns)
│   ├── Repositories/EnrollmentsRepository.php
│   ├── Repositories/UsersRepository.php
│   ├── Integrations/TutorLms.php           Tutor LMS wrapper — use is_effectively_completed()
│   └── Support/
│       ├── DatePeriod.php                  Period resolution, calendar days, UTC keys
│       ├── Math.php                        Growth % helper
│       ├── Xlsx.php                        ZIP-based XLSX generator
│       ├── CountryHelper.php               Trait: normalize_country_label(), iso2_to_name()
│       └── BucketHelper.php                Trait: bucket_key() — progress % → bucket string
├── admin/
│   ├── assets/admin.js                     Chart.js setup for dashboard
│   ├── assets/dropout.js
│   ├── assets/country.js
│   └── views/
│       ├── partials/period-selector.php    Shared period <select> + custom range
│       ├── dashboard.php
│       ├── dropout-progress.php
│       ├── country-analysis.php
```

## Development Notes

- No composer, npm, or build step. Drop the plugin folder into `wp-content/plugins/` and activate.
- PHP namespace: `E3_Analytics`. All classes use `E3_Analytics\` prefix.
- Constants: `E3A_VERSION`, `E3A_PATH`, `E3A_URL` defined in the main plugin file.
- Inline comments are primarily in Spanish.
- No automated tests exist; test manually against a WordPress + Tutor LMS installation.

---


## Stack
- PHP dashboard application
- MySQL/MariaDB backend

## Token Efficiency Rules

### RTK (installed globally)
- RTK hook auto-rewrites Bash commands. All git, test, build, ls, grep commands go through rtk automatically.
- For file reading, use shell commands (cat/head/tail) instead of Read tool when possible — RTK compresses them.
- For search, use rg/grep via shell instead of Grep tool — RTK groups matches by file.

### Caveman (installed globally via plugin)
- Activated by default from session start (`/caveman` to toggle).
- Be concise: lead with answer, use fragments, drop filler.
- Never compromise code, commands, or error messages.
- Use `/caveman-stats` to track savings.

### Efficient Workflow
- Prefer shell commands (`git status`, `cat file.php`, `rg pattern`) over built-in tools when RTK can compress.
- Run `/compact` when conversation gets long instead of starting fresh.
- Keep this CLAUDE.md lean — every line costs tokens.

## Known defects — NOT fixed

Documented deliberately. See CHANGELOG 1.3.0 for detail.

1. **`ExportService.php:259`** — invalid `GROUP BY`. Returns silently wrong data on this site (no `ONLY_FULL_GROUP_BY` in `sql_mode`); errors out on a default MySQL 5.7+.
2. **Unfiltered usermeta in exports** — `user_full_row()`, `maybe_add_user_meta_sheet()` and `flatten_user_meta_json()` dump all of `get_user_meta()` into a downloadable file.
3. **Retention semantics** — windows anchored to *now*, capped at 365 days; rates not comparable across periods.
4. **N+1 on completion detection** — one Tutor call per course-user pair; `period=all` is ~7,000 queries.
5. **Repositories without `LIMIT`** — `rows_between()` and `first_enrollment_map_until()` load everything into memory; `Xlsx::sheet_xml()` builds the whole XML as a string.
