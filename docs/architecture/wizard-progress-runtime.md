# Wizard Progress Runtime

APRIL wizard definitions are declarative learning flows. The current runtime
boundary prepares progress tracking without introducing persistence, sessions,
buttons or automatic completion.

## Purpose

`WizardProgressStoreInterface` is the port for reading wizard progress. CLI and
Web UI should use this same interface so both surfaces describe the same
read-only state.

The store is intentionally separate from wizard definitions:

- definitions describe the learning path
- the view model combines definitions, checks and progress
- the store reports what is known about progress

## Current Implementation

`NullWizardProgressStore` is the current Community-Core implementation. It does
not persist anything and always returns:

- `status`: `unknown`
- `message`: `Wizard progress is not persisted yet.`

This keeps the UI honest while making the later runtime boundary explicit.

## Persistent Store

A later persistent implementation may store progress by:

- `userId`
- `wizardKey`
- `stepKey`

`wizardKey` identifies the guided tour. `stepKey` identifies an optional step.
A `null` step key can represent wizard-level progress.

Potential status values:

- `unknown`: no reliable progress state is available
- `pending`: the wizard or step is known but not completed
- `completed`: the wizard or step has been completed
- `skipped`: the wizard or step was intentionally skipped

The persistent implementation should remain connector-independent and should not
depend on DMS, Amagno or external system identifiers.

## Completion Rules

Completion rules may later evaluate whether a step can be marked as completed.
They may read state from the wizard runtime and from APRIL read models where
appropriate.

Completion rules must not trigger side effects such as:

- mutating business processes
- loading fixtures
- creating process events
- changing navigation as a completion side effect
- calling connector-specific systems

Navigation can help a user reach a page, but visiting a route should only become
progress if an explicit runtime records that read-only observation.

## Boundaries

Wizard progress is a learning/runtime concern. It must not alter APRIL process
intelligence data.

The runtime must not:

- mutate business process state
- execute demo fixtures
- depend on DMS or Amagno concepts
- treat connector state as progress storage
- infer completion from navigation without an explicit progress mechanism

The Community-Core default should remain safe and transparent: no progress is
persisted unless a concrete store is configured.
