# ARCHITECTURE.md — APRIL Community Core

This document describes the Community Core architecture and the dependency rules that
keep APRIL connector-independent. It complements `docs/architecture/core.md`
(concepts) with a code-level view. Agents and contributors: the rules here are
enforced partly by tests and fully by review.

The Community Core is the public Open Core: it owns process intelligence behavior,
while optional connectors adapt external systems at the boundary.

## 1. System context

```
Source systems (DMS, ERP, SaaS, ticketing, custom apps)
        │  events + context (HTTP POST, signed)
        ▼
┌──────────────────────────────────────────────────────────┐
│ APRIL (Symfony 7.4 monolith)                             │
│                                                          │
│  HTTP adapters          CLI adapters                     │
│  src/Controller/**      src/Command/**                   │
│        │                     │                           │
│        ▼                     ▼                           │
│  ┌────────────────────────────────────────┐              │
│  │ Application (use cases)                │              │
│  │ src/Intelligence/Application           │              │
│  └──────────┬────────────────┬────────────┘              │
│             │ uses           │ depends on                │
│             ▼                ▼                           │
│  ┌───────────────┐   ┌───────────────┐                   │
│  │ Domain        │   │ Ports         │                   │
│  │ …/Domain      │   │ …/Port        │                   │
│  └───────────────┘   └───────┬───────┘                   │
│                              │ implemented by            │
│             ┌────────────────┴──────────────┐            │
│             ▼                               ▼            │
│  ┌────────────────────┐        ┌─────────────────────┐   │
│  │ Infrastructure     │        │ Connectors          │   │
│  │ …/Infrastructure   │        │ …/Connector/<Vendor>│   │
│  │ Doctrine, HMAC,    │        │ vendor adapters     │   │
│  │ stores, templates  │        └─────────────────────┘   │
│  └────────────────────┘                                  │
│                                                          │
│  Presentation: Twig (templates/web) + AssetMapper        │
│  Persistence: PostgreSQL (Docker) / SQLite (bare dev)    │
└──────────────────────────────────────────────────────────┘
```

## 2. Layers and dependency rules

| Layer | Namespace | May depend on | Must NOT depend on |
|---|---|---|---|
| Domain | `App\Intelligence\Domain` | PHP stdlib only | Symfony, Doctrine, Ports, Infrastructure, vendors |
| Ports | `App\Intelligence\Port` | Domain | Everything else |
| Application | `App\Intelligence\Application` | Domain, Port | Infrastructure, Connector, Controller, Command, `App\Service` |
| Infrastructure | `App\Intelligence\Infrastructure` | Domain, Port, Symfony, Doctrine | Controllers, Commands |
| Connector | `App\Intelligence\Connector\<Vendor>` | Domain, Port, Symfony HTTP client | Other connectors' internals |
| BPMN / Template helpers | `App\Intelligence\{Bpmn,Template}` | Domain | Vendors |
| Web adapter | `App\Controller` | Application, Symfony HTTP | `App\Command`, Symfony Console (test-enforced) |
| CLI adapter | `App\Command` | Application, Symfony Console | Business logic of its own (convention) |
| Wizard | `App\Wizard` | Symfony, own definitions | Intelligence internals beyond published services |
| **Legacy** | `App\Service`, `App\SignatureCheck`, `App\Dto`, `App\View` | (frozen) | — nothing new may depend on these |

Architecture tests are part of the public architecture contract. Do not weaken them
without an equivalent replacement.

Enforced by tests today:
- `tests/Architecture/ControllersDoNotUseCommandsTest.php` — controllers never reference
  console commands.
- `tests/Intelligence/Architecture/DomainDependencyTest.php` — domain purity.
- `tests/Intelligence/Architecture/ProcessEventRawLogTest.php` — raw event log invariants.

Planned guards (add when touching the respective areas):
- Intelligence core (`Domain`, `Application`, `Port`) contains no `Amagno`/vendor tokens.
- Intelligence namespaces never reference `App\Service\`.

## 3. Community Core and connector boundary

The Community Core contains the connector-independent process intelligence model:

- Canonical Events
- Process Reconstruction
- Context Snapshots
- Process Templates
- Findings
- Rule Evaluation

Connectors live at the boundary and adapt source systems into that model:

- Vendor Authentication
- Payload Normalization
- Metadata Mapping
- Port Implementations

Infrastructure provides framework and storage implementations such as Doctrine,
template loading, HMAC verification and repositories. It may implement Ports, but it
must not introduce source-system semantics into the Domain or Application layers.

## 4. Core data flow

### 4.1 Event intake

```
POST /api/intelligence/events
  → IntelligenceEventController (thin adapter)
      → SignatureVerifier (Port) ⇐ HmacSignatureVerifier (Infrastructure/Security)
      → payload validation (processKey, item reference, occurredAt, …)
      → IncomingEventIntake (Application)
          → normalization (dates, keys)  ⚠ vendor-format parsing should live in connectors
          → idempotency via stable external event key
          → persist IncomingEventEntity / ProcessEventEntity (Doctrine)
          → append raw payload (append-only guarantee)
```

Auth model: shared secret / HMAC-SHA256 over the raw body
(`X-Intelligence-Signature: sha256=<hex>`). **Rule: empty secret must fail closed** in
non-dev environments. Secrets are never accepted via query or form parameters.

Response contract: the endpoint **always returns HTTP 200**; acceptance or rejection is
signalled in the body (`accepted`, `error`). This is deliberate — see ADR-005. Even a
signature failure answers 200 with `accepted: false`, so a misconfigured source system
keeps running while the problem surfaces in APRIL's logs.

### 4.2 Reconstruction & checking

```
ProcessEventEntity (per item, per processKey)
  → DocumentTimelineProvider (Port) → timeline of observed steps
  → ProcessTemplateCheckService (Application)
      inputs:  ProcessTemplate (from YAML, versioned)
      checks:  expected/missing/unexpected steps, ordering, parallel groups,
               SLA rules, decision rules (context-aware), sign checks
      output:  ProcessTemplateCheckResult → ProcessDeviation[] ("findings")
  → JourneyTemplateCheckService — journey-level evaluation
  → CrossProcessRoutingChecker — routing between processes
  → ProcessGraphMetricsFactory / MermaidProcessGraphRenderer / Bpmn* — visual outputs
