# Agent guidelines for this repository

This file documents the commands agents and contributors use to build, test,
and lint **EVA**, the Nextcloud app in this repo. For full context see
[CONTRIBUTING.md](CONTRIBUTING.md) and [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Toolchain

- Node.js / npm: see `.nvmrc` (24.x).
- PHP: 8.2+ (with `curl`); CI also runs 8.3/8.4.
- Ollama is only needed to run the app end‑to‑end, not for CI.

## Build

```bash
npm ci
npm run build          # webpack -> committed js/ bundles (source of truth)
```

Any change under `src/` or to frontend dependencies **must** be followed by
`npm run build`; CI fails if the committed `js/` output differs from a fresh
build. Webpack cleans `js/` before emitting, so removed entry points delete
bundles — commit those deletions too.

## Test

```bash
composer test          # PHPUnit (PHP), see phpunit.xml.dist + tests/bootstrap.php
npm test               # Frontend unit tests (node --test tests/*.test.cjs)
npm run test:browser   # Playwright (installs Chromium)
```

## Lint

```bash
npm run lint           # ESLint (flat config in eslint.config.js)
```

ESLint configuration is **advisory**: it runs in CI but does not block merges.
Keep new code lint‑clean where practical instead of mass‑editing existing files.

## Conventions

- User‑facing strings must be English (`EnglishOnlyMessagesTest`).
- Respect the request/response DTO pattern under `lib/Dto/`.
- Update the matching `docs/*.md` when behavior changes.
- Open a PR for every change; never push directly to `main`.

## CI workflows

- `.github/workflows/tests.yml` — PHPUnit (8.2/8.3/8.4), frontend build +
  bundle‑match, browser tests, Nextcloud app lifecycle, `php -l`.
- `.github/workflows/quality.yml` — `composer audit`, `npm audit`, ESLint
  (advisory).
- `.github/workflows/nightly.yml` — Nextcloud stable30–35 + latest compat.
