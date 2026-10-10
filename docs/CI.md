# Continuous integration

EVA's checks run on every pull request and push to `main`. Workflows live in
`.github/workflows/`. `Tests` and `Quality` set least-privilege
`permissions: contents: read` and a per-ref `concurrency` group that cancels
superseded in-progress runs.

## Tests (`tests.yml`)

- **coverage** — PHPUnit with Xdebug coverage on PHP 8.3, uploaded to Codecov.
- **phpunit** — the PHPUnit suite on PHP 8.2, 8.3, and 8.4.
- **groq-contract** — runs `tests/GroqTest.php` (`--fail-on-skipped`) against
  the real Nextcloud public API checked out from `nextcloud/server`.
- **migration-regression** — `tests/MigrationRegressionTest.php`,
  `tests/TaskProcessingContractTest.php`, `tests/AppConfigUserSettingsTest.php`.
- **nextcloud-app** — installs the app into real Nextcloud `stable30`/`stable33`/
  `stable35` in the official Nextcloud PHP CI container: fresh SQLite instance →
  `occ app:enable eva_ai` → `occ app:list` → `tests/ApiControllerIntegrationTest.php`
  inside the install → `composer test`. This catches metadata, bootstrap, route,
  dependency, and runtime-API breakage across the oldest/middle/newest lines.
- **frontend** — `npm ci`, `npm test` (`node --test`), Playwright browser tests,
  a production `npm run build`, and a Git check that the committed `js/` bundles
  match a fresh build (a `src/` change must be rebuilt and committed).
- **lint** — `php -l` over every PHP file in `lib/` and `tests/`.

## Quality (`quality.yml`)

- **audit** — `composer audit` and `npm audit --omit=dev`.
- **eslint (advisory)** — `npx eslint .` with `continue-on-error`; findings are
  surfaced but never block merges (see `AGENTS.md`).

## Nightly (`nightly.yml`)

- **compat** — scheduled across `stable30`–`stable35` and `latest`: a fresh SQLite
  Nextcloud install, `occ app:enable eva_ai`, `occ app:list`, then `composer test`.

## Local equivalents

```bash
composer test
npm test
npm run build
npm run lint     # advisory ESLint
```

The app-lifecycle jobs need Docker and network access to a Nextcloud release, so
they run in GitHub Actions rather than against the standalone local PHPUnit
bootstrap.
