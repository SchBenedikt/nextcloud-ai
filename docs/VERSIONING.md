# Versioning guideline

EVA follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This document defines
how a version is chosen, where it must be recorded, and which invariants CI
enforces so a release can never ship a version that silently skips work.

## Where the version lives

Every release must update all three files to the **same** value:

| File               | Field                              |
| ------------------ | ---------------------------------- |
| `appinfo/info.xml` | `<version>`                        |
| `package.json`     | `"version"`                        |
| `package-lock.json`| root `"version"` and `packages[""].version` |

`tests/ReleaseMetadataTest` fails if they disagree. Never edit only one of them.

## The migration rule (most important)

Nextcloud only runs an app's update steps when the version in `appinfo/info.xml`
is **greater** than the installed version. A migration class
(`lib/Migration/VersionNNNNNN…`) therefore has a hard requirement:

> **The app version must be greater than or equal to the highest migration
> version.**

Concrete example of the bug this prevents: adding
`Version104009Date20260910000000.php` while `info.xml` still says `1.4.8`. The
upgrade path compares the stored installed version with `1.4.8`, sees no change,
and never invokes the migration — the column is missing in production and the
feature that depends on it breaks with no error. The fix is to bump the app
version to `1.4.9` **in the same commit** that adds the migration.

`tests/ReleaseMetadataTest::testAppVersionCoversEveryMigration` enforces this:
it parses every `Version*.php` file, derives the dotted version from the class
name, and asserts that each one is `<=` the `info.xml` version.

## Choosing the next number

| Change                                                         | Bump      | Example        |
| -------------------------------------------------------------- | --------- | -------------- |
| New user-facing feature or capability                          | **MINOR** | 1.4.9 → 1.5.0  |
| New migration, settings, config keys, or non-breaking behavior  | **MINOR** | 1.4.9 → 1.5.0  |
| Bug fix only, no new surface                                   | **PATCH** | 1.4.9 → 1.4.10 |
| Breaking change (removed route, renamed config key, dropped NC) | **MAJOR** | 1.4.9 → 2.0.0  |

Rules of thumb:

1. **One version per merge to `main`.** Do not bump a version without a change
   that justifies it, and do not ship a change that warrants a version without
   bumping it.
2. **Never reuse or renumber a released version.** If `1.4.9` was published, the
   next release is `1.5.0` (or `1.4.10`), even if the content is small.
3. **Migrations get MINOR bumps.** A migration means new persisted state, so it
   is never a PATCH release.
4. **Keep the numbers monotonic.** Nextcloud compares versions with
   `version_compare`, so `1.4.10 > 1.4.9` but `1.4.10 < 1.5.0`. Pad numerically
   rather than logically when in doubt.
5. **PATCH releases must be safe to install without reading the changelog.**

## Migration file naming

`Version<MAJOR><MINOR-PADDED-TO-2><PATCH-PADDED-TO-2>Date<YYYYMMDDHHMMSS>`:

- `1.4.9` → `Version104009Date20260910000000`
- `1.5.0` → `Version105000Date20260915000000`
- `1.4.10` → `Version104010Date20260920000000`

The timestamp only orders migrations that share a version; it never replaces the
version bump.

## Checklist for a release

- [ ] `CHANGELOG.md` has an entry for the version (move items out of
      `[Unreleased]` and add the comparison link).
- [ ] `info.xml`, `package.json` and `package-lock.json` carry the same version.
- [ ] Every new migration's version is `<=` the new app version.
- [ ] `vendor/bin/phpunit` passes (it includes the release-metadata guard).
- [ ] The App Store description in `info.xml` is **flush-left**. CommonMark
      renders lines indented by four or more spaces as a code block, which makes
      the whole store listing appear as source code. Never re-indent it to match
      the surrounding XML.
