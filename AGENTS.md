# AGENTS.md

This repository is the canonical source for the Kenzi OroCommerce bundle.

## Ownership

- Package: `kenzi-chat/orocommerce-bundle`
- Primary install channel: Packagist
- Supported host lines: OroCommerce 6.0 and 6.1
- PHP floor: 8.1

Do not route changes through `kenzi-chat/web/platforms/orocommerce`. That path
is historical after the polyrepo split.

## Compatibility Rules

- Keep the `oro/commerce` Composer constraint compatible with OroCommerce 6.0
  and 6.1.
- Version-neutral services must not import host-line-specific Oro classes.
- Host-line-specific compatibility code belongs in clearly named compatibility
  modules.
- Changes must preserve both demo targets:
  - `oro60.demo.kenzi.chat`
  - `oro61.demo.kenzi.chat`

## Development Commands

Run commands from the repository root.

```bash
composer install
composer validate --strict
composer lint
composer analyze
composer test
```

## Release Policy

Customer releases are native `v*` tags from this repository. Release automation
must validate the exact tag commit before publishing a GitHub release or relying
on Packagist ingestion.

If `composer.json` contains a `version` field, the tag version without the `v`
prefix must match it. If no `version` field is present, Packagist derives the
version from the tag.

Before customer release, verify Packagist resolves
`kenzi-chat/orocommerce-bundle` from this repository.
