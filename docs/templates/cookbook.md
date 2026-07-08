# APRIL Template DSL Cookbook

Dieses Cookbook zeigt kleine, aktuell unterstützte Muster. Es ergänzt die [Template DSL Reference](./reference.md).

## Linearer Prozess

```yaml
key: incident_triage
version: 1.0

steps:
  - key: incident_received
  - key: classify_incident
  - key: close_incident

transitions:
  - from: incident_received
    to: classify_incident
  - from: classify_incident
    to: close_incident
```

Ohne `scope` gilt `scope: process`.

## Bedingter Prozesspfad

```yaml
key: incident_routing
version: 1.0

steps:
  - key: classify_incident
  - key: resolve_first_level
  - key: escalate_to_saas_provider
  - key: close_incident

field_mapping:
  severity:
    source: event_context
    value_type: string
    stability: snapshot_required

decision_points:
  - key: route_by_severity
    after: classify_incident
    required_fields:
      - severity
    rules:
      - when:
          severity:
            in: [high, critical]
        expect_next: escalate_to_saas_provider
      - else:
          expect_next: resolve_first_level
```

## Optionaler Journey-Step mit `when`

```yaml
key: incident_journey
version: 1.0
scope: journey

steps:
  - key: import
    type: process
    process_key: incident_intake
    required: true
    when:
      imported: true

  - key: triage
    type: process
    process_key: incident-management
    required: true

transitions:
  - from: import
    to: triage
```

Wenn `imported` nicht zu `true` passt, wird `import` als `CONDITION_NOT_APPLICABLE` bewertet.

## Generischer Intake, fachlicher Prozess, optionales Follow-up

```yaml
key: incident_journey
version: 1.0
scope: journey

steps:
  - key: intake
    type: process
    process_key: incident_intake
    required: true

  - key: triage
    type: process
    process_key: incident-management
    required: true

  - key: follow_up
    type: process
    process_key: specialist_follow_up
    required: true
    when:
      category: business_process

transitions:
  - from: intake
    to: triage
  - from: triage
    to: follow_up
```

Ein Detailtemplate fuer `incident_intake`, `incident-management` oder `specialist_follow_up` ist optional. Die Journey-Pruefung blockiert nicht, wenn es fehlt.

## Cross-Process-Routing

```yaml
key: incident_intake
version: 1.0

steps:
  - key: intake_completed

cross_process_routing:
  - key: route_to_security_review
    after_step: intake_completed
    when:
      category: security
    expected_process: security_review
```

Das prueft read-only, ob nach dem Source-Step ein Zielprozess mit `processKey = security_review` fuer dasselbe Item existiert.

## Detailtemplate als optionaler Drilldown

Journey:

```yaml
key: incident_journey
version: 1.0
scope: journey

steps:
  - key: triage
    type: process
    process_key: incident-management
    required: true
```

Optionales Detailtemplate:

```yaml
key: incident-management
version: 1.0

steps:
  - key: incident_received
  - key: classify_incident
  - key: close_incident

transitions:
  - from: incident_received
    to: classify_incident
  - from: classify_incident
    to: close_incident
```

Die Journey prueft nur, ob `incident-management` existiert. Die interne Pruefung des Detailtemplates ist ein separater Check.
