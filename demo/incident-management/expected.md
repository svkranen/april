# Expected Results

Diese Datei beschreibt die erwarteten Ergebnisse der Incident-Management-Demo-Fixtures. Sie ist eine fachliche Referenz fuer spaetere Demo-Loader, Wizard-Schritte und Regressionstests.

## events-normal.json

Erwarteter Prozesspfad:

```text
incident_received -> classify_incident -> resolve_first_level -> close_incident
```

Erwartetes Routing:

- `severity=low` routet nach `resolve_first_level`.

Erwartete Findings:

- Keine Decision Rule Violation.
- Keine fehlenden Pflichtschritte.
- Prozess endet erfolgreich in `close_incident`.

Besonderheiten:

- Basisszenario fuer einen einfachen, unkritischen Incident.
- Geeignet als erster Quickstart- und Wizard-Durchlauf.

## events-business.json

Erwarteter Prozesspfad:

```text
incident_received -> classify_incident -> route_to_specialist_group -> close_incident
```

Erwartetes Routing:

- `category=business_process` routet nach `route_to_specialist_group`.

Erwartete Findings:

- Keine Decision Rule Violation.
- Keine fehlenden Pflichtschritte.
- Prozess endet erfolgreich in `close_incident`.

Besonderheiten:

- Zeigt fachliches Routing ohne Security- oder Provider-Eskalation.

## events-security.json

Erwarteter Prozesspfad:

```text
incident_received -> classify_incident -> trigger_security_review -> close_incident
```

Erwartetes Routing:

- `data_exposure=true` routet nach `trigger_security_review`.
- `category=security` bestaetigt denselben Security-Pfad.

Erwartete Findings:

- Keine Decision Rule Violation.
- Keine fehlenden Pflichtschritte.
- Prozess endet erfolgreich in `close_incident`.

Besonderheiten:

- Zeigt priorisiertes Security-Routing.
- Der Context enthaelt bewusst zwei Signale fuer denselben Zielpfad.

## events-deviation.json

Erwarteter Prozesspfad laut Template und Context:

```text
incident_received -> classify_incident -> trigger_security_review -> close_incident
```

Tatsaechlicher Prozesspfad in der Fixture:

```text
incident_received -> classify_incident -> resolve_first_level -> close_incident
```

Erwartetes Routing:

- `data_exposure=true` und `category=security` erwarten `trigger_security_review`.
- Die Timeline routet absichtlich nach `resolve_first_level`.

Erwartete Findings:

- Decision Rule Violation nach `classify_incident`.
- Erwarteter naechster Schritt: `trigger_security_review`.
- Tatsaechlicher naechster Schritt: `resolve_first_level`.
- `close_incident` ist vorhanden, der Prozessabschluss selbst ist also nicht der Fehler.

Besonderheiten:

- Negativbeispiel fuer Wizard und Regressionstests.
- Macht sichtbar, dass ein abgeschlossener Prozess trotzdem fachlich falsch geroutet sein kann.
