# Doctrine Persistence

Doctrine is used as the persistent adapter for the Intelligence event store,
process instances, and context snapshots.

For a fresh checkout where Doctrine is not installed yet, install the required
packages with:

```bash
composer require symfony/orm-pack
composer require --dev symfony/maker-bundle doctrine/doctrine-migrations-bundle
```

This repository currently uses the Doctrine packages directly in `composer.json`
and maps the Intelligence infrastructure entities via
`config/packages/doctrine.yaml`.

## Intelligence Tables

The current process intelligence timeline is based on these entities:

- `ProcessEventEntity` / `intelligence_process_event`: append-only process events.
- `ProcessInstanceEntity` / `intelligence_process_instance`: document-version scoped process instances.
- `ContextSnapshotEntity` / `intelligence_context_snapshot`: loaded context attributes for historical rule evaluation.
- `IncomingEventEntity` / `intelligence_incoming_event`: asynchronous webhook intake queue.

Most analysis commands use `ProcessEventEntity` as their timeline source. In
particular, `intelligence:template:check-process` and live metric rendering in
`intelligence:template:export-diagram --view=flow|dwell|deviations|combined`
read documents through `ProcessDocumentUuidProvider` and
`DocumentTimelineProvider`.

## Context Snapshots and UTC

All persisted date/time values are stored as Doctrine `datetime_immutable`
without a database timezone offset, but semantically they are UTC values.

Relevant fields include:

- `receivedAt`
- `occurredAt` / `eventOccurredAt`
- `loadedAt`
- `processedAt`
- `createdAt`
- `updatedAt`

`ContextSnapshotEntity` stores:

- `occurred_at`: event time in UTC
- `loaded_at`: context load time in UTC
- `freshness_seconds`: `loaded_at.timestamp - occurred_at.timestamp`
- `is_fresh_for_decision_check`: true only if freshness is non-negative and
  inside the template snapshot policy window

Decision checks use snapshots for mutable or `snapshot_required` fields. Stale,
missing, or time-skewed context is reported as an uncertain/uncheckable context
condition instead of a false process deviation.

## Dev/Test Sample Data

Local process-analysis fixtures can be loaded without IncomingEvents or Amagno:

```bash
bin/console intelligence:sample-data:load-ai-rechnungen --purge
```

The command writes sample documents `900001` through `900008` directly into
`ProcessEventEntity`, `ProcessInstanceEntity`, and `ContextSnapshotEntity` for
`processKey=ai-rechnungen`.

Use this for local heatmap, graph, and projection tests. The command is intended
for `dev`/`test`; outside those environments it requires explicit confirmation.
