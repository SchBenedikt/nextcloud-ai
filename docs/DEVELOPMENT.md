# EVA — Development guide

How to set up, test and contribute to eva_ai.

## Requirements

- PHP ≥ 8.2 with `curl`
- Composer (for PHPUnit)
- A Nextcloud instance (≥ 30) for integration testing, or none at all — the unit
  tests run standalone
- Ollama (for manual/functional testing)

## Getting started

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/SchBenedikt/nextcloud-ai.git eva_ai
cd eva_ai

# Install dev dependencies (PHPUnit)
composer install

# Run the test suite
composer test
```

`composer test` runs `phpunit --bootstrap tests/bootstrap.php tests/`.

## Test suite

| File | Covers |
|---|---|
| `tests/ToolPolicySecurityTest.php` | Tool registration, risk classification, surface isolation, prompt-injection rejection |
| `tests/TaskProcessingContractTest.php` | Unique provider IDs, correct task-type IDs, input/output shape contracts |
| `tests/FrontendContractTest.php` | Vue navigation, settings, document pagination and generated frontend contracts |

### How the bootstrap works

`tests/bootstrap.php`:

1. Registers PSR-4 autoloading for `OCA\EvaAi\` (app `lib/`).
2. If the environment variable `NEXTCLOUD_ROOT` points at a Nextcloud installation
   (default: auto-detect `/var/www/nextcloud`), it registers the OCP/`OC\`
   namespaces so contract tests can load real interfaces.
3. Otherwise the contract tests **skip** with a clear message — the suite stays
   green in CI environments without Nextcloud.

Before opening a pull request, frontend changes must pass `npm run build` and the generated-bundle emission checks. Keep the relevant Markdown documentation and `CHANGELOG.md` synchronized with user-visible changes.

## CI

`.github/workflows/tests.yml`:

- **PHPUnit matrix** on PHP 8.2, 8.3, 8.4
- **PHP syntax check** (lint) on PHP 8.3
- `composer install` has a built-in retry (3 attempts) because fresh GitHub
  runners can transiently fail downloading Composer dist archives from the
  GitHub API with an SSL error.

## Code style

- PSR-4 namespaces (`OCA\EvaAi\…`)
- Strict types (`declare(strict_types=1);`)
- Follow the conventions of the surrounding code (tabs, comment language mixed
  DE/EN in legacy files — keep new code in English)
- All tool execution must go through `ActionExecutor` and be registered in
  `ToolPolicy` (see [SECURITY.md](SECURITY.md))

## Adding a new tool

1. Register the tool in `ToolPolicy::TOOLS` with `risk`, `surfaces` and
   `requiresConfirmation`.
2. Implement the operation in the matching service (or a new one).
3. Expose it in `ActionExecutor` (the chokepoint).
4. Add a security test case in `tests/ToolPolicySecurityTest.php`.
5. Document it in `README.md` and `docs/SECURITY.md`.

## Contributing

1. Fork the repository and create a feature branch.
2. Make your change, run `composer test` and the syntax check.
3. Push the branch and open a pull request against `main`.
4. CI must pass (PHPUnit 8.2/8.3/8.4 + lint).

## Release checklist

1. Bump `<version>` in `appinfo/info.xml` **and** `package.json` (keep in sync).
2. Add a `CHANGELOG.md` entry.
3. Ensure `max-version` matches the Nextcloud versions the app is tested against.
4. Run `composer test`, the lint job and a manual smoke test on the target
   Nextcloud version.
5. Tag the release and push.

## Validation notes

When changing the shared workspace layout, keep the responsive `--eva-content-width` contract, native navigation controls, bounded centered New chat sizing, notification app-icon URL generation, stable TaskProcessing provider IDs, and tool-policy confirmation boundaries covered by tests. Regenerate committed frontend bundles only after source validation succeeds.
