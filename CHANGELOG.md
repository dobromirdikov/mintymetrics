# Changelog

## [Unreleased]

## [1.2.0] - 2026-10-29

A reliability, security and accuracy release. Upgrading is a drop-in file replacement:
the database migrates itself (schema v1–v5 → v6) on the first request and keeps all data.

### Upgrade notes
- New installs ask for a one-time setup token, written to `.mm_setup_token.php` next to `analytics.php`
- Days (charts, summaries, retention, the daily salt) now follow one timezone, set in Settings → Timezone. Until you choose one, the server's PHP timezone is used, as before
- The database location is now pinned in `.mm_db_marker.php`. Existing installs are pinned automatically to the database they already use
- For PHP-site tracking, use `include_once` (the help panel shows the updated snippet)
- On nginx/Caddy, block the new files too (see README → Database Protection)

### Added
- Timezone setting, and an "allow subdomains" option for the domain allow-list
- Daily totals CSV export from the chart card (`?export&type=daily`, matching the chart)
- Dark mode that follows the operating system
- IP2Location IPv6 database support; geo uploads accept a ZIP or a BIN file
- Bot detection for more crawlers, and detection of in-app browsers (Instagram, Facebook, Google app, Android/iOS WebViews)

### Fixed
- Data loss: cleanup could delete raw hits that were never summarized (servers outside UTC, or the database size limit)
- Wrong totals: bounce rate and average time are weighted correctly across summarized days; top pages are ranked by views; summarized days are included in every breakdown; UTM campaigns are no longer double counted
- Time on page is attributed to the exact pageview, counts only visible time, and survives the daily salt rotation
- Single-page apps: `pushState`, `replaceState` and back/forward navigation are tracked; back/forward-cache restores are not counted as new referrals; prerendered pages are counted only when shown
- "database is locked" errors under concurrent traffic
- Rate limiting no longer drops legitimate repeat pageviews, and caps floods per IP
- Sensitive query parameters (tokens, emails, passwords) and click IDs are stripped from stored URLs
- IPv6 visitors are grouped per /64, and IPv4-mapped addresses are treated as IPv4
- Dashboard: date presets use the dashboard's timezone, stale responses can no longer overwrite newer ones, panels show errors instead of spinning forever, device bars and map colors render under the strict CSP, and the custom date range is validated
- Accessibility: keyboard-operable site selector, focus-trapped dialogs, visible focus, better text contrast, labelled charts and buttons

### Security
- Login limits per client and globally, reserved before the password check; sessions are bound to the current password, rotated on sign-in, and stored in a protected directory
- One-time setup token; setup runs exactly once
- `.htaccess` rules now also protect SQLite WAL/SHM/journal files, the marker and the setup token; the health check reports real web-root exposure
- CSV exports are protected against formula injection
- Security headers (frame, referrer, nosniff, no-store) and trusted-proxy-only HTTPS detection

## [1.1.1] - 2026-07-29

### Fixed
- Local development URLs now retain non-default ports such as MAMP's `localhost:8888`
- Allowed domains, tracked sites, and CORS origins now share consistent hostname normalization
- Existing allow-list entries that include a port continue working without manual changes

## [1.1.0] - 2026-04-26

### Added
- Custom event tracking — new `?event` ingestion endpoint, `events` table, and dashboard "Top Events" card with name → value drilldown
- `window.mm('track', name, value?, props?)` JavaScript API with queued-stub pattern so events can be recorded before the tracker script loads
- `?api&action=events` and `?api&action=event_values` JSON endpoints
- CSV export for events (`?export&type=events`)
- Schema migration v2 → v3 (creates `events` table; existing data untouched)
- Separate rate-limit bucket (`evt:` prefix) so a burst of events does not throttle pageview tracking
- `last_active_at` column on hits table — beacon refreshes keep visitors "active" between pageviews
- Configurable live visitor window (1-30 minutes) via Settings UI
- Poll failure handling — dashboard shows "—" after 3 consecutive API failures
- Visibility-based poll backoff — stops polling when the dashboard tab is hidden
- Prerender guard — prevents phantom hits from Chrome's speculative prerendering
- Schema migration system (v1 → v2) with transaction safety

### Changed
- "Live Visitors" relabeled to "Active Now" for accuracy
- Live query uses `OR` condition (`created_at >= :since OR last_active_at >= :since`) for index efficiency
- `check_rate_limit()` accepts an optional bucket-prefix argument; default behavior unchanged

### Fixed
- Race condition where rapid tab-switching could stack multiple polling intervals

## [1.0.0] - 2026-02-17

### Added
- Single-file PHP analytics with SQLite storage
- Cookie-free tracking with daily-rotating visitor hashes
- Drop-in mode (PHP include) and Hub mode (JS snippet)
- Dashboard with SVG line charts, date range picker, and live visitor count
- Top pages, referrers, UTM campaigns, devices, countries, screen resolutions, languages
- Bot filtering (JS verification + User-Agent pattern matching)
- DNT and Global Privacy Control respect
- Password-protected dashboard with bcrypt hashing and rate-limited login
- CSRF protection on all state-changing actions
- Nonce-based Content Security Policy
- Data retention with automatic summarization and cleanup
- CSV export for all data types
- IP2Location LITE geolocation support (optional, file upload)
- SVG world map with country heatmap coloring (lazy-loaded)
- .htaccess auto-protection for SQLite database file
- Database stored outside web root when possible
- IPv6 support for rate limiting (/64 prefix normalization)
- CLI password reset (`php analytics.php --reset-password`)
- Health status panel with system diagnostics
- GDPR compliance information page
- Mobile-responsive dashboard design
- Mint/green color scheme with 8-color chart palette
- Build system (`build.php`) compiling src/ into single distributable file
- GitHub Actions workflow for automated release builds
