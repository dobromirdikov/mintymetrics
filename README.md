<p align="center">
  <img src="assets/logo.png" alt="MintyMetrics" width="300">
</p>

<p align="center">
  <a href="https://github.com/dobromirdikov/mintymetrics/actions/workflows/test.yml"><img src="https://github.com/dobromirdikov/mintymetrics/actions/workflows/test.yml/badge.svg" alt="Tests"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.0+"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License: MIT"></a>
</p>

<p align="center"><strong>Single-file, cookie-free, privacy-first web analytics for PHP.</strong></p>

Drop one file. Know your audience. No cookies, no config, no complexity.

---

## Highlights

- **One file** — upload `analytics.php` to any PHP server, done
- **No cookies** — GDPR/CCPA-compliant by design, no consent banners needed
- **No dependencies** — no Composer, no Node.js, no external database
- **SQLite storage** — auto-created, zero configuration
- **Privacy-first** — IPs hashed with a daily-rotating salt, never stored raw
- **Self-hosted** — your data stays on your server

## Features

- Pageviews, unique visitors, bounce rate, average time-on-page
- Live visitor count
- Top pages, referrers, UTM campaign tracking
- Device type, browser, OS, screen resolution, language breakdown
- Country-level geolocation (optional, via free IP2Location LITE database)
- Interactive world map
- Date range picker (today, 7d, 30d, 90d, custom)
- CSV export
- Bot filtering (User-Agent patterns + JS verification)
- DNT / Global Privacy Control support
- Automatic data retention and daily summarization
- Rate limiting and brute-force login protection

## Quick Start

You can rename `analytics.php` to anything you like (e.g., `stats.php`, `s.php`). All internal routing is self-referencing.

### Drop-in Mode

For a single site — analytics and dashboard served from the same domain.

1. Upload `analytics.php` to your web root
2. Visit `https://yoursite.com/analytics.php` in your browser
3. Set an admin password and configure your domain
4. Add this to your pages:
   ```php
   <?php
   // At the very top of the file, before any output:
   include 'analytics.php';
   ?>
   ...
   <head>
       <?php \MintyMetrics\head(); ?>
   </head>
   ```

The `include` loads MintyMetrics. The `head()` call outputs the tracking JavaScript.

### Hub Mode

Track multiple sites from one central dashboard.

1. Host `analytics.php` on a central domain (e.g., `stats.yourdomain.com`)
2. Visit the dashboard, configure your allowed domains
3. Add to each site's `<head>`:
   ```html
   <script defer src="https://stats.yourdomain.com/analytics.php?js&site=myproject.com"></script>
   ```
4. All sites appear in one dashboard with a site switcher

## Requirements

- PHP 8.0+
- SQLite3 extension (enabled by default on most hosts)
- Write permission for the database file

## Configuration

Settings are managed through the dashboard UI (**Settings** page). Key options:

| Setting | Default | Description |
|---|---|---|
| Data retention | 90 days | How long raw hit data is kept before summarization |
| Max DB size | 100 MB | Warning threshold for database size |
| Respect DNT | On | Honor Do Not Track / Global Privacy Control headers |
| Geolocation | On | Enable country tracking (requires IP2Location LITE download) |
| Rate limit | 1 second | Minimum interval between hits from the same IP |
| Allowed domains | — | Domain whitelist for hub mode |

> **Rate limiting behind a reverse proxy:** Rate limiting uses `REMOTE_ADDR`. Behind a reverse proxy, all visitors share the proxy's IP. This is the safe default (`X-Forwarded-For` is spoofable). If your proxy sets a trusted header, configure rate limiting at the proxy level instead.

### Geolocation

