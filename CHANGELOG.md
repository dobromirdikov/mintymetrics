# Changelog

## [Unreleased]

### Added
- `last_active_at` column on hits table — beacon refreshes keep visitors "active" between pageviews
- Configurable live visitor window (1-30 minutes) via Settings UI
- Poll failure handling — dashboard shows "—" after 3 consecutive API failures
- Visibility-based poll backoff — stops polling when the dashboard tab is hidden
- Prerender guard — prevents phantom hits from Chrome's speculative prerendering
- Schema migration system (v1 → v2) with transaction safety

### Changed
- "Live Visitors" relabeled to "Active Now" for accuracy
- Live query uses `OR` condition (`created_at >= :since OR last_active_at >= :since`) for index efficiency

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
