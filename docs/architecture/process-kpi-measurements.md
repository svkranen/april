# Process KPI measurement foundation

APRIL reconstructs measurement evidence from stored events. This is a read-only
projection, not a replacement for the persisted `ProcessInstance` or a workflow
engine. The foundation has no dashboard aggregations, SLA checks, reports, or persistence
changes. The [KPI aggregation and web view](process-kpi-board.md) builds on these results.

## Application API

`ProcessKpiMeasurements::forTemplate($template, $definition, $processVersion)`
returns a list of immutable `ProcessRunMeasurement` objects, including ineligible
and incomplete runs. The optional process version selector accepts a version or
`latest`; an unknown selection returns an empty list. Without a selector,
unassigned versions and all their diagnostics remain visible.

Each result exposes:

- process key, template revision, and measurement definition revision;
- observed `DocumentRef` values and technical instance IDs;
- start and completion timestamps, when determined;
- `running` or `completed`, independently of conformance and measurability;
- the existing `KpiEligibilityResult`, including the assigned `ProcessVersion`;
- E2E duration and structured non-measurability reasons;
- step visits and their durations, statuses, and source event keys.
- the explicitly mapped historical template version, when the process baseline
  provides one;
- a global chronological observed step sequence and typed factual transitions.

Example (the referenced steps must exist in the supplied process template):

```php
$definition = ProcessKpiDefinition::forTemplate(
    $template,
    version: '1',
    start: KpiEventMarker::at('intake', 'before'),
    completionGroups: [[KpiEventMarker::at('finished', 'after')]],
);
$runs = $measurements->forTemplate($template, $definition);
foreach ($runs as $run) {
    $seconds = $run->e2eDuration->seconds; // null when not measurable
    $reasons = $run->e2eDuration->reasons; // KpiMeasurementReason enum values
}
```

The definition is explicitly bound to a template key and revision; applying it
to a different revision fails. Its own version records the measurement contract.
No new YAML schema or automatic configuration inference is introduced. Callers
must supply the historically appropriate template and measurement definition;
automatic historical definition selection remains future work.

`ProcessVersion.validFrom` is the process-baseline boundary. Its optional
`templateVersion` is an explicit mapping to the Soll-template revision and is
never inferred from the baseline version string. A run keeps the mapping chosen
at its first valid start even when a later baseline becomes live. Existing
baselines without a mapping remain explicitly unmapped until associated with a
template revision.

New baselines can record the association explicitly with
`intelligence:process-version:create --template-version <revision>`.

## Existing concepts and the read boundary

The pipeline reuses `ProcessEventRecord`, `DocumentRef`, `ProcessTemplate`,
`ProcessVersion`, and `TimelineKpiEligibilityResolver`. Persisted instances are
technical associations: their identity includes document and template versions,
and their manager does not currently derive completion. Their status and times
are consequently not used as authoritative KPI boundaries.

The existing event-list adapter truncates results and lacks phase/source fields;
the document timeline adapter requires UUIDs. Neither can provide complete KPI
input. The small `ProcessEventReader` port therefore reads complete history for a
process, including items without UUIDs. `DoctrineEventStore` implements it using
its existing entity-to-domain mapping and a streamed query with the optional
instance association joined. No live connector is accessed.

Reconstruction currently retains that process history in memory. The query does
not truncate by date, document version, or process version. This deliberately
preserves starts and pairs across reporting periods. Bounded reporting queries
and incremental projections require a separate design if actual scale demands
it. The API is an internal application service, not a new HTTP endpoint.

## Run identity and segmentation

Events are grouped by process, source system, and UUID (when present), otherwise
by source system and external item ID. Document versions are retained as
observations rather than assumed to represent new business runs. Events with and
without a UUID are not heuristically merged. Technical instance IDs are retained
as provenance, not treated as business correlation IDs.

A strictly later explicit start following a complete end-marker group establishes
a new run for the same item. A repeated start before completion does not split a
run: the evidence is ambiguous. Events after completion without a new start also
remain ambiguous. A change of document version or conflicting technical instance
IDs within a segment disables duration measurement, since no explicit business
correlation rule currently resolves that change.

Simultaneous events stay in one batch. IDs and receipt times cannot decide which
run owns them. Run keys are deterministic hashes of item identity, process, and
first event key; they are projection identifiers, not durable business IDs. Late
history can change segmentation, keys, and measured values.

A segment marked `ambiguous_run` is evidence, not a reliably countable business
run. Future aggregators must inspect measurability reasons before counting such
segments as reconstructed runs. No concurrent runs for one item are guessed from
incomplete data. An explicit business run correlation contract remains necessary
for that case.

