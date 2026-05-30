# Amagno Intelligence Tool

Das Amagno Intelligence Tool ist ein Symfony-basiertes Werkzeug fuer Process Intelligence in dokumentenbasierten Amagno-Prozessen.

Es beobachtet Prozessereignisse, speichert fachliche Timelines, laedt bei Bedarf Dokumentkontext aus Amagno nach und bewertet Prozessdurchlaeufe gegen versionierte Process Templates. Daraus entstehen Template-Vorschlaege, Konformitaetspruefungen, Heatmaps, Problem-Statistiken und BPMN-aehnliche Prozessansichten.

Amagno ist der erste Connector. Der fachliche Kern bleibt langfristig DMS-unabhaengig.

## Was aktuell moeglich ist

- Process Templates aus einzelnen oder mehreren Dokument-Timelines vorschlagen
- Prozessdurchlaeufe gegen Soll-Templates pruefen
- Batch-Checks fuer alle Dokumente eines `processKey` ausfuehren
- Statusklassen `OK`, `WARNING`, `DEVIATION`, `ERROR` ausgeben
- Problem-Statistiken und Top-Problem-Dokumente priorisieren
- Decision Points und einfache Decision Rules pruefen
- Parallelgruppen mit `order: any` pruefen
- Kontextfelder ueber `field_mapping` aus Amagno-Tags laden
- Flow- und Duration-Heatmaps erzeugen
- BPMN-aehnliche JSON-, Mermaid- und SVG-Views erzeugen
- SVG-Views als Summary, Bottleneck, Deviations oder Combined darstellen

## Schnellstart

Template-Dateien liegen typischerweise unter `templates/*.yaml`.

Verfuegbare Templates anzeigen:

```bash
bin/console intelligence:template:list
bin/console intelligence:template:list --format=json
```

Ein Dokument gegen ein Template pruefen:

```bash
bin/console intelligence:template:check-document <documentUuid> <processKey> \
  --template=templates/<processKey>.yaml
```

Alle Dokumente eines Prozesses pruefen:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=templates/<processKey>.yaml
```

JSON-Ausgabe:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=templates/<processKey>.yaml \
  --format=json
```

Nur fachliche Abweichungen anzeigen:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=templates/<processKey>.yaml \
  --only-deviations
```

OK-Dokumente zusaetzlich auflisten:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=templates/<processKey>.yaml \
  --show-ok
```

## Batch-Check-Ausgabe verstehen

`intelligence:template:check-process` klassifiziert jedes Dokument:

- `OK`: keine Warnungen, keine Abweichungen
- `WARNING`: nur Kontext- oder Datenqualitaetsprobleme, z. B. fehlender Decision-Kontext
- `DEVIATION`: fachliche Abweichungen, z. B. fehlende Pflichtschritte, Decision-Rule-Verletzungen oder unvollstaendige Parallelgruppen
- `ERROR`: technische Fehler waehrend der Pruefung

Die Ausgabe enthaelt:

- Summary mit Zaehlern fuer `OK`, `WARNING`, `DEVIATION`, `ERROR`
- `Deviation Summary`, aggregiert nach Problemtyp
- `Warning Summary`, z. B. fehlender Kontext je Decision Point
- `Top Problem Documents`, sortiert nach `problem_score`
- gruppierte Detailausgabe fuer `WARNING`, `DEVIATION`, `ERROR` und optional `OK`

Die Standardgewichtung fuer `problem_score`:

- `Missing step` = 3
- `Decision rule violation` = 2
- `Parallel Group incomplete` = 2
- `Missing context` = 0
- technische Fehler = 5

## Templates erzeugen

Aus einem einzelnen Dokument:

```bash
bin/console intelligence:template:suggest-from-document <documentUuid> <processKey>
```

Aus mehreren Dokumenten:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> <documentUuid1> <documentUuid2>
```

Automatische Dokumentauswahl:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> --limit=50
```

Die Multi-Document-Suggestion erkennt unter anderem:

- beobachtete Schritte und Transitionen
- widerspruechliche Transitionen
- moegliche Parallelgruppen
- moegliche Decision Points, wenn ein Schritt mehrere direkte Folgeschritte hat

## Template-Struktur

Ein Template beschreibt den Soll-Prozess:

```yaml
key: ai-rechnungen
version: 1

required_steps:
  - "01 Rechnungen pruefen"
  - "09 Rechnungen Abschluss"

steps:
  - key: "01 Rechnungen pruefen"
  - key: "03 Freigabe_klein"
  - key: "05 Ausgangsrechnung buchen"
  - key: "07 Zahlungseingang erwartet"
  - key: "09 Rechnungen Abschluss"

connector:
  type: amagno
  connection: default

context_profile:
  required:
    - invoice_direction
    - amount_net

field_mapping:
  invoice_direction:
    source: amagno
    tag_name: "Eingang/Ausgang"

  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
    value_type: number

decision_points:
  - key: route_after_pruefung
    after: "01 Rechnungen pruefen"
    required_fields:
      - invoice_direction
      - amount_net
    rules:
      - when:
          invoice_direction:
            eq: "RE - Ausgang"
        expect_next: "02 Versenden"

      - when:
          amount_net:
            gt: 50
        expect_next: "03 Freigabe_klein"

      - else:
          expect_next: "05 Ausgangsrechnung buchen"

parallel_groups:
  - key: buchen_und_zahlung
    required_steps:
      - "05 Ausgangsrechnung buchen"
      - "07 Zahlungseingang erwartet"
    order: any
```

