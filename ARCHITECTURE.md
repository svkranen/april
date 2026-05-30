# Amagno Intelligence Tool

## Vision

Das Amagno Intelligence Tool überwacht, analysiert und visualisiert dokumentenbasierte Geschäftsprozesse.

Amagno bleibt das führende operative DMS.

Das Intelligence Tool beobachtet Prozessereignisse, erzeugt Prozessinstanzen, bewertet diese gegen definierte Soll-Prozesse und erkennt Abweichungen, Engpässe sowie SLA-Verletzungen.

Langfristig soll daraus ein DMS-unabhängiges Process-Intelligence-Framework entstehen.

---

# Leitprinzipien

## DMS-Unabhängigkeit

Der fachliche Kern darf keine Abhängigkeit zu einem konkreten DMS besitzen.

DMS-spezifische Logik wird ausschließlich über Adapter eingebunden.

Der Kern arbeitet ausschließlich mit:

* Canonical Events
* Process Instances
* Context Snapshots
* Process Templates
* Decision Rules

---

## Event Sourcing Light

Alle empfangenen Prozessereignisse werden unveränderlich gespeichert.

Events werden niemals überschrieben.

Neue Erkenntnisse entstehen durch Auswertung vorhandener Events.

---

## Versionierung

Eine neue Dokumentversion erzeugt grundsätzlich einen neuen Prozesslauf.

Beispiel:

Dokument RE-4711

* Version 1 → Prozessinstanz A
* Version 2 → Prozessinstanz B

Alte Prozessinstanzen bleiben vollständig erhalten.

---

## Process Intelligence vor UI

Zuerst entsteht ein belastbarer Analysekern.

Die grafische Oberfläche folgt später.

Reihenfolge:

1. Event Store
2. Context Resolver
3. Process Instance Manager
4. Rule Engine
5. Reporting
6. UI
7. BPMN / Heatmap

---

# Zielarchitektur

```text
Amagno
    │
    ▼
Event Receiver
    │
    ▼
Event Normalizer
    │
    ▼
Canonical Event
    │
    ▼
Event Store
    │
    ├── Recorder
    ├── Reporting
    └── Reprocessing

    ▼

Process Instance Manager
    │
    ▼
Context Resolver
    │
    ▼
Context Provider
    │
    ▼
Rule Engine
    │
    ▼
Deviation Analyzer
    │
    ▼
Output Layer
```

---

# Architekturregeln

## Core

Der Core enthält die fachliche Prozesslogik.

Der Core:

* kennt kein Amagno
* kennt keine Symfony Controller
* kennt keine Doctrine Repositories
* kennt keine Zugangsdaten
* arbeitet nur mit ProcessEvent, ProcessTimeline, ProcessTemplate, Rules und KPIs

## Connector/Amagno

Der Amagno-Connector kapselt alle Amagno-spezifischen Details.

Der Connector:

* darf Amagno kennen
* übersetzt Amagno-Payloads in ProcessEvents
* darf Core-Modelle verwenden

## App

Die App-Schicht enthält die technische Symfony-Anwendung.

Zur App gehören:

* Symfony Commands
* Symfony Controller
* Persistenz
* Konfiguration

Die App darf Core und Connector nutzen.

## Abhängigkeitsrichtung

Erlaubte Abhängigkeiten:

```text
App → Connector → Core
App → Core
```

Verbotene Abhängigkeiten:

```text
Core → App
Core → Amagno
```

Der Core bleibt dadurch fachlich eigenständig und langfristig DMS-unabhängig.

---

# Ports

## EventNormalizer

Verantwortlich für die Übersetzung eines DMS-Payloads in ein kanonisches Event.

```php
interface EventNormalizer
{
    public function normalize(array $payload): CanonicalEvent;
}
```

---

## ContextProvider

Lädt benötigte Dokumentinformationen.

```php
interface ContextProvider
{
    public function loadAttributes(
        DocumentRef $document,
        array $fields
    ): array;
}
```

---

## SignatureVerifier

Prüft eingehende Requests.

```php
interface SignatureVerifier
{
    public function verify(
        string $payload,
        string $signature
    ): bool;
}
```

---

# Kanonisches Eventmodell

```php
final readonly class CanonicalEvent
{
    public function __construct(
        public DocumentRef $document,
        public string $stepKey,
        public ?string $actorRef,
        public DateTimeImmutable $occurredAt,
        public array $attributes = [],
    ) {}
}
```

