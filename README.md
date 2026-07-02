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
- neutrale ProcessGraphs als Mermaid exportieren, optional mit Live-Metriken aus ProcessEvents
- Dev-/Test-Sample-Daten fuer `ai-rechnungen` laden

## Schnellstart

APRIL-Prozess-Templates liegen typischerweise unter
`config/april/process-templates/*.yaml`. Das Projektverzeichnis `templates/`
ist fuer Symfony/Twig- und Frontend-Templates reserviert und darf nicht fuer
APRIL-Prozess-YAMLs verwendet werden.

Die Web-App unter `/app` ist per Form-Login geschuetzt. Der initiale Benutzer
wird ueber `APRIL_APP_USERNAME` und `APRIL_APP_PASSWORD_HASH` konfiguriert; der
Passwort-Hash gehoert in `.env.local`, echte Umgebungsvariablen oder Symfony
Secrets. Der Event-Endpoint `POST /api/intelligence/events` bleibt davon
unabhaengig und wird weiterhin ueber die vorhandene Signaturpruefung abgesichert.

Verfuegbare Templates anzeigen:

```bash
bin/console intelligence:template:list
bin/console intelligence:template:list --format=json
```

Ein Dokument gegen ein Template pruefen:

```bash
bin/console intelligence:template:check-document <documentUuid> <processKey> \
  --template=config/april/process-templates/<processKey>.yaml
```

Alle Dokumente eines Prozesses pruefen:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=config/april/process-templates/<processKey>.yaml
```

JSON-Ausgabe:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --format=json
```

Nur fachliche Abweichungen anzeigen:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --only-deviations
```

OK-Dokumente zusaetzlich auflisten:

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --show-ok
```

Dev-/Test-Sample-Daten fuer `ai-rechnungen` laden:

```bash
bin/console intelligence:sample-data:load-ai-rechnungen --purge
```

Der Sample-Loader schreibt direkt interne `ProcessEvent`-, `ProcessInstance`- und `ContextSnapshot`-Daten. Er nutzt keine IncomingEvent-Worker und fragt keinen Amagno-Context ab.

Fuer feinere Dwell-Heatmap-Verlaeufe kann ein zusaetzliches Fixture geladen werden:

```bash
bin/console intelligence:sample-data:load-ai-rechnungen --fixture=dwell-gradient --purge
```

`--purge` loescht dabei nur die Dokumente des gewaehlten Fixtures. Mit `--purge-all-samples` werden alle lokalen Sample-Dokumente `900001` bis `900008` und `901001` bis `901012` entfernt.

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
  - key: "02 Versenden"
  - key: "03 Freigabe_klein"
  - key: "04 Freigabe_gross"
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
    stability: immutable

  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
    value_type: number
    stability: snapshot_required

context_policy:
  snapshot:
    max_delay_seconds: 300
    stale_behavior: uncertain

transitions:
  - from: "02 Versenden"
    to_parallel_group: "buchen_und_zahlung"

  - from: "04 Freigabe_gross"
    to_parallel_group: "buchen_und_zahlung"

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
          expect_next_parallel_group: "buchen_und_zahlung"

  - key: freigabe_ab_1000
    after: "03 Freigabe_klein"
    required_fields:
      - amount_net
    rules:
      - when:
          amount_net:
            gt: 1000
        expect_next: "04 Freigabe_gross"

      - else:
          expect_next_parallel_group: "buchen_und_zahlung"

parallel_groups:
  - key: buchen_und_zahlung
    required_steps:
      - "05 Ausgangsrechnung buchen"
      - "07 Zahlungseingang erwartet"
    order: any
    next: "09 Rechnungen Abschluss"