```

### 4.3 Context snapshots

`ContextSnapshotEntity` stores the item context **as it was at event time**, so decision
rules can be evaluated against historical truth ("was the right decision made with the
context available then?"). Snapshots are append-only; freshness repair is an explicit
command (`intelligence:context-snapshot:repair-freshness`), never implicit.

## 5. Persistence

- Canonical store: **Doctrine ORM** entities under
  `src/Intelligence/Infrastructure/Doctrine/Entity/`:
  `IncomingEventEntity`, `ProcessEventEntity`, `ProcessInstanceEntity`,
  `ProcessVersionEntity`, `ContextSnapshotEntity`, `VisibilityCheckResultEntity`.
- Databases: PostgreSQL 16 (Docker/CI target), SQLite (bare-metal dev/tests). Keep SQL
  DBAL-portable in the core.
- `JsonFileEventStore` (JSONL) and `InMemoryEventStore` implement the event-store Port
  for lightweight/dev/test scenarios; treat the JSONL store as a secondary adapter, not
  the source of truth.
- Migrations in `migrations/` are immutable once merged; schema evolution = new migration.
- The Doctrine mapping also registers `App\Entity` (currently empty) — legacy of the
  pre-hexagonal layout.

## 6. Process templates

- Location: `config/april/process-templates/*.yaml`; versioning via
  `ProcessVersionEntity` and the `intelligence:process:version:*` commands.
- Parsed by `ProcessTemplateArrayFactory` into the Domain model
  (`ProcessTemplate`, `ProcessTemplateStep`, `ProcessTemplateDecisionPoint/Rule`,
  `ProcessTemplateParallelGroup`, `ProcessTemplateSignCheck`, field mappings).
- Reference and examples: `docs/templates/reference.md`, `docs/templates/cookbook.md`.
- Templates are the human-readable contract of the expected process; keep them
  vendor-neutral. Field names used in decision rules come from context snapshots, not
  from source-system internals.

## 7. Presentation

- Server-rendered Twig under `templates/web/`, base layout `web/layout/base.html.twig`.
- AssetMapper + importmap (no Node build). Mermaid/Cytoscape render graphs client-side
  (`assets/template-graph.js`).
- Web auth: single env-backed user (`EnvUserProvider`, `APRIL_APP_USERNAME` +
  `APRIL_APP_PASSWORD_HASH`), form login. Demo login is created by
  `april:demo:user:create` (dev/test-guarded).
- i18n: `translations/messages.{de,en}.yaml` + glossary catalogs. Target state: all UI
  strings translated, locale switchable; English is the primary locale for the
  Community Core.
- Onboarding: the Wizard is a declarative onboarding engine. Definitions live in
  `config/april/wizards/` and are loaded/rendered by `src/Wizard/` (see
  `docs/architecture/onboarding-wizard.md`).

## 8. Architecture evolution

Known deviations from the target architecture:

| # | Deviation | Target |
|---|---|---|
| D1 | `src/Service/**` contains legacy connector/export code that predates the hexagonal core | Migrate active behavior behind Ports or move/remove it from the Community Core |
| D2 | `DateTimeNormalizer::parseAmagnoValue()` is still used in Domain/Application code | Vendor-neutral parsing; vendor formats normalized in connectors |
| D3 | Some console commands still contain too much behavior | Commands as thin adapters over Application services |
| D4 | Dual event stores (JSONL + Doctrine) can be confusing without explicit adapter choice | Doctrine canonical; JSONL as explicit dev/test adapter |
| D5 | Inline CSS remains in the base layout while `assets/styles/` exists | Token-based CSS under `assets/styles/` |
| D6 | Mixed `intelligence:` / `april:` command prefixes and connector-scoped parameters are not fully normalized | Unified `april:` commands, connector-scoped parameters |

When you fix a deviation, delete its row and, where possible, add an architecture test
that prevents regression.

## 9. Decision log (seed)

- **ADR-001 — Hexagonal Intelligence core.** Ports isolate the core from source systems;
  fakes make the full check pipeline testable without any live DMS.
- **ADR-002 — YAML templates as the process contract.** Human-readable, diffable,
  versioned; preferred over embedded BPMN engines. BPMN/Mermaid are *output* formats.
- **ADR-003 — Append-only event history with context snapshots.** Historical decisions
  are auditable against the context available at the time; repairs are explicit commands.
- **ADR-004 — No Node toolchain.** AssetMapper + importmap keeps the contributor setup at
  "PHP + Docker".
- **ADR-005 — Event intake never blocks the source process.** `/api/intelligence/events`
  always answers HTTP 200, signalling acceptance or rejection in the JSON body
  (`accepted: true|false` + `error` code). Rationale: source-system hooks can stall
  or retry-storm the *monitored* business process when a monitoring endpoint returns
  non-2xx. APRIL is an observer; observing must never degrade the observed process.
  Consequences: rejected events must be visible inside APRIL (structured `error`
  codes, log entries, ideally a dead-letter view), because the sender will not treat
  them as failures. This exception applies to the intake endpoint only — all other
  HTTP APIs use conventional status codes.

New significant decisions: add an `docs/adr/NNNN-title.md` (context, decision,
consequences) and reference it here.
