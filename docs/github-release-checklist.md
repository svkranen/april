# APRIL GitHub Release Candidate Checklist

This checklist tracks the remaining work before the first public GitHub release
of APRIL Community Core.

## Installability

- [ ] `composer install --no-interaction --no-progress` works without private
  Composer repositories or credentials.
- [ ] `composer.json` contains no private packages.
- [ ] `composer.lock` contains no private package sources.
- [ ] `symfony.lock` contains no private connector recipes.
- [ ] `.env.example` contains only Community-safe defaults.
- [ ] No private hosts, credentials, tokens, customer names, or internal
  repository URLs are present in public configuration or documentation.

## Fresh Clone Quickstart

- [ ] Fresh clone starts from `cp .env.example .env`.
- [ ] `docker compose up -d --build` starts the app and PostgreSQL services.
- [ ] `docker compose exec app composer install` completes.
- [ ] `docker compose exec app php bin/console doctrine:migrations:migrate`
  completes on a fresh database.
- [ ] `docker compose exec app php bin/console april:demo:user:create` creates
  the local demo user.
- [ ] `docker compose exec app php bin/console april:fixtures:load --reset`
  imports the Incident Management demo fixtures.
- [ ] Browser opens `http://localhost:8080` without HTTPS redirect loops.
- [ ] Login works with the documented demo credentials.
- [ ] Items, Journey, Findings, and Process Graph are reachable.
- [ ] The Incident Management deviation produces a visible Decision Rule
  Violation.
- [ ] Guided tours are reachable from the app navigation.
- [ ] `/app/wizards/first-insight` renders the read-only First Insight wizard.

## Documentation

- [ ] README Quickstart matches the tested fresh clone path exactly.
- [ ] README uses English as the primary language.
- [ ] README uses Community terminology such as Item, Journey, Finding, and
  Context Snapshot.
- [ ] `LICENSE` contains the complete Apache-2.0 license text.
- [ ] `composer.json` declares `Apache-2.0`.
- [ ] Public docs are connector-independent or clearly describe optional
  connectors as out of scope for the Community quickstart.
- [ ] Legacy connector-specific docs remain archived under `docs/legacy/`.
- [ ] `docs/github-readiness.md` reflects the current release state.

## GitHub Metadata

- [ ] `CONTRIBUTING.md` exists and explains how to run checks locally.
- [ ] `SECURITY.md` exists and explains how to report vulnerabilities.
- [ ] `CODE_OF_CONDUCT.md` is added or intentionally deferred.
- [ ] Issue templates are added or intentionally deferred.
- [ ] Pull request template is added or intentionally deferred.
- [ ] Repository description, topics, and homepage are set before announcement.

## Validation Before Release Tag

- [ ] `composer validate`
- [ ] `composer install --no-interaction --no-progress`
- [ ] `php bin/console lint:yaml config --parse-tags`
- [ ] `php bin/console lint:twig templates`
- [ ] `php bin/console lint:container`
- [ ] Relevant PHPUnit suites for:
  - [ ] fixture loader
  - [ ] demo user command
  - [ ] inline context snapshots
  - [ ] security/login redirect behavior
  - [ ] docs controller
  - [ ] wizard loader, renderer, view model, and web detail page
- [ ] `git diff --check`
- [ ] `git status --short` is clean before tagging.

## Known Limitations

- [ ] Doctrine deprecation policy can make selected controller test commands exit
  with status `1` even when assertions are functionally OK.
- [ ] Wizard progress is intentionally read-only and currently reports
  `unknown`; no persistence, session tracking, or completion mutation exists yet.
- [ ] Enterprise/private connectors are out of scope for the Community Core
  release and must live outside the public quickstart path.
- [ ] Legacy documentation is archived for migration context only and is not part
  of the public Community entry path.

## Release Decision

- [ ] All blocking checklist items are complete.
- [ ] Deferred optional GitHub metadata is explicitly accepted.
- [ ] Fresh clone quickstart has been run on a clean machine or clean container
  environment.
- [ ] The release commit is tagged.
- [ ] The public announcement links to README and the tested Quickstart.