Das kanonische Eventmodell darf keine Amagno-spezifischen Konzepte enthalten.

---

# Dokumentmodell

## DocumentRef

```php
final readonly class DocumentRef
{
    public function __construct(
        public string $sourceSystem,
        public string $externalId,
        public ?string $externalUuid,
        public int $version
    ) {}
}
```

---

# Process Instance

Eine Process Instance beschreibt einen konkreten Prozessdurchlauf.

Identifikation:

```text
document_uuid
+
document_version
+
template_version
```

Beispiel:

eingangsrechnung-4711-v3

---

# Event Store

Alle Events werden dauerhaft gespeichert.

Pflichtfelder:

* id
* external_event_key
* process_key
* event_key
* document_uuid
* document_version
* occurred_at
* received_at
* raw_event_json
* context_snapshot_json

---

# Idempotenz

Doppelte Events dürfen keine Doppelverarbeitung auslösen.

Unique Constraint:

```text
process_instance_id
+
external_event_key
```

---

# Context Profile

Ein Process Template definiert, welche Merkmale benötigt werden.

Beispiel:

```yaml
context_profile:
  required:
    - documentVersion
    - betrag
    - dokumentart
    - projektnummer
    - freigaben
```

Der Context Resolver lädt ausschließlich diese Merkmale.

---

# Context Snapshot

Der fachliche Zustand zum Zeitpunkt eines Events.

Beispiel:

```json
{
  "betrag": 12000,
  "projektnummer": "BV-4711",
  "documentVersion": 3
}
```

Snapshots werden niemals nachträglich verändert.

---

# Decision Engine

Unterstützte Regeltypen:

## Linear

```text
A -> B -> C
```

## If / Else

```text
Wenn Betrag > 10000
→ GF-Freigabe

Sonst
→ Export
```

## Switch / Case

```text
Rechnung
→ Rechnungsprüfung

Vertrag
→ Vertragsprüfung
```

## Mehrfachfreigaben

```text
Mindestens 2 GF-Freigaben erforderlich.
```

## Rückläufer

```text
GF lehnt ab
→ zurück zu Prüfung
```

## Endzustände

```text
Abgeschlossen
Abgebrochen
Storniert
```

---

# Template System

Templates definieren den Soll-Zustand.

Enthalten:

* Schritte
* Übergänge
* Varianten
* SLA-Regeln
* Context Profile
* Decision Rules
* erlaubte Rückläufer
* Endzustände

---

# Template Sources

Ein Template kann aus unterschiedlichen Quellen stammen.

## Recorder

Aufzeichnung eines Testdurchlaufs.

## YAML

Definition als Datei.

## Builder

Programmatische Erstellung.

## Mining

Ableitung aus historischen Events.

## BPMN Import

Spätere Ausbaustufe.

---

# Recording-Modus

Ablauf:

1. Testdokument auswählen
2. Prozess durchlaufen
3. Events aufzeichnen
4. Eventkette anzeigen
5. Soll-Prozess markieren
6. Context Profile auswählen
7. Template erzeugen

---

# Reporting

## Phase 1

* CLI
* Excel
* CSV

## Phase 2

* Mermaid
* SVG

## Phase 3

* BPMN
* Heatmap
* Interaktive Analyse

---

# Technologie

## Backend

* Symfony
* PostgreSQL
* Doctrine
* Messenger
* Scheduler
* PHPUnit
* PHPStan

## Frontend

Später:

* Vue 3
* TypeScript
* Pinia
* Vite

## BPMN

Später:

* bpmn-js

---

# MVP

## Version 1

* Event Receiver
* Event Store
* Context Resolver
* Process Instance Manager
* Excel Export
* CLI Reporting

Keine UI.

---

## Version 2

* Recording-Modus
* Template Builder
* Rule Engine
* SLA Monitoring

---

## Version 3

* VueJS UI
* BPMN Visualisierung
* Heatmap
* Interaktive Analyse

---

# Wiederverwendung bestehender Komponenten

Folgende Komponenten aus dem Amagno Exporter sollen übernommen werden:

* AmagnoAPIApi
* Document UUID Lookup
* Merkmal-Resolver
* Merkmalstypen
* Multimerkmale
* Signaturprüfung
* Excel-Export
* Symfony Infrastruktur

Neue Funktionalität wird bevorzugt als neue Services implementiert.

Vorhandene Komponenten sollen refactored und wiederverwendet werden, nicht neu entwickelt.
