# AGENTS.md — Working on APRIL

Guidance for AI coding assistants and human contributors working on APRIL.

Read the documentation in `docs/architecture/` before making structural changes.
When in doubt, prefer the smallest change that keeps all tests green and strengthens
the existing architecture.

## What APRIL is

APRIL is a process intelligence application (Symfony 7.4, PHP >= 8.2, Doctrine ORM,
PostgreSQL in Docker / SQLite for bare-metal dev, Twig + AssetMapper, no Node build).
It ingests business events, reconstructs process instances ("items" and "journeys"),
stores context snapshots, and checks observed behavior against versioned YAML process
templates, producing findings (deviations, rule violations, SLA breaches).

APRIL is not a workflow engine. It observes and explains; it does not orchestrate.

## Philosophy

APRIL values long-term maintainability over short-term convenience.

When multiple solutions are possible, prefer the one that:

- keeps the Community Core connector-independent,
- improves readability,
- strengthens the architecture,
- reduces future complexity,
- and remains easy to test.

Small, well-tested improvements are preferred over large refactorings.

The principles in this document are guidelines, not dogma. Apply them where they
improve the codebase and the developer experience.

## Golden rules (non-negotiable)

- The Community Core stays connector-independent. No vendor names, vendor API
  clients, vendor headers, or vendor-specific date formats in
  `src/Intelligence/{Domain,Application,Port}`. Vendor-specific code lives only in
  `src/Intelligence/Connector/<Vendor>/`.
- Dependency direction: Domain -> nothing. Application -> Domain + Port.
  Infrastructure and Connector implement Ports. Controllers and Commands are thin
  adapters that call Application services. Architecture tests in
  `tests/Architecture/` and `tests/Intelligence/Architecture/` enforce parts of
  this; never weaken them.
- `src/Service/` is a legacy quarantine zone. Do not add new code there. Do not add
  new dependencies on it from `src/Intelligence/`. Bug fixes are fine; new features
  belong in the Intelligence namespace or a Connector.
- Never weaken or delete tests to make a change pass. If a test blocks you, the
  test is probably encoding a rule from this file. Explain the conflict instead of
  "fixing" the test.
- The event store is append-only. Never write code that silently rewrites or deletes
  historical process events or context snapshots. Repair/migration paths must be
  explicit commands with dry-run support.
- No secrets in the repo. No real hostnames, tokens, customer names, or production
  paths; not in code, fixtures, docs, or test data. Use `example.local`, `acme`,
  etc.
- Migrations are immutable once merged. Never edit an existing file in
  `migrations/`; add a new migration instead.
- Fail closed on auth. Empty/missing secrets must disable an endpoint, not open it.

## Security rules

These apply to every change; violations block merge.

- Fail closed on auth. An empty or missing secret disables an endpoint (403 + log
  hint) outside dev. Never treat "no secret configured" as "no auth required".
- Secrets travel in headers only. Never read tokens/secrets from query or form
  parameters (they end up in access logs, proxies, and browser history). The event
  API uses `X-Intelligence-Signature` with HMAC-SHA256 over the raw body;
  plain-secret comparison is legacy and must not spread to new endpoints.
- Constant-time comparison for any secret/signature check (`hash_equals`), never
  `===` on secrets.
- Never log secrets or credentials. Payload/header logging must go through
  redaction (see the `safe*` helpers in `IntelligenceEventController` for the
  pattern) and use the debug level, not info.
- CSRF stays on for all session-based forms (login included). New forms use
  Symfony's CSRF tokens; never set `enable_csrf: false`.
- Availability of the observed process beats strictness at the intake boundary:
  the event endpoint accepts-or-rejects in the response body, always HTTP 200
  (ADR-005). This is a deliberate architecture decision to avoid blocking or
  retry-storming observed systems; do not change it to 4xx/5xx without discussing
  those operational effects. Everywhere else, strict status codes apply.
- Input is untrusted. Validate event payloads at the boundary (types, required
  fields, size limits); the Domain layer may assume validated input.
- No new public routes without an explicit entry in `security.yaml`
  `access_control` and a stated reason in the PR description.
- Output escaping stays on. No `|raw` in Twig on data that originates from events,
  templates, or user input; sanitize anything rendered into SVG/Mermaid.
- Demo/test conveniences are environment-guarded. Anything that writes credentials
  or weakens auth must refuse to run outside dev/test without `--force` (see
  `april:demo:user:create` for the pattern).
- Dependencies: run `composer audit` when adding or bumping packages; do not add
  packages for functionality Symfony already provides.

## Repository map

