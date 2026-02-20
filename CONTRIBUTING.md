# Contributing to MintyMetrics

Thanks for your interest in contributing!

## Reporting Bugs

Open an issue with steps to reproduce, expected behavior, and your PHP version.

## Submitting Changes

1. Fork the repo and create a branch from `main`
2. Make your changes in `/src` (not `analytics.php` directly)
3. Run `composer test` to ensure all tests pass
4. Run `php build.php` to verify the build succeeds
5. Submit a pull request

## Development Setup

```bash
git clone https://github.com/dobromirdikov/mintymetrics.git
cd mintymetrics
composer install
composer test
```

## Code Style

- All functions live in `namespace MintyMetrics` (no classes except `GeoReader`)
- Prefix PHP builtins with `\` (e.g., `\time()`, `\header()`)
- Use prepared statements for all database queries
- Escape all output with `e()` for HTML contexts

## Build System

The distributable `analytics.php` is compiled from `/src` modules via `build.php`. Never edit `analytics.php` directly — it's regenerated on every build.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