During this same reconstruction pass, each run receives a global chronological
sequence of observed step visits. Consecutive events for one step become one
observation; a later occurrence after another step remains separate. Typed
observed transitions are adjacent pairs in that sequence and are factual only;
they do not perform Soll/Ist classification.

## Start, completion, and eligibility

Start is an explicit step/phase marker. Each completion group is an AND of
markers; groups are alternatives (OR). Completion occurs at the first fully
observed group's last required marker. A group referencing a branch of a template
parallel group must include all its required branches. A separate, authoritative
process completion/join marker may be used instead.

Only the explicit measurement markers determine completion. Optional steps and
conformance findings do not block it. Missing start still permits `completed`,
with no E2E duration. Missing end means `running`, not a duration up to the current
clock. No abort inference is provided.

The existing eligibility resolver runs per reconstructed segment with all process
version boundaries. Its baseline, missing-version, mid-process entry, and
cross-version exclusions also disable step duration measurement for that segment.
Missing E2E markers alone do not disable otherwise eligible, unambiguous step
pairs. If the expected initial step shares the earliest timestamp with another
step, its observed presence establishes the initial-step eligibility; technical
ordering must not turn that observation into a mid-process entry.

The legacy `KpiRelevantTimelineFilter` now retains subsequent version boundaries
when selecting an older version. Timelines starting in later versions are excluded
as `process_version_not_selected`. The existing selected-baseline behavior for
earlier starts is preserved. The new API selects runs only after reconstruction
and eligibility, retaining diagnostics for ineligible runs assigned to the
selected version.

## Step visits and durations

Primary step duration is exclusively `after.occurredAt - before.occurredAt` within
one segment and step. Distinct steps can overlap. An alternating sequence such as
`A_before, A_after, B_before, B_after, A_before, A_after` retains two visits of A.
There is no direct-repeat collapse and no fallback interval between steps.

A before-only visit is open; an after-only visit is completed but lacks a duration.
Distinct external event keys are never deduplicated by timestamp or payload.
Repeated copies of the same external event key are ignored, using the event
store's existing globally unique, idempotent key contract.

Multiple before/after candidates quarantine that step's evidence in the segment:
no FIFO, LIFO, receipt-time, or ID-based pairing is inferred. The result is an
`ambiguous` fragment with `visitNumber = null` and all event keys, not an invented
number of visits. This conservative policy can exclude otherwise plausible pairs
on the same step. A unique before/after at the same timestamp measures zero;
multiple simultaneous candidates remain ambiguous. Unknown phases are retained
as non-measurable evidence.

Each `StepVisitMeasurement` also exposes `measurementPointCoverage` as the fixed
shape `['before' => bool, 'after' => bool]`. These flags describe actual observed
phases, independently of unique timestamps, duration measurability, or KPI
eligibility. Both flags can be true for an ambiguous fragment with no measurable
duration. An unknown-phase-only fragment has both flags false. No event or visit
is synthesized for a template step that has never been observed.

Consumers can group these results by `stepKey` and inspect or combine the flags
without loading events again. Repeated visits keep their individual coverage.
An aggregate with both flags true does not establish a measurable pair: its two
phases might come from different visits. To display unobserved template steps,
consumers can join the result with the template step list; an absent result means
no observed visit, not a synthetic visit with zero duration.

`KpiDuration::seconds` is nullable and uses elapsed seconds, including subsecond
precision from supplied event timestamps. `isMeasurable()` distinguishes a real
zero from missing data. Reasons are `KpiMeasurementReason` enum values, including
existing eligibility codes. Multiple applicable reasons are retained. No missing
values are converted to zero and negative intervals are never clamped to zero.

Only `occurredAt` determines business time. Every call reads current stored
history, so late arrivals can correct previous measurements. No historical event
or snapshot is changed, and no cache needs invalidation.

## Verification and remaining decisions

Domain and application tests cover linear and repeated visits, incomplete phases,
ambiguous pairings, duplicate keys, late events, month boundaries, parallel
completion, version exclusions, and repeated runs. A real in-memory SQLite test
uses the Doctrine reader and Application API and verifies that measuring does not
change stored event rows.

Before configuring a concrete process, establish what its hooks actually mean:
`before`/`after` may describe a technical action rather than time spent in a
business state. Also establish authoritative completion markers, document-version
continuity, business run correlation for concurrency/restarts, and historical
measurement definition selection. No working-time calendar or SLA semantics are
implied by elapsed durations.