Wichtig:

- `steps` ist der Katalog bekannter Schritte.
- `required_steps` sind globale Pflichtschritte. Bedingte Schritte aus Decision Rules gehoeren nicht automatisch hier hinein.
- `decision_points` pruefen bedingte Pfade.
- `parallel_groups` pruefen reihenfolgeunabhaengige Pflichtschritte.
- `field_mapping` verbindet fachliche Kontextfelder mit Amagno-Tags.

## Heatmaps

Heatmap erzeugen:

```bash
bin/console intelligence:template:heatmap <processKey> \
  --template=templates/<processKey>.yaml \
  --format=json \
  --output=templates/<processKey>-heatmap.json \
  --force
```

Der Report enthaelt:

- `flow_heatmap`: beobachtete Transitionen mit Count, Prozent, Intensitaet und `is_allowed`
- `duration_heatmap`: Dauer, offene Dokumente und Intensitaeten je Schritt

## BPMN-aehnliche Prozessansichten

View Model als JSON:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=json
```

Mermaid fuer Doku:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=mermaid \
  --view=summary
```

SVG Summary, gut lesbar als Prozessansicht:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=svg \
  --view=summary \
  --layout=process
```

Bottleneck-Fokus:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=svg \
  --view=bottleneck \
  --layout=process
```

Wichtige SVG-Optionen:

- `--view=summary`: Soll-Prozess mit KPI-/Heatmap-Overlay
- `--view=bottleneck`: Fokus auf Task-Farben und Backlog, sehr wenige Kanten
- `--view=deviations`: Abweichungskanten
- `--view=combined`: Soll- und beobachtete Kanten kombiniert
- `--layout=process`: BPMN-zentrierte Prozessansicht
- `--layout=graph`: technischer Analysegraph
- `--min-unexpected-count=2`: seltene unerwartete Kanten filtern

## Kontext aus Amagno laden

Templates koennen einen Connector definieren:

```yaml
connector:
  type: amagno
  connection: default
```

Die Verbindung wird aus `config/amagno_connections.local.json` geladen. Diese Datei ist lokal und darf keine Secrets im Repository hinterlassen.

`field_mapping` kann mit `tag_id` oder `tag_name` arbeiten. `tag_id` ist am eindeutigsten. `tag_name` wird ueber Amagno Tag Definitions aufgeloest.

```yaml
field_mapping:
  amount_net:
    source: amagno
    tag_id: "dc1bb3c6-ed8e-4780-32d2-08db7c5aaf65"
    value_type: number
```

## Architektur

Zielrichtung:

```text
App -> Connector/Amagno -> Core
App -> Core

Core -> niemals App
Core -> niemals Amagno
```

Core/Domain enthaelt fachliche Modelle wie:

- `ProcessEvent`
- `ProcessTimeline`
- `ProcessTemplate`
- Decision Rules
- Parallel Groups
- KPIs und Deviations

Connector/Amagno kapselt Amagno-spezifische Details wie Tag-Aufloesung, Dokumentdaten und Feld-Mapping.

App enthaelt Symfony Commands, Controller, Persistenz, Konfiguration und YAML-/JSON-I/O.

## Legacy AmagnoExporter

Das Repository enthaelt weiterhin alte Exporter-/Sync-Funktionen. Diese bleiben aus Kompatibilitaetsgruenden erhalten, sind aber nicht mehr der fachliche Produktkern.

Legacy-Bereiche sind unter anderem:

- `bin/console amagno:sync`
- `bin/console amagno:verify-signatures`
- alte Export-/Processing-Services
- `oldProject/*`
- historische Settings-UI

Neue Intelligence-Funktionalitaet sollte auf `src/Intelligence` und den Connector-Schichten aufbauen.

## Weitere Dokumentation

- [ARCHITECTURE.md](ARCHITECTURE.md): Architekturregeln und Schichtung
- [docs/process-analysis-guide.md](docs/process-analysis-guide.md): Anleitung fuer Prozessanalyse, Checks, Heatmaps und BPMN-Views
- [docs/doctrine-persistence.md](docs/doctrine-persistence.md): Doctrine-Persistenz fuer Intelligence-Daten
- [docs/index.html](docs/index.html): Entwickleruebersicht
- [docs/admin-guide.html](docs/admin-guide.html): Betriebsanleitung

## Tests

```bash
composer test
```

Die Tests laufen ohne produktive Amagno-Verbindung. Connector- und Context-Logik wird ueber Fakes oder In-Memory-Komponenten abgesichert.
