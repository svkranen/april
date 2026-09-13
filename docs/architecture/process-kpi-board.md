# Process KPI aggregation and web view

The protected route `/app/templates/{key}/kpi` uses the existing `ROLE_USER`
access rule for `/app`. Template detail pages link to it for process templates.
Journey aggregation, exports, SLA checks, alerts, and drilldowns are out of scope.

## Single application entry point

`ProcessKpiPageProvider::build(key, period, version)` loads the template and its
explicit measurement definition, calls `ProcessKpiMeasurements` once, and passes
the resulting projections to `ProcessKpiAggregator`. The controller only validates
GET parameters and renders the page. Twig formats the supplied values; it performs
no reconstruction or KPI arithmetic. Statistics and grouping live in Application,
not Domain. See [the reconstruction contract](process-kpi-measurements.md).

`ProcessKpiSummary` and `StepKpiSummary` expose counts, `KpiStatistics`, coverage
counts, and quality diagnostics for further consumers. `StepKpiAggregator` shares
the statistics implementation with the process aggregate.

## Time and selection

`from` and `to` are strict `YYYY-MM-DD` calendar dates in the configured reporting timezone (currently `Europe/Berlin`). Both selected dates are inclusive. Local day boundaries are then converted to UTC, so DST days may span 23 or 25 UTC hours; internally the interval is `[local from 00:00, local day-after-to 00:00)` represented by UTC instants.
The default is the current reporting-timezone month through today. Reversed, invalid, or periods
longer than 3660 days return HTTP 400. Array-valued query parameters are rejected
by the request boundary. Filters are read-only GET parameters; no write endpoint
is introduced.

- Started: a known start timestamp is inside the period.
- Completed: a known completion timestamp is inside the period.
- Open: observed by the cutoff, with no completion before the exclusive upper
  boundary. A partially observed run can be open without a known start marker.
- E2E: full durations of measurable runs completed in the period, including starts
  before the period. Completed runs lacking measurable E2E remain in the completion
  count and in the quality denominator, but not in the duration sample.

The existing measurements API now accepts an optional `observedBefore` cutoff.
It retains all earlier history and excludes only events whose `occurredAt` is at
or after the cutoff before invoking the existing reconstructor. There is no lower
history bound. This also ensures step coverage and eligibility reflect the
historical cutoff rather than leaking future completion markers. Receipt time
is never substituted; late arrivals can retrospectively correct earlier periods.
This is retrospective process history, not a snapshot of what APRIL knew then.

Aggregators accept measurements reconstructed for that cutoff. They must not be
fed a different snapshot if historically correct coverage is required. No second
event interpretation or reconstruction happens in aggregation.

The optional `version` accepts a specific process version or `latest`. Empty means
all assigned versions plus unassigned, non-measurable evidence. Unknown selections
return an empty result. Existing eligibility excludes invalid duration samples;
version selection happens only after checking all temporal version boundaries.
There is no new version-selection model or automatic historical definition lookup.

## Step cohort and coverage

Each step row contains the union of visits completed within the period and visits
observed but not completed by its end. An open visit may have begun before `from`.
Visits completed before `from` are excluded. Duration statistics include only
measurable completed visits in this cohort, always using the whole visit duration.
An unclosed visit can remain incomplete even if a separate process completion
marker was observed; conformance remains independent.

`StepVisitMeasurement` now includes `firstObservedAt` and `lastObservedAt` as
reconstruction evidence. This dates diagnostic fragments without asking the
aggregator to re-read events or infer timestamps from nullable endpoints.

Coverage is calculated only from `measurementPointCoverage`, per countable visit:

- `before`, `after`: visits with the corresponding observed point;
- `both`, `beforeOnly`, `afterOnly`, `neither`: mutually exclusive categories;
- `mixedCoverage`: more than one category is represented.

The web table displays each present category and its count, with a text label
beside `|────|`, `|────`, `────|`, or `────`. These symbols are hidden from screen
readers in favor of their labels. A mixed row never displays a single combined
symbol that would imply every visit has both points. Coverage is not proof of a
measurable duration. Template steps without visits still appear with zero counts
and unavailable statistics; no visit is synthesized.

Ambiguous run segments are excluded from all ordinary run and visit counts and
shown as separate quality evidence. Within otherwise countable runs, ambiguous
step fragments are also excluded from visit and coverage counts. Fragments whose
last observation falls inside the period are reported separately with their
existing typed reasons. They must not be interpreted as an exact number of
visits. The quality display includes completed runs without E2E, visits without
durations, and reason counts. Reasons can overlap and must not be summed as a
unique-case count.

## Statistics and presentation

`KpiStatistics::fromSeconds()` accepts only measured elapsed seconds:

- mean: sum divided by sample count;
- median: middle sorted value for odd samples, mean of the two middle values for
  even samples;
