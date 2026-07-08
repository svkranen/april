# GitHub Release Readiness

This document tracks the public-release readiness of the APRIL Community Core.
It is intentionally public-safe: it does not list internal hosts, credentials,
repository URLs, customer data, or private package names.

## Current Status

APRIL Community Core is being prepared as a connector-independent open-core
process intelligence application.

The Community quickstart is expected to work with public Composer packages only:

- `composer.json` does not require private connector packages.
- `composer.lock` is generated without private package sources.
- `symfony.lock` does not list private connector recipes.
- `composer install` must not require private repository credentials.

Optional connectors must live outside the Community Core. Enterprise and private
adapters are out of scope for the public release path.

## Public-Ready Areas

- Event-driven process intelligence core
- Canonical event ingestion
- Process instance reconstruction
- Context snapshots
- Process template loading and checks
- Decision rule evaluation
- Demo process templates and demo fixtures
- Local Docker quickstart
- Demo user command
- Wizard definition loading and read-only rendering
- Community UI terminology and first-insight entry points

These areas should remain connector-independent and must not depend on private
services, private package repositories, or vendor-specific runtime services.

## Composer And CI

The Community Composer install path is public-safe when all of the following
remain true:

- No private Composer packages are required.
- No custom private Composer repositories are configured.
- Composer secure transport is not disabled for private repository access.
- CI does not configure credentials for private Composer package sources.

The current Community CI path should install dependencies using the checked-in
lock file and public package sources only.

## Optional Connector Boundary

Connectors are allowed to provide system-specific names and behavior, but they
must not be required by the Community Core.

Community Core should depend on ports and neutral implementations, such as:

- event normalizers
- context providers
- signature verifiers
- demo or null adapters

System-specific connector implementations should be shipped separately, for
example as optional packages or enterprise/private adapters.

## Remaining Compatibility Cleanup

There are still two excluded compatibility adapter classes in the source tree.
They are not part of the Community service container and do not block the
Community Composer install path.

Before public release, they should either be removed from the Community Core or
moved into an optional connector package.

## License Decision

The package metadata still declares the project license as `proprietary`.

This is a public-release blocker until the project license is explicitly
decided and reflected consistently in:

- `composer.json`
- repository license file
- README license section
- release documentation

Do not guess the license. Choose and document the intended open-source license
before publishing the repository.

## Public Release Checklist

- Composer install works without private credentials.
- Docker quickstart works from a fresh clone.
- Demo user can be created locally.
- Incident Management demo fixtures can be loaded.
- Browser login and first-insight entry points work.
- No internal hosts, credentials, customer names, or private repository URLs are
  present in public docs or configuration.
- Optional connector code is separated from the Community Core runtime path.
- License decision is complete and documented.

## Out Of Scope For Community Release

- Enterprise/private adapters
- Customer-specific templates or mappings
- Internal deployment workflows
- Private package registries
- Connector-specific secrets or credentials
- Runtime wizard progress persistence
