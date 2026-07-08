# APRIL Wizard Definition MVP

## Goal

APRIL already describes expected business processes declaratively through YAML Process Templates. The onboarding wizard should follow the same idea: describe the learning path declaratively, then let CLI and Web surfaces render or execute it.

This document defines the first Wizard Definition MVP. It is not an implementation plan for controllers, Twig pages or JavaScript. It defines the shared model that a future Wizard Engine can consume.

## Format Proposal

YAML is the preferred MVP format because it is already familiar in APRIL through Process Templates:

- human-readable
- versionable
- reviewable in pull requests
- usable by CLI and Web
- close enough to Process Templates without reusing their domain model

A future file location could be:

```text
config/april/wizards/*.yaml
```

No such loader is implemented in this step.

## Model Shape

```yaml
key: first-insight
version: 1.0
name: "First Insight"
description: "Guide new users through the Incident Management demo."

audience:
  - developer
  - architect
  - process_owner

scenario:
  key: incident-management
  template: incident-management

prerequisites:
  - key: app_available
    type: route
    route: app_templates_index
    required: true
  - key: demo_template_available
    type: process_template
    process_key: incident-management
    required: true
  - key: demo_fixtures_loaded
    type: fixture_scenario
    scenario: incident-management
    required: true

steps:
  - key: welcome
    title: "Welcome to APRIL"
    goal: "Understand what APRIL reconstructed from the demo events."
    body: "APRIL turns imported events into items, journeys and findings."
    concepts:
      - item
      - journey
      - finding
      - process_template
    actions:
      - key: continue
        type: continue
        label: "Start"

  - key: open_items
    title: "Open Items"
    goal: "See the process objects APRIL reconstructed from events."
    body: "The Incident Management demo contains multiple items, including one intentionally misrouted incident."
    links:
      - key: items_with_findings
        label: "Items & Findings"
        route: app_templates_documents
        params:
          key: incident-management
          withFindings: 1
    completion:
      type: visit_route
      route: app_templates_documents
      params:
        key: incident-management

  - key: open_journey
    title: "Open Journey"
    goal: "Inspect the timeline of the misrouted incident."
    body: "The item looks complete, but the journey shows the actual path APRIL reconstructed."
    links:
      - key: deviation_journey
        label: "Deviation Journey"
        route: app_intelligence_documents_show
        params:
          documentUuid: "10000000-0000-4000-8000-000000000004"
    expected_insight:
      type: text
      value: "A closed item can still be routed incorrectly."
    completion:
      type: visit_route
      route: app_intelligence_documents_show
      params:
        documentUuid: "10000000-0000-4000-8000-000000000004"

  - key: open_findings
    title: "Open Findings"
    goal: "Find the Decision Rule Violation."
    body: "The context requires a security review, but the observed next step is first-level resolution."
    links:
      - key: filtered_findings
        label: "Items with Findings"
        route: app_templates_documents
        params:
          key: incident-management
          withFindings: 1
    expects:
      - type: finding
        severity: deviation
        finding_type: decision_rule_violation
    completion:
      type: finding_present
      process_key: incident-management
      finding_type: decision_rule_violation

  - key: open_process_graph
    title: "Open Process Graph"
    goal: "Compare expected routing with observed findings."
    body: "The graph shows the expected process; findings explain where the observed journey diverged."
    links:
      - key: graph_with_findings
        label: "Process Graph"
        route: app_templates_graph
        params:
          key: incident-management
          withFindings: 1
    completion:
      type: visit_route
      route: app_templates_graph
      params:
        key: incident-management

completion:
  type: all_steps_completed
  message: "You have seen how APRIL reconstructs a journey and detects a decision rule violation."

references:
  - label: "Template reference"
    path: docs/templates/reference.md
  - label: "Context history"
    path: docs/intelligence/context-history.md
  - label: "Event API"
    path: docs/intelligence/event-api.md
```

## First Wizard: `first-insight`

The first wizard should be intentionally small. Its job is not to teach all of APRIL. Its job is to create the first useful mental model:

```text
Events -> Items -> Journeys -> Findings -> Process Graph
```

Recommended steps:

1. `welcome`
   - Explain APRIL in one sentence.
   - Introduce Item, Journey, Finding and Process Template.

2. `open_items`
   - Link to the Incident Management item list with findings enabled.
   - Show that APRIL reconstructed items from imported events.

3. `open_journey`
   - Link directly to the known deviation item.
   - Show the reconstructed timeline.

4. `open_findings`
   - Point to the Decision Rule Violation.
   - Explain that completion is not the same as correctness.

5. `open_process_graph`
   - Link to the process graph with findings enabled.
   - Connect the observed deviation back to the expected template.

This is deliberately close to the current First Insight Card. The wizard definition should later let CLI and Web present the same path consistently.

## Declarative Parts

The YAML definition should own:

- wizard identity: `key`, `version`, `name`, `description`
- target audience
- scenario references
- prerequisites as named checks
- ordered steps
- titles and explanatory text
- concepts introduced by each step
- links to existing APRIL routes or documentation
- expected insights
- completion criteria
- documentation references

These fields are data. They can be reviewed without running APRIL and rendered by multiple clients.

## Future Wizard Engine Parts

A future Wizard Engine would be responsible for behavior:

- loading and validating wizard YAML
- resolving route names to URLs
- checking prerequisites
- rendering steps in CLI or Web
- tracking visited steps or completed checks
- mapping `completion.type` values to executable checks
- presenting warnings without mutating process data
- optionally persisting user progress
- handling localization through translation keys

The engine must not duplicate APRIL business logic. It should call existing services for template lookup, fixture status, item lists, findings and graph generation.

## MVP Constraints

For the first implementation later:

- No connector credentials.
- No productive data mutations.
- No separate business rules inside the wizard.
- No new terminology outside the APRIL glossary.
- No Web-only concepts in the shared definition.
- CLI and Web may render differently, but must consume the same definition.

## Relation To Process Templates

Process Templates describe the expected business process.

Wizard Definitions describe the expected learning process.

Both should be:

- declarative
- versioned
- human-readable
- stable enough for documentation and tests

They should not share one schema. A Process Template models steps, transitions and rules in the domain. A Wizard Definition models guidance, links and completion criteria for users.