- P90: nearest rank, `sorted[ceil(0.9 * n) - 1]`, without interpolation;
- maximum: largest observed value.

An empty sample returns `null` for all four durations. A single observation
returns that observation for all four. A real measured zero is retained. The page
shows whole seconds, or `< 1 s` for a positive subsecond duration, and explicitly
shows "Not available" for null. Sample counts remain visible; P90 of a small
sample is an observed value, often its maximum, not an interpolated prediction.

Completion trends use daily reporting-timezone buckets for up to 62 days and calendar-month buckets thereafter. Buckets without completions contain zero; boundary months
include only selected dates. The simple table requires no chart library. Styling
uses AssetMapper via the `template-kpi` importmap entry point.

## Explicit measurement configuration

`ConfiguredProcessKpiDefinitionProvider` implements a small definition port. It
reads the `april.kpi_definitions` service parameter and calls the existing domain
factory; no alternate inference engine or template parser is added. An entry
must match the loaded template revision exactly. Missing/mismatched configuration
produces an explanatory page without fabricated metrics. Invalid configured
markers fail validation rather than silently selecting defaults.

Example:

```yaml
parameters:
    april.kpi_definitions:
        incident-management:
            template_version: '1'
            version: '1'
            start: { step: incident_received, phase: after }
            completion_groups:
                - [{ step: close_incident, phase: after }]
```

The bundled incident demo uses after-only events. This explicit contract measures
receipt-to-close elapsed time, and does not invent before points for step times.
Its current unquoted YAML template version `1.0` is normalized by the existing
parser to the string `'1'`; process baseline versions are a separate concept.
Eligibility baselines must still exist to obtain measurable durations. No baseline
or credential is automatically created by the KPI page.

Before enabling another process, configure its actual hook semantics and marker
contract. Automatic historical measurement-definition selection and continuity
across document-version changes remain open questions from the foundation.

## Invoice KPI demo

The development and test command `april:demo:seed-invoice-kpi` resets and seeds
the connector-neutral `invoice-receipt` process. It creates 20 deterministic
items and 260 events covering all four departments, low and high amount bands,
an open item, incomplete E2E evidence, repeated visits, late receipt, and local
day/month boundaries. The configured markers are `invoice_received before` and
`payment before`; the management approval step is an optional branch in the
template, so the KPI definition does not require it for low-value invoices.
The command refuses other environments unless `--force` is supplied. Re-running
it replaces only this process key and recreates the same event keys.

## Performance and verification

One page request makes one full-history reader call and one reconstruction. The
existing Doctrine reader joins the optional instance association, with no query per
row. There are only a fixed number of version lookups, independent of row counts.
The original in-memory reconstruction remains; no cache, materialized view,
persistence migration, or additional dependency has been introduced.

Tests cover statistics, empty samples, period boundaries, earlier starts, open
inventory before later completions, partial measurement, repeated and mixed-coverage
visits, and ambiguous evidence. Web tests exercise real page services and templates
with port fakes, including authentication, 404, filters, empty history, missing
configuration, unavailable values, mixed coverage, and one history read per request.
The existing Doctrine deprecation categories still occur when booting web tests;
this change does not suppress them or relax test assertions.

## Implementation file inventory

The aggregation/web increment adds or updates these 31 files on top of the
existing, uncommitted measurement foundation:

```text
assets/styles/kpi.css
assets/template-kpi.js
config/services.yaml
config/services_test.yaml
docs/architecture/process-kpi-board.md
docs/architecture/process-kpi-measurements.md
importmap.php
src/Controller/App/TemplateKpiController.php
src/Intelligence/Application/KpiPeriod.php
src/Intelligence/Application/KpiStatistics.php
src/Intelligence/Application/ProcessKpiAggregator.php
src/Intelligence/Application/ProcessKpiMeasurements.php
src/Intelligence/Application/ProcessKpiPage.php
src/Intelligence/Application/ProcessKpiPageProvider.php
src/Intelligence/Application/ProcessKpiSummary.php
src/Intelligence/Application/StepKpiAggregator.php
src/Intelligence/Application/StepKpiSummary.php
src/Intelligence/Domain/StepVisitMeasurement.php
src/Intelligence/Domain/StepVisitReconstructor.php
src/Intelligence/Infrastructure/Template/ConfiguredProcessKpiDefinitionProvider.php
src/Intelligence/Port/ProcessKpiDefinitionProvider.php
templates/web/template/_kpi_duration.html.twig
templates/web/template/kpi.html.twig
templates/web/template/kpi_error.html.twig
templates/web/template/show.html.twig
tests/Controller/App/TemplateKpiControllerTest.php
tests/Intelligence/Application/KpiPeriodTest.php
tests/Intelligence/Application/KpiStatisticsTest.php
tests/Intelligence/Application/ProcessKpiAggregatorTest.php
translations/messages.de.yaml
translations/messages.en.yaml
```
