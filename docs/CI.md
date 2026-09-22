# Continuous integration

EVA uses separate checks for application compatibility, backend behavior, and
frontend bundles. The checks run on every pull request and push to `main`.

## Nextcloud app lifecycle

The `nextcloud-app` job in `.github/workflows/tests.yml` downloads supported
Nextcloud releases (`stable30`, `stable33`, and `stable35`) into the official
Nextcloud PHP CI container. For each release it:

1. installs the app's Composer dependencies;
2. creates a fresh SQLite Nextcloud instance;
3. enables `eva_ai` through `occ`;
4. runs `occ app:check-code eva_ai`;
5. confirms that EVA routes are registered; and
6. runs the app's PHPUnit suite inside that installation.

This catches incompatible app metadata, bootstrap registration, route loading,
dependency resolution, and runtime API changes. The matrix intentionally covers
the oldest, middle, and newest supported Nextcloud lines. The nightly workflow
extends the same check to every supported line and `latest`.

## Backend and frontend checks

- PHPUnit runs on PHP 8.2, 8.3, and 8.4.
- The PHP lint job parses every PHP file in `lib/` and `tests/`.
- Frontend CI runs the Node behavior tests, Playwright browser checks, a
  production webpack build, and verifies that committed bundles match the build.
- Dependency audits run separately for Composer and npm.

Run the local equivalents from the app directory:

```bash
composer test
npm test
npm run build
```

The app lifecycle job requires Docker and a network connection to download a
Nextcloud release. It is therefore executed in GitHub Actions rather than
inside the standalone local PHPUnit bootstrap.