```

Wichtig:

- `steps` ist der Katalog bekannter Schritte.
- `required_steps` sind globale Pflichtschritte. Bedingte Schritte aus Decision Rules gehoeren nicht automatisch hier hinein.
- `decision_points` pruefen bedingte Pfade.
- `expect_next` zeigt auf einen Step.
- `expect_next_parallel_group` aktiviert eine Parallelgruppe.
- `parallel_groups` pruefen reihenfolgeunabhaengige Pflichtschritte. Mit `next` wird nach vollstaendiger Gruppe der naechste Step erwartet.
- `transitions.to_parallel_group` aktiviert eine Parallelgruppe nach einem Step.
- `field_mapping` verbindet fachliche Kontextfelder mit Amagno-Tags.
- `field_mapping.stability` ist Pflicht fuer Felder, die in Decision Rules verwendet werden. Gueltige Werte sind `immutable`, `mutable`, `snapshot_required`.
- `context_policy.snapshot.max_delay_seconds` begrenzt, wie spaet ein Snapshot fuer historische Decision Checks noch verwendet werden darf.

## Heatmaps

Heatmap erzeugen:

```bash
bin/console intelligence:template:heatmap <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --format=json \
  --output=var/april/generated/heatmaps/<processKey>-heatmap.json \
  --force
```

Der Report enthaelt:

- `flow_heatmap`: beobachtete Transitionen mit Count, Prozent, Intensitaet und `is_allowed`
- `duration_heatmap`: Dauer, offene Dokumente und Intensitaeten je Schritt

## Mermaid ProcessGraph Export

Der direkte Diagramm-Export nutzt zuerst einen neutralen `ProcessGraph` und rendert danach Mermaid. Das ist bewusst nicht YAML-direkt-zu-Mermaid verdrahtet, damit spaeter weitere Renderer wie draw.io oder BPMN folgen koennen.

Strukturansicht:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/<processKey>.yaml
```

Obsidian-kompatible Labels:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/<processKey>.yaml \
  --compat=obsidian
```

Default-Order-Kanten aus der Reihenfolge in `steps` sind standardmaessig ausgeblendet. Bei Bedarf:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/<processKey>.yaml \
  --show-default-order
```

Metrik-Views:

- `--view=structure`: nur Soll-Struktur
- `--view=flow`: Kanten-Counts
- `--view=dwell`: Liegedauer-Buckets auf Nodes
- `--view=deviations`: unerwartete Kanten und Problem-Nodes
- `--view=combined`: Flow, Dwell und Deviations kombiniert

Dwell-Farben nutzen standardmaessig eine relative Gelb-bis-Rot-Perzentil-Skala im aktuellen Datensatz:

- Default: `--dwell-metric=median`, `--dwell-buckets=8`
- p10 wird dem hellgelben Bucket `dwell-scale-0` zugeordnet.
- p90 wird dem roten Bucket `dwell-scale-7` zugeordnet.
- Dunkler/roter bedeutet laengere Liegedauer im aktuellen Datensatz, nicht automatisch fachlich kritisch.
- Virtuelle Knoten wie Start, End, Decisions und Parallelgruppen bleiben neutral.
- `no-dwell` ist hellgelb/neutral und bedeutet: keine belastbare Dwell-Messung oder virtueller Prozessknoten.
- Dwell-Klassen setzen nur die Fuellfarbe. Rahmen codieren Status oder Struktur, z. B. `required`, `node-deviation` oder `constraint`.

Legende ausgeben:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/ai-rechnungen.yaml \
  --view=dwell \
  --show-dwell-legend
```

Ohne `--metrics` oder `--heatmap` baut der Export fuer Metrik-Views die Metriken live aus derselben `ProcessEvent`-/Timeline-Basis wie `template:check-process`.

In `--view=flow` codiert die Kantendicke die beobachtete Menge auf der Kante. Node-Fuellfarben nutzen `flow-scale-0` bis `flow-scale-7` relativ zum aktuellen Datensatz:

- Reale Steps: eindeutige Dokumente, die den Step mindestens einmal durchlaufen.
- Decision Nodes: Dokumente, bei denen der Decision Point projiziert wurde.
- Parallel Start: Dokumente, bei denen die Gruppe aktiviert wurde.
- Parallel Complete: Dokumente, bei denen alle Required Steps der Gruppe erfuellt wurden.
- Rot bedeutet hohes Volumen im aktuellen Datensatz, nicht kritisch.

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/ai-rechnungen.yaml \
  --view=flow \
  --show-flow-legend \
  --show-node-metrics
```

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/ai-rechnungen.yaml \
  --view=combined
