# Security Policy

EVA processes file contents, chat prompts, and (optionally) connected service
data. We take security reports seriously.

## Supported versions

Security fixes are provided for the **latest released line** (currently the
`1.17.x` series). See [docs/VERSIONING.md](docs/VERSIONING.md) for the release
and support policy, and [appinfo/info.xml](appinfo/info.xml) for the supported
Nextcloud (`30`–`35`) and PHP (`8.2+`) versions.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, report them privately to the maintainer via GitHub private
vulnerability reporting:

- Go to **Security → Advisories → Report a vulnerability** at
  <https://github.com/SchBenedikt/nextcloud-ai/security/advisories/new>

Please include:

- A description of the issue and its impact
- Steps to reproduce (Nextcloud version, provider/config, request/response if
  relevant)
- Any suggested remediation

We aim to acknowledge reports within 5 business days and to coordinate a fix
and disclosure timeline with you.

## Data-flow awareness

Where prompts and excerpts are sent depends on the configured model and
enabled integrations. See the "Data & Privacy" section of the README and
[docs/SECURITY.md](docs/SECURITY.md) for the tool/policy model and the review
checklist before enabling write-capable tools or external connectors.