| Path | What it is | Agent policy |
| --- | --- | --- |
| `src/Intelligence/Domain/` | Pure domain model (templates, events, deviations, evaluators) | No framework, no I/O, no vendor names |
| `src/Intelligence/Application/` | Use cases: intake, checks, timelines, graph/metrics factories | Depends only on Domain + Port |
| `src/Intelligence/Port/` | Interfaces (event store, signature verifier, access probes, ...) | Interfaces + small value objects only |
| `src/Intelligence/Infrastructure/` | Doctrine entities, event stores, HMAC verifier, template loading | Implements Ports; Symfony/Doctrine allowed |
| `src/Intelligence/Connector/Amagno/` | Connector-specific adapter code | Only place where this connector name may appear in Intelligence |
| `src/Intelligence/Bpmn/`, `src/Intelligence/Template/` | Rendering/parsing helpers | Keep free of vendor coupling |
| `src/Controller/`, `src/Controller/App/` | HTTP adapters | Thin: validate -> call Application service -> render. Must not reference Commands (enforced by test) |
| `src/Command/` | CLI adapters (`april:*`, legacy `intelligence:*`) | Thin: parse input -> call Application service -> format output. Do not put business logic here |
| `src/Service/`, `src/SignatureCheck/`, `src/Dto/`, `src/View/` | Legacy pre-hexagonal code | Quarantine: fix bugs only, no new features, no new dependents |
| `src/Wizard/` | Onboarding wizard (definitions in `config/april/wizards/`) | Self-contained; follow existing patterns |
| `config/april/process-templates/` | YAML process templates | See `docs/templates/reference.md` and `docs/templates/cookbook.md` |
| `templates/web/` | Twig UI | Use `assets/` AssetMapper CSS/JS (importmap, Mermaid) |
| `assets/` | AssetMapper CSS/JS | No Node toolchain; keep `importmap.php` in sync |
| `tests/` | PHPUnit including architecture guards and fakes (`tests/Fake/`) | Mirror `src/` structure for new tests |
| `docs/` | Architecture and user docs | Update when behavior changes; `docs/legacy/` is frozen |

## Dead legacy artifacts
Historical connector-specific artifacts have been removed from the Community Core.
Do not reintroduce vendor-specific helper files or configuration into the repository.

## Environment & commands

