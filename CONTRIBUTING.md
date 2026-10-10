# Contributing to EVA

Thanks for your interest in improving EVA, the AI assistant app for
Nextcloud. This guide covers how to set up a development environment, run
the test suite, and get changes merged.

Please also read [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) and
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Ground rules

- **Open a pull request for every change.** Do not push directly to `main`.
- Keep PRs small and focused; one logical change per PR.
- CI must be green before merge (see [Continuous integration](#continuous-integration)).
- Reference the issue your work addresses (e.g. `Closes #123`).
- Keep user-facing messages in English (enforced by `EnglishOnlyMessagesTest`).
- Follow the request/response **DTO** pattern used under `lib/Dto/`.

## Requirements

| Tool | Version |
|------|---------|
| PHP | 8.2+ (with `curl`), or 8.3/8.4 |
| Node.js | see `.nvmrc` (24.x) |
| npm | 10.5+ |
| Ollama | optional, reachable from the server |

## Local setup

Install the app into a Nextcloud checkout so paths line up with the App
Store layout (`apps/eva_ai`):

```bash
# from nextcloud/apps
git clone https://github.com/SchBenedikt/nextcloud-ai.git eva_ai
cd eva_ai

composer install --no-interaction --prefer-dist
npm ci
npm run build

# from the nextcloud root to enable the app:
sudo -u www-data php occ app:enable eva_ai
```

Pull requests that only touch code can skip `npm run build` locally, but any
change under `src/` or to frontend dependencies **must** rebuild the committed
`js/` bundles (see below).

## Running the tests

```bash
composer test              # PHPUnit (PHP), see phpunit.xml.dist
npm test                   # Frontend unit tests (node --test)
npm run test:browser       # Playwright browser tests (installs Chromium)
```

Frontend browser tests need a local Chromium. Install it once with:

```bash
npx playwright install --with-deps chromium
```

## Rebuilding the frontend bundles

The `js/` directory contains the **committed build output** that the app
serves at runtime — it is source of truth for the running app, not a build
artifact you can ignore. `src/` changes or dependency bumps require a rebuild:

```bash
npm ci
npm run build
git add js/
```

The `Tests` workflow **fails** if `js/` differs from a fresh build, so a
source change that was never rebuilt cannot be merged. Webpack cleans `js/`
before emitting; a removed entry point shows up as a deleted bundle and must
also be committed.

## Continuous integration

Defined under `.github/workflows/`:

- **Tests** (`tests.yml`, on PRs and pushes to `main`):
  - PHPUnit across PHP 8.2 / 8.3 / 8.4 (+ coverage)
  - Frontend production build, `js/` bundle-match verification, browser tests
  - Nextcloud app lifecycle on stable30/33/35 (install → `app:enable` → tests)
  - `php -l` syntax check and migration/provider regression tests
- **Quality** (`quality.yml`): `composer audit` and `npm audit`.
- **Nightly** (`nightly.yml`): compatibility across stable30–35 + latest.

## Documentation

Architecture, API, plugin API, security, and versioning live in
[docs/](docs/). Update the relevant doc in the same PR when you change
behavior.

## Reporting bugs / features

Use the GitHub issue templates under
[.github/ISSUE_TEMPLATE](.github/ISSUE_TEMPLATE). For security issues, see
[.github/SECURITY.md](.github/SECURITY.md) and do **not** open a public issue.