```

Eine vorhandene Heatmap-Datei kann explizit genutzt werden:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/ai-rechnungen.yaml \
  --view=combined \
  --metrics=var/april/generated/heatmaps/ai-rechnungen-heatmap.json
```

Wichtig: Wenn `--metrics` gesetzt ist, wird genau diese Datei verwendet. Ohne `--metrics` ist die Live-Datenbasis der aktuelle `processKey`.

Live-Metrik-Filter:

- `--process-key=<key>`: Runtime-ProcessKey, default ist Template-Key
- `--from=<datetime>` und `--to=<datetime>`
- `--document-id=<externalId-or-uuid>`
- `--document-version=<n>`
- `--sample-only`
- `--include-ok`
- `--include-deviations`
- `--order-by=occurred-at|received-at|occurred-then-received`

Debug-Ausgabe fuer Metrik-Projektion:

```bash
bin/console intelligence:template:export-diagram config/april/process-templates/ai-rechnungen.yaml \
  --view=combined \
  --debug-metrics
```

Die Debug-Ausgabe enthaelt unter anderem `documents_seen`, `documents_projected`, `documents_skipped`, `skip_reasons`, `raw_transition_count`, `projected_edge_count`, `unexpected_edge_count` und pro Dokument die erkannten Transitionen.

Eventlogs enthalten reale Amagno-Steps. Der Soll-Graph enthaelt zusaetzlich virtuelle Knoten wie Decision Gateways, Parallel-Start, Parallel-Join und End. Der Export projiziert beobachtete direkte Step-zu-Step-Transitionen deshalb auf Soll-Graph-Kanten. Nur nicht erklaerbare Transitionen bleiben rote observed-only Kanten.

## BPMN-aehnliche Prozessansichten

View Model als JSON:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --heatmap=var/april/generated/heatmaps/<processKey>-heatmap.json \
  --format=json
```

Mermaid fuer Doku:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --heatmap=var/april/generated/heatmaps/<processKey>-heatmap.json \
  --format=mermaid \
  --view=summary
```

SVG Summary, gut lesbar als Prozessansicht:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --heatmap=var/april/generated/heatmaps/<processKey>-heatmap.json \
  --format=svg \
  --view=summary \
  --layout=process
```

Bottleneck-Fokus:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=config/april/process-templates/<processKey>.yaml \
  --heatmap=var/april/generated/heatmaps/<processKey>-heatmap.json \
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

## Cross-Process-Routing pruefen

Ein Source-Template kann read-only beschreiben, welcher Zielprozess nach einem
Routing-Schritt erwartet wird. Das modelliert Cross-Process-Routing: Prozess A
entscheidet oder erwartet Zielprozess B. Eine Journey ist nur die fachliche
Klammer ueber mehrere Prozesse. Ein Subprozess wird damit nicht modelliert.

```yaml
cross_process_routing:
  - key: route_to_aufmass
    after_step: "10 Intake abgeschlossen"
    when:
      document_type: "aufmass"
    expected_process: "aufmass_workflow"
```

`when` ist im MVP ein Equality-Shorthand: alle angegebenen Context-Felder
muessen am Routing-Zeitpunkt exakt passen.

```bash
bin/console intelligence:template:check-journey uuid-1 debitoren_intake \
  --template=config/april/process-templates/debitoren_intake.yaml
```

Der Check liest nur vorhandene Timelines, Prozessinstanzen und Context Snapshots.
Er startet keine Prozesse, nutzt keine Queue und schreibt keine Daten.

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