Docker quickstart (canonical dev environment, FrankenPHP + PostgreSQL 16):

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console april:demo:user:create   # dev/test only
docker compose exec app php bin/console april:fixtures:load --reset
# App: http://localhost:8080/app
```

Verification (run the relevant subset before declaring a task done; prefix with
`docker compose exec app` when using Docker):

```bash
composer validate
php bin/console lint:container
php bin/console lint:twig templates/
php bin/console lint:yaml config/
./vendor/bin/simple-phpunit                          # full suite
./vendor/bin/simple-phpunit tests/Intelligence/      # focused
composer test                                        # alias for the suite
```

Notes:

- Tests must run without a live connector, DMS, or network. Use fixtures and the
  fakes in `tests/Fake/` and `tests/Intelligence/**`. If you need a new port
  implementation for tests, add a fake; never a mock of a concrete vendor client.
- `DATABASE_URL` differs between `.env.example` (SQLite) and `compose.yaml`
  (PostgreSQL). Docker's environment wins inside the container. Write
  DBAL-portable code (no raw vendor-specific SQL in the core).
- PHP support target: 8.2-8.4 (CI runs 8.4). Do not use features beyond the
  minimum version in `composer.json` without bumping it deliberately.

## Coding conventions

- PHP: PSR-12, declare strict types where files already use them, final classes by
  default, constructor property promotion, readonly where possible; match
  surrounding code.
- Naming: new console commands use the `april:` prefix with a sub-namespace
  (`april:template:check`, `april:event:list`). Do not add new `intelligence:` or
  vendor-prefixed commands; keep old names as aliases when renaming.
- Language: code, identifiers, commit messages, and new docs in English. UI strings
  go through the translator with keys in `translations/messages.{de,en}.yaml`;
  never hardcode German or English strings in Twig templates or PHP.
- Twig: extend `web/layout/base.html.twig`; put styles in `assets/styles/`, JS in
  `assets/` via importmap. Add `aria-` attributes and table `scope`/`caption` to
  any table or interactive element you touch.
- Errors: HTTP APIs return proper status codes (400/401/403/422), JSON bodies with
  an error code and human-readable message. Deliberate exception: the event intake
  endpoint (`/api/intelligence/events`) always returns HTTP 200 with
  `{"accepted": false, "error": ...}` on failures. This is a design decision
  (ADR-005), not a bug. Source systems can hang or retry-storm when a monitoring
  hook returns non-2xx; APRIL must never block or stall the observed process. Do
  not "fix" this to 4xx. Rejections must still be fully visible: machine-readable
  error code in the body plus a log entry, so failures surface in APRIL instead of
  in the source system.
- Logging: request/payload dumps at debug level only; never log secrets or full
  credentials (the `safe*` helpers in the event controller show the expected
  pattern).
- Size guidance: keep classes under ~300 lines and methods under ~40 where
  practical. If your change grows a class past that, extract a collaborator
  instead.

## Object design (inspired by "Elegant Objects", applied selectively)

These principles are applied pragmatically and are intended to improve
maintainability, readability and testability. We follow these EO principles where
they match how the Intelligence core is built:

- Immutability by default. Value objects and results are final + readonly;
  "changing" state means returning a new instance. No setters in
  Domain/Application.
- Constructors assign, never compute. No I/O, parsing, or validation logic in
  constructors; use named static factories (`fromArray()`, `fromYaml()`) for that.
- Fakes over mocks. Test through real objects and the fakes in `tests/Fake/`;
  never mock a concrete class, only implement Ports.
- Small, focused classes. Prefer composing small collaborators over growing a
  class; four or fewer constructor dependencies is a good smell test.
- Fail fast, no null passing. Prefer throwing specific exceptions or returning
  explicit result objects over returning/accepting null in the Domain layer.
- No inheritance for reuse. final classes, composition, and interfaces (Ports);
  no abstract base classes for sharing code.

We deliberately do NOT follow these EO rules, because they conflict with the
Symfony/Doctrine stack and the existing codebase; do not "fix" code toward them:

- The ORM ban: Doctrine entities in Infrastructure/Doctrine are the canonical store.
- The getter ban: Doctrine entities and Symfony forms use accessors; that is fine
  outside the Domain layer.
- The "-er naming" ban: `*Renderer`, `*Provider`, `*Checker`, `*Evaluator` are the
  established convention here.
- The static-method ban: named static constructors on value objects are encouraged.

## Task playbooks

### Adding a template check / finding type

- Model it in Domain (evaluator + result value object), unit-test it in isolation.
- Wire it into the check pipeline in Intelligence/Application (see
  `ProcessTemplateCheckService` and the existing evaluators).
- Extend the YAML template schema only if needed; update
  `docs/templates/reference.md` and add a cookbook example.
- Surface it: CLI output + web view (translated labels), plus a demo fixture that
  triggers it.

### Adding a connector

- Create `src/Intelligence/Connector/<Vendor>/`. Implement the relevant Ports.
- Normalize everything (dates, IDs, event names) at the connector boundary; the core
  must never see vendor formats.
- Config via parameters/env with a vendor-prefixed namespace; no defaults that point
  at real systems.
- Tests with recorded/fixture payloads only.

### Refactoring legacy `src/Service/` code

- Target location: matching Connector or Application namespace.
- Move behind an existing or new Port; keep the old class as a thin deprecated
  forwarder for one release if anything still references it.
- Update `services.yaml` wiring and rename vendor-specific parameters you migrate
  to a connector-scoped namespace.
- Add/extend an architecture test so the dependency cannot come back.

### UI changes

- No new inline styles; extend `assets/styles/` tokens.
- Every new user-visible string: translation key in both de and en files.
- Check the view with demo fixtures loaded and with an empty database (empty states).
- Run `php bin/console lint:twig templates/`.

## Definition of done

- Full test suite green (`composer test`); new behavior covered by tests.
- Architecture tests untouched or strengthened.
- Community Core remains connector-independent.
- No new references from Intelligence core to vendors or to `src/Service/`.
- Lints pass (container, twig, yaml, php -l implicitly via CI).
- Docs updated if behavior, config, CLI, or API changed.
- No public documentation regressed to vendor-specific wording.
- No secrets, customer names, or real hostnames introduced.
- Diff reviewed for accidental formatting churn (`git diff --check`).

## Commit & PR conventions

- Small, focused commits; imperative subject, <= 72 chars, English
  (e.g. `Extract SLA evaluation from ProcessTemplateCheckService`).
- One concern per PR. Refactoring and behavior changes in separate commits at
  minimum.
- PR description: what/why, how it was verified (commands run), and any follow-ups.
- If architecture, configuration, security, or public APIs change, update the
  related documentation in the same PR.
- Discuss schema changes, new Ports, and public API changes in an issue first
  (see `CONTRIBUTING.md`).