Country tracking uses the free [IP2Location LITE](https://lite.ip2location.com/) database. It's not bundled to keep the file small. In Settings, you can either auto-download it with a free IP2Location token or manually upload the BIN file (~2 MB).

## Building from Source

The distributable `analytics.php` is compiled from modular source files in `/src`.

```bash
# Build with version from VERSION file
php build.php

# Build with a specific version
php build.php --version 1.2.0
```

The build script:
- Inlines all PHP modules, CSS, JS, and SVG assets into a single file
- Validates all markers are replaced
- Runs `php -l` syntax check
- Verifies key function definitions exist

## Running Tests

```bash
composer install
composer test
```

Run a specific test suite or filter:

```bash
composer test:unit            # Unit tests only
composer test:integration     # Integration tests only
composer test -- --filter=UserAgent   # Single test class
```

Tests require PHP 8.0+ and the SQLite3 extension. The test suite uses temporary SQLite files that are cleaned up automatically.

## Security

- **No cookies** — session-based dashboard auth only; visitors are never cookied
- **Password hashing** — bcrypt via `password_hash()`
- **CSRF protection** — all state-changing POST endpoints require a valid token
- **Rate limiting** — login lockout after 5 failed attempts (15 min); hit rate limiting per IP
- **CSP headers** — nonce-based Content Security Policy on all dashboard pages
- **Database protection** — randomized SQLite filename; `.htaccess` auto-generated to block direct access
- **Input validation** — all user input truncated and sanitized; prepared statements throughout
- **Privacy** — raw IPs never stored; visitor hashes rotate daily and cannot be correlated across days

## Database Protection

MintyMetrics creates the SQLite database with a randomized filename and attempts to store it **outside your web root** automatically. When that's not possible (e.g., shared hosting restrictions), it falls back to the same directory and relies on server-level rules.

The **Health** panel in the dashboard shows whether your database is outside the web root or protected by server rules.

**Apache / LiteSpeed** — `.htaccess` rules are auto-generated to block direct access to `.sqlite`, `.sqlite-wal`, `.sqlite-shm`, and `.mm_db_marker.php` files.

**Nginx** — add these rules to your server block:
```nginx
location ~* \.(sqlite|sqlite-wal|sqlite-shm)$ {
    deny all;
}
location ~* \.mm_db_marker\.php$ {
    deny all;
}
```

**Caddy** — add a respond matcher:
```
@blocked path_regexp \.(sqlite|sqlite-wal|sqlite-shm)$ *.mm_db_marker.php
respond @blocked 403
```

## Files Created

No files are written to disk until you complete the setup wizard. After setup, MintyMetrics creates:

| File | Location | Purpose |
|---|---|---|
| `.mm_db_marker.php` | Same directory as `analytics.php` | Stores the randomized DB filename suffix |
| `mintymetrics-{hex}.sqlite` | One directory above web root (falls back to same directory) | SQLite database |
| `mintymetrics-{hex}.sqlite-wal`, `-shm` | Next to the `.sqlite` file | SQLite WAL journal files (normal operation) |
| `.htaccess` | Same directory as `analytics.php` | Apache rules to block direct access to the DB and marker files |

Optional: If you enable geolocation and upload the IP2Location database, an `.BIN` file is also stored alongside `analytics.php`.

## Project Structure

```
/src
  config.php          # Constants, config functions, DB path, salt rotation
  database.php        # SQLite connection and schema
  auth.php            # Login, session, password management
  useragent.php       # User-Agent parsing (device, browser, OS)
  geo.php             # IP2Location integration
  tracker.php         # Hit recording, beacon handling, JS snippet
  cleanup.php         # Data retention, summarization, pruning
  export.php          # CSV export
  api.php             # Dashboard data queries
  setup.php           # First-run setup wizard
  settings.php        # Settings management UI
  dashboard.php       # Dashboard rendering (HTML, inline CSS/JS)
  bootstrap.php       # Entry point and routing
  /assets             # CSS, JS, SVG (inlined at build time)
/tests                # PHPUnit test suite
build.php             # Build script
```

## Privacy & Data Handling

MintyMetrics is designed to be privacy-compliant by default. A detailed privacy page is built into the dashboard at `?compliance`.

**Collected:** page URL, referrer, device/browser/OS (from User-Agent), screen resolution, language, country (optional), UTM parameters.

**NOT collected:** no cookies, no IP addresses stored (hashed immediately with a daily-rotating salt then discarded), no cross-day tracking, no fingerprinting, no personal data.

**No cookie consent needed** — MintyMetrics uses no cookies, local storage, or any client-side storage. Under GDPR and the ePrivacy Directive, consent requirements apply to information stored on a user's device; since nothing is stored, no banner is required.

**DNT / GPC:** when Do Not Track or Global Privacy Control signals are detected, no data is collected for that visitor (enabled by default).

**Data retention:** raw pageview data is retained for a configurable period (default 90 days), after which it is aggregated into anonymous daily summaries and deleted. Summaries contain no individual visitor information.

## AI Attribution

This project was built with significant assistance from [Claude](https://claude.ai) by Anthropic. Claude contributed to architecture design, code implementation, testing, and documentation.

## License

MIT License. See [LICENSE](LICENSE) for details.

Copyright (c) 2026 [Minty Analyst](https://mintyanalyst.com)
