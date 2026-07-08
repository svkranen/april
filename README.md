# APRIL

**APRIL turns raw business events into process insight. Connector-independent. Event-driven. Open Core.**

It reconstructs what actually happened, compares reality with your expected process, and explains where and why they diverge.

APRIL does not run your workflows. It helps you understand them.

---

## The Problem

Most real processes are not contained in one workflow engine.

An incident may start in a support tool, touch a SaaS platform, require a security review, and close somewhere else. An order may move through ERP, email, approvals and custom scripts. Each system knows a fragment. Nobody owns the full journey.

That makes simple questions surprisingly hard:

- Which path did this item actually take?
- Was the right decision made with the context available at the time?
- Which cases followed the process, and which only looked complete?
- Where are process deviations hiding?

APRIL was built to close that gap.

---

## What APRIL Does

APRIL ingests events, rebuilds process instances, stores historical context snapshots, and checks the observed journey against versioned process templates.

Events
   │
   ▼
APRIL Core
   │
   ├── Process Reconstruction
   ├── Context Snapshots
   ├── Rule Evaluation
   ├── Template Checks
   └── Event Store
   │
   ▼
Items
Journeys
Findings
Documentation
Mermaid

The result is a human-readable view of process reality:

- **Items**: the process objects APRIL reconstructed from events
- **Journeys**: the timeline of what happened to an item
- **Findings**: deviations, warnings and rule violations
- **Process Graphs**: Mermaid views of the expected process
- **Templates**: explicit, inspectable definitions of the process you expected

The first demo shows a security incident that looks complete, but was routed to first-level resolution even though its context required a security review. APRIL flags that as a **Decision Rule Violation**.

---

## Why It Is Different

APRIL is not another workflow engine.

It is a process intelligence layer for systems you already have.

- It starts from observed events, not from an orchestration runtime.
- It keeps the core connector-independent.
- It records context snapshots so historical decisions can be checked later.
- It treats process definitions as readable templates.
- It explains findings in terms humans can inspect.

**Connectors can adapt APRIL to DMS, ERP, SaaS, ticketing or custom systems.**
**The Community Core stays focused on events, items, journeys and findings.**

---

## Quickstart

Run APRIL locally with Docker, install the public Composer dependencies, load the Incident Management demo, and open it in the browser.

```bash
git clone <repository-url>
cd april

cp .env.example .env

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console april:demo:user:create
docker compose exec app php bin/console april:fixtures:load --reset
```

Open:

```text
http://localhost:8080/app
```

Login:

```text
User: admin@example.local
Password: april
```

No Node, Vite or external connector is needed for the demo. The local stack uses FrankenPHP and PostgreSQL 16.

---

## First Thing To Explore

After login, APRIL shows a first-insight card for the Incident Management demo.

Start with:

- **Guided tours**: open the read-only First Insight walkthrough.
- **Items & Findings**: see the imported items and calculated findings.
- **Deviation Journey**: inspect the incident that was routed incorrectly.
- **Process Graph**: compare the expected process with observed findings.
- **Template Details**: read the process definition APRIL checks against.

The fastest "aha" is the Deviation Journey: a closed incident is not automatically a correct incident.

---

## Architecture

APRIL keeps source systems at the edge.

```text
Source systems
  SaaS, DMS, ERP, ticketing, custom apps
        |
        v
Events + context
        |
        v
+-----------------------------+
|          APRIL Core         |
|                             |
|  Event Store                |
|  Process Reconstruction     |
|  Context Snapshots          |
|  Template Checks            |
|  Rule Evaluation            |
+-----------------------------+
        |
        v
Timelines / Findings / Documentation / Mermaid
```

Optional connectors normalize incoming events or enrich context. The core model remains portable.

---

## Key Features

- Process reconstruction
- Item journeys
- Findings
- Decision rule violations
- Context snapshots
- Mermaid
- Documentation
- Versioned templates
- Connector-independent Community Core

---

## Project Philosophy

APRIL is built around a simple idea:

> Your systems already produce the truth. APRIL helps you read it.

It should be:

- understandable before it is exhaustive
- explicit before it is magical
- connector-independent before it is platform-specific
- useful to developers, architects and process owners

---

## Experience APRIL in minutes

Clone the repository.

Start Docker.

Load the demo.

Open APRIL.

Explore:

✓ Items

✓ Journey

✓ Findings

✓ Decision Rule Violation

---

## Roadmap

**Community Core**

- Stable first-time experience
- Neutral demo scenarios
- Better documentation for templates, events and findings
- More regression-ready demo fixtures

**Connector ecosystem**

- Clear adapter contracts
- Optional source-system connectors
- Connector-specific naming without leaking into the core model

**Enterprise**

- Operational hardening
- Governance and access models
- Larger-scale monitoring and reporting

---

## Contributing

APRIL is being prepared as an open-source Community Core.

Good contributions keep the core connector-independent, avoid secrets, and prefer small, testable changes. For larger ideas, open an issue or discussion first and describe the process-intelligence use case.

---

## License

The current package metadata declares this repository as `proprietary`.
The public open-source license is still to be finalized before the first public GitHub release.

Understand how your processes actually run — then improve them.
