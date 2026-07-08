# Incident Management Demo Fixtures

Diese Demo-Fixtures beschreiben neutrale Incident-Management-Ereignisse fuer den APRIL Community-Core. Sie gehoeren zum Prozess-Template `incident-management` und enthalten den benoetigten Context direkt im Event-Payload unter `attributes`.

Die Dateien dienen spaeter als gemeinsame Grundlage fuer:

- den lokalen Quickstart
- den CLI-Onboarding-Wizard
- den Web-Onboarding-Wizard
- Regressionstests fuer Template-, Routing- und Deviation-Checks

## Szenarien

- `events-normal.json`: Low-Severity-Incident mit Routing zu `resolve_first_level` und erfolgreichem Abschluss.
- `events-business.json`: Business-Process-Incident mit Routing zu `route_to_specialist_group` und erfolgreichem Abschluss.
- `events-security.json`: Security-Incident mit `data_exposure=true`, Routing zu `trigger_security_review` und erfolgreichem Abschluss.
- `events-deviation.json`: Security-Context, aber absichtlich falsches Routing zu `resolve_first_level`; dient als Demo fuer spaetere Decision Rule Violations.

Es wird noch keine Import-Logik vorausgesetzt. Die Dateien sind reine, reproduzierbare Demo-Daten.
