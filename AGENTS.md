# AGENTS.md

## Projektziel

Dieses Repository basiert auf dem bestehenden Amagno Exporter und wird zum **Amagno Intelligence Tool** weiterentwickelt.

Ziel ist ein Symfony-basiertes Tool zur Überwachung, Analyse und Auswertung dokumentenbasierter Prozesse in Amagno.

Amagno bleibt das operative DMS.
Das Intelligence Tool beobachtet Prozessereignisse, lädt Dokumentkontext nach, erzeugt Prozessinstanzen, bewertet diese gegen Soll-Templates und erkennt Abweichungen, Engpässe sowie SLA-Verletzungen.

Langfristig soll der Kern DMS-unabhängig bleiben.

---

## Grundregeln für Codex

- Arbeite in kleinen, nachvollziehbaren Schritten.
- Vor größeren Änderungen zuerst analysieren und einen Plan vorschlagen.
- Bestehende Bausteine wiederverwenden, nicht unnötig neu schreiben.
- Keine Secrets, Tokens oder Zugangsdaten committen.
- Datenbankmigrationen nur erstellen, wenn sie fachlich begründet sind.
- Neue Services mit Tests absichern.
- Änderungen kurz dokumentieren.
- Bei Unsicherheit zuerst Rückfrage oder Vorschlag machen.

---

## Architekturprinzipien

### DMS-unabhängiger Kern

Der fachliche Kern darf keine direkte Abhängigkeit zu Amagno enthalten.

Der Kern arbeitet mit:

- CanonicalEvent
- DocumentRef
- ProcessInstance
- ContextSnapshot
- ProcessTemplate
- ContextProfile
- DecisionRules
- Deviations

Amagno-spezifische Logik gehört in Adapter oder Provider.

---

### Ports / Interfaces

Folgende Ports kapseln DMS-spezifische Logik:

- EventNormalizer
- ContextProvider
- SignatureVerifier

Konkrete Implementierungen:

- AmagnoEventNormalizer
- AmagnoContextProvider
- AmagnoSignatureVerifier

Für Tests sollen Fake-/InMemory-Implementierungen verwendet werden.

---

## Wiederverwendung bestehender Exporter-Funktionen

Folgende bestehende Bausteine sollen bevorzugt wiederverwendet oder extrahiert werden:

- AmagnoAPIApi
- Dokumentabfrage über Document UUID
- Merkmal-Resolver
- Merkmalstypen
- Multimerkmale
- Signatur- und Freigabeprüfung
- Excel-Export
- bestehende Symfony-Infrastruktur

Nicht Ziel:

- vollständiger Rewrite der Amagno-Kommunikation
- unnötige Duplizierung vorhandener Exporter-Logik

---

## Zentrale Konzepte

### Event Store

Events werden append-only gespeichert.

Events werden nicht überschrieben.

Wichtige Felder:

- external_event_key
- process_key
- event_key
- document_uuid
- document_version
- occurred_at
- received_at
- raw_event_json
- context_snapshot_json

Idempotenz ist Pflicht.

Doppelte Events müssen über `external_event_key` und Unique Constraints erkannt werden.

---

### Dokumentversionierung

Eine neue Dokumentversion erzeugt grundsätzlich eine neue Prozessinstanz.

Beispiel:

- document_uuid = abc, version = 1 → Prozessinstanz 1
- document_uuid = abc, version = 2 → Prozessinstanz 2

Alte Prozessinstanzen bleiben historisch erhalten.

---

### Context Profile

Ein Process Template definiert, welche Merkmale benötigt werden.

Im Recording-Modus dürfen viele oder alle Merkmale geladen werden.

Im Live-Modus sollen nur die im Context Profile definierten Merkmale geladen werden.

Besonders wichtig:

- Beträge
- Dokumentart
- Projektnummer
- Kostenstelle
- Dokumentversion
- Multimerkmale
- Freigaben / Signaturen

---

### Context Snapshot

Der Context Snapshot speichert den fachlichen Zustand zum Zeitpunkt eines Events.

Snapshots werden nicht nachträglich verändert.

---

### Process Templates

Templates definieren den Soll-Prozess.

Sie enthalten:

- Schritte
- Übergänge
- Varianten
- SLA-Regeln
- relevante Merkmale
- Decision Rules
- erlaubte Rückläufer
- erlaubte Endzustände

Templates müssen versioniert werden.

Laufende Prozessinstanzen bleiben auf der Template-Version, mit der sie gestartet wurden.

---

## Unterstützte Regeltypen

MVP:

- linearer Prozess
- If/Else-Regeln
- Switch/Case-Regeln
- einfache SLA-Regeln

Später:

- parallele Freigaben
- Mehrfachfreigaben je Rolle
- Rückläufer
- Storno/Abbruch
- komplexe Konformitätsprüfung
- BPMN-Visualisierung

---

## Technologiestack

Backend:

- Symfony
- PHP 8.3 oder neuer
- PostgreSQL
- Doctrine
- Symfony Messenger
- Symfony Console
- Symfony Scheduler
- PHPUnit oder Pest
- PHPStan oder Psalm

Frontend später:

- Vue 3
- TypeScript
- Vite
- Pinia
- bpmn-js

---

## Empfohlene Entwicklungsreihenfolge

1. Bestehenden Exporter analysieren
2. AmagnoAPIApi und Dokument-/Merkmal-Logik identifizieren
3. DMS-Ports als Interfaces anlegen
4. Event Receiver bauen
5. Event Store bauen
6. Context Resolver bauen
7. ProcessInstanceManager bauen
8. CLI-/Textauswertung bauen
9. Excel-Export anbinden
10. Recording-Modus bauen
11. Template-System ergänzen
12. Rule Engine aufbauen
13. UI später ergänzen

---

## Tests

Neue Logik soll testbar sein ohne laufendes Amagno.

Testarten:

- Unit-Tests für Domain-Logik
- Functional-Tests für API-Endpunkte
- Fixture-basierte Payload-Tests
- Tests für Idempotenz
- Tests für Dokumentversionierung
- Tests für ContextResolver mit Fake ContextProvider

---

## Verhalten bei Aufgaben

Bei jeder größeren Aufgabe:

1. Repository-Struktur prüfen
2. bestehenden Code suchen, der wiederverwendet werden kann
3. Plan vorschlagen
4. erst nach Plan umsetzen
5. Tests ergänzen
6. Tests/Linter ausführen, soweit möglich
7. Ergebnis zusammenfassen

---

## Nicht tun

- Keine produktiven Secrets in Dateien schreiben
- Keine Amagno-Zugangsdaten hardcoden
- Keine UI bauen, bevor Event Store und Prozesslogik stabil sind
- Keine direkte Amagno-Abhängigkeit in Domain-Services einbauen
- Keine Events überschreiben
- Keine historischen Prozessinstanzen stillschweigend verändern
