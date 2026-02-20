# Security Policy

## Supported Versions

Only the latest release is supported with security updates. Please ensure you are running the most recent version of `analytics.php` before reporting.

## Reporting a Vulnerability

**Please do not open a public issue for security vulnerabilities.**

Email security reports to: **hi@mintyanalyst.com**

Include:
- A description of the vulnerability
- Steps to reproduce
- Affected version (check `?health` or CLI `--version`)
- Impact assessment if known

## Response Timeline

- **Acknowledgment:** within 48 hours
- **Initial assessment:** within 1 week
- **Fix or mitigation:** depends on severity, but we aim for 30 days for critical issues

## What Counts as a Security Issue

- Authentication or authorization bypass
- SQL injection, XSS, or CSRF issues
- Information disclosure (e.g., leaking visitor data, database contents)
- Path traversal or file access beyond intended scope
- CSP bypass or header injection

## What Is NOT a Security Issue

- Brute-force attacks against the login (already mitigated with lockout)
- Rate limiting effectiveness behind reverse proxies (documented behavior)
- Denial of service via excessive requests (infrastructure concern, not application)
- Missing features or general bugs (use the issue tracker)

## Credit

We're happy to credit reporters in release notes (with permission). Let us know in your report if you'd like to be acknowledged.
