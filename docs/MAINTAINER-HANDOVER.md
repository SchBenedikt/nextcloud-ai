# Maintainer and ownership handover

This document prepares EVA for a possible transfer or broader maintenance
within the Nextcloud community. It does not claim that Nextcloud has accepted a
transfer or endorsed the app.

## Project snapshot

- App ID: `eva_ai`.
- Repository: <https://github.com/SchBenedikt/nextcloud-ai>.
- App Store listing: <https://apps.nextcloud.com/apps/eva_ai>.
- License and conduct: AGPL-3.0-or-later; see [LICENSE](../LICENSE) and
  [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md).
- Current manifest version: `1.24.5`; declared server range: Nextcloud 30–36.
- The release workflow builds a production archive on `vX.Y.Z` tags. App Store
  publication is present as a disabled opt-in; details are in
  [VERSIONING.md](VERSIONING.md).

Before a handover request, verify the live App Store version, the matching
release archive, open security issues, supported-server claims, and the current
release/CI status. Name a primary and backup maintainer and agree who will own
issue triage, reviews, security reports, and releases.

## Repository transfer or community maintenance

Repository ownership in the Nextcloud GitHub organization is an organizational
decision, separate from app installation, App Store listing, and inclusion in
the Assistant documentation. Contact a Nextcloud contributor or organization
administrator and ask which path they prefer: repository transfer, additional
community maintainers, or a contribution to an existing Nextcloud project.

Suggested request:

> I maintain EVA (`eva_ai`), an AGPL-3.0-or-later Nextcloud app with native
> TaskProcessing providers for Nextcloud Assistant. I would like to discuss
> whether the repository should move to the Nextcloud organization or whether
> community co-maintainers would be a better fit. The repository is
> <https://github.com/SchBenedikt/nextcloud-ai> and the App Store listing is
> <https://apps.nextcloud.com/apps/eva_ai>. I can provide the release, CI,
> compatibility, security, and support details and will follow the Nextcloud
> Code of Conduct. Please let me know the appropriate contact and requirements.

Do not transfer the repository or change its visibility until the receiving
maintainers have agreed on ownership, access, release responsibilities, support
contacts, and the handling of GitHub Actions secrets. A transfer does not by
itself transfer the App Store listing or constitute an endorsement.

## Release automation and signing keys

The Store upload job in `.github/workflows/release.yml` is deliberately
disabled unless the repository variable `APPSTORE_PUBLISH_ENABLED` equals
`true`. Before enabling it:

1. Create a GitHub Environment named `release` and configure required reviewers
   and allowed deployment refs for release tags.
2. Add the App Store API token as the environment secret `APPSTORE_TOKEN` and
   the app signing private key as the environment secret `APP_PRIVATE_KEY`.
   Use the App Store's registered key and token; never commit them or print
   them for troubleshooting.
3. Review who can create tags and approve the `release` environment. Keep the
   publishing variable unset until the environment protection and secrets have
   been verified; then set `APPSTORE_PUBLISH_ENABLED=true` to opt in.
4. Perform the first publication as a deliberately reviewed release and verify
   the resulting GitHub asset and App Store version. App Store review may still
   be required.

The credential files in any local certificate directory are not needed by the
workflow and should remain outside the repository. Reassess secret access and
the registered signing identity after any ownership change; do not replace or
rotate the signing key until the App Store certificate/registration impact is
understood. The upload action is pinned to the commit referenced by Nextcloud's
release-automation documentation.

## Nextcloud Assistant visibility and compatibility

EVA is already listed in the App Store and exposes native TaskProcessing
providers. Being mentioned in the Assistant project documentation is a separate
maintainer decision. Ask the maintainers of
<https://github.com/nextcloud/assistant> whether they want a provider listing
or a small documentation pull request; include supported task types, setup and
privacy notes, and current CI evidence. Do not imply that a listing request is
an official integration or endorsement until accepted.

The manifest currently declares support through Nextcloud 36, while the
repository's lifecycle checks exercise stable30/33/35 and nightly stable30–35
plus `latest`. At the time this handover note was prepared, Nextcloud 36 was
documented as upcoming, not stable. Keep the existing `latest` check and add an
explicit `stable36` lifecycle job only after the stable36 release archive is
available; until then do not describe stable36 as CI-verified.