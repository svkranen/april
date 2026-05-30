# Process Analysis Guide

Diese Anleitung beschreibt den aktuellen Arbeitsablauf fuer Prozessanalyse, Template-Pruefung, Heatmaps und BPMN-aehnliche Visualisierung im Amagno Intelligence Tool.

## 1. Grundidee

Das Tool arbeitet template-basiert:

1. Prozessereignisse werden gespeichert.
2. Dokument-Timelines werden daraus aufgebaut.
3. Ein `ProcessTemplate` beschreibt den Soll-Prozess.
4. Checks vergleichen Ist-Timelines mit dem Soll-Prozess.
5. Heatmaps und BPMN-Views machen Abweichungen, Backlogs und Engpaesse sichtbar.

Amagno liefert aktuell Ereignisse und Kontextdaten. Der Core arbeitet mit fachlichen Modellen und bleibt frei von Amagno-Abhaengigkeiten.

## 2. Templates verwalten

Templates liegen standardmaessig unter `templates/*.yaml`.

Alle Templates anzeigen:

```bash
bin/console intelligence:template:list
bin/console intelligence:template:list --format=json
```

Ein Template besteht typischerweise aus:

- `key` und `version`
- `required_steps` fuer globale Pflichtschritte
- `steps` als Katalog bekannter Schritte
- `decision_points` fuer bedingte Pfade
- `parallel_groups` fuer reihenfolgeunabhaengige Schrittgruppen
- `context_profile.required` fuer benoetigte Kontextfelder
- `field_mapping` fuer technische Herkunft der Kontextfelder
- optional `connector` fuer Amagno-Verbindungszuordnung

## 3. Templates vorschlagen

Ein Template aus einem Dokument vorschlagen:

```bash
bin/console intelligence:template:suggest-from-document <documentUuid> <processKey>
```

Ein Template aus mehreren Dokumenten vorschlagen:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> <documentUuid1> <documentUuid2>
```

Automatische Auswahl:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> --limit=50
```

Die Multi-Document-Suggestion kann Hinweise ausgeben:

- `conflicting_transition`
- `possible_parallel`
- `possible_parallel_group`
- `possible_decision_point`

Diese Vorschlaege sind bewusst keine fertigen Fachregeln. Sie helfen, Templates manuell zu schaerfen.

## 4. Einzelnes Dokument pruefen

```bash
bin/console intelligence:template:check-document <documentUuid> <processKey> \
  --template=templates/<processKey>.yaml
```

Typische Ergebnisse:

- fehlende Pflichtschritte
- unerwartete Schritte
- falsche Reihenfolge globaler Pflichtschritte
- unvollstaendige Parallelgruppen
- Decision-Rule-Verletzungen
- fehlender Decision-Kontext

Decision-Rule-Verletzungen enthalten Kontextwerte, z. B.:

```text
Decision rule violation: freigabe_ab_1000 after 03 Freigabe_klein expected 05 Ausgangsrechnung buchen but got 07 Zahlungseingang erwartet. Context: amount_net=83.0
```

## 5. Batch-Check fuer einen Prozess

```bash
bin/console intelligence:template:check-process <processKey> \
  --template=templates/<processKey>.yaml
```

Der Command laedt alle Dokumente ueber den `ProcessDocumentUuidProvider` und prueft sie mit derselben Logik wie der Single-Document-Check.

Nuetzliche Optionen:

- `--format=json`
- `--only-deviations`
- `--show-ok`
- `--document-version=<n>`
- `--order-by=occurred-at|received-at|occurred-then-received`

### Statusklassen

- `OK`: keine Warnungen, keine Abweichungen
- `WARNING`: nur Datenqualitaets- oder Kontextprobleme
- `DEVIATION`: fachliche Regelverletzungen
- `ERROR`: technische Fehler waehrend der Pruefung

### Problem-Statistiken

Der Batch-Check aggregiert Problemtypen:

```text
Deviation Summary:
  - Decision rule violation: 2
  - Missing step: 2
  - Parallel Group incomplete: 1

Warning Summary:
  - Missing context route_after_pruefung: 16
  - Missing context freigabe_ab_1000: 4
```

### Priorisierung

Jedes Dokument erhaelt einen `problem_score`.

Standardgewichtung:

- `Missing step` = 3
- `Decision rule violation` = 2
- `Parallel Group incomplete` = 2
- `Missing context` = 0
- technische Fehler = 5

Die Ausgabe enthaelt:

```text
Top Problem Documents:
1. documentId: 41279747
   score: 7
   deviations: 3
```

Sortierung:

1. hoechster Score
2. hoechste Anzahl Verstoesse
3. `documentId`

## 6. Context und Field Mapping

Decision Rules benoetigen Kontextfelder. Diese werden ueber `context_profile.required` fachlich angefordert und ueber `field_mapping` technisch aufgeloest.

Beispiel:

```yaml
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
```

Wenn moeglich, ist `tag_id` robuster als `tag_name`.

```yaml
amount_net:
  source: amagno
  tag_id: "dc1bb3c6-ed8e-4780-32d2-08db7c5aaf65"
  value_type: number
```

Die Verbindung wird ueber das Template referenziert:

```yaml
connector:
  type: amagno
  connection: default
```

Die konkrete Verbindung steht lokal in `config/amagno_connections.local.json`.

## 7. Heatmaps erzeugen

```bash
bin/console intelligence:template:heatmap <processKey> \
  --template=templates/<processKey>.yaml \
  --format=json \
  --output=templates/<processKey>-heatmap.json \
  --force
```

Der Report enthaelt:

- `flow_heatmap.transitions`: beobachtete Kanten, Counts, Prozentwerte, Intensitaet, erlaubte/unerlaubte Kante
- `duration_heatmap.steps`: abgeschlossene Dokumente, durchschnittliche Dauer, offene Dokumente, Intensitaeten

Heatmaps veraendern keine Templates. Sie sind Auswertungen ueber beobachtete Timelines.

## 8. BPMN-aehnliche Views

Aus Template und optionaler Heatmap kann ein View Model erzeugt werden.

JSON:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=json
```

Mermaid:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=mermaid \
  --view=summary
```

SVG Summary:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=svg \
  --view=summary \
  --layout=process
```

SVG Bottleneck:

```bash
bin/console intelligence:template:bpmn-view <processKey> \
  --template=templates/<processKey>.yaml \
  --heatmap=templates/<processKey>-heatmap.json \
  --format=svg \
  --view=bottleneck \
  --layout=process
```

Views:

- `summary`: Soll-Prozess mit KPI-/Heatmap-Overlay
- `bottleneck`: Fokus auf Backlog und Task-Farben
- `deviations`: Abweichungen
- `observed`: beobachtete Kanten
- `combined`: kombinierte Analyseansicht

Layouts:

- `process`: BPMN-zentriert, lesbarer Soll-Prozess
- `graph`: technisch, hilfreich fuer Detailanalyse vieler Kanten

## 9. Empfohlener Workflow

1. Events fuer einen Prozess sammeln.
2. Mit `suggest-from-documents` ein erstes Template erzeugen.
3. Template manuell bereinigen:
   - `required_steps` setzen
   - Decision Points definieren
   - Parallelgruppen definieren
   - Kontextfelder mappen
4. Einzelne Dokumente mit `check-document` pruefen.
5. Gesamten Prozess mit `check-process` auswerten.
6. Problem Summary und Top Problem Documents abarbeiten.
7. Heatmap erzeugen.
8. SVG Summary oder Bottleneck View fuer fachliche Analyse erzeugen.

## 10. Hinweise zur Interpretation

- `Missing context` ist nicht automatisch eine Prozessabweichung. Es bedeutet, dass eine Decision Rule nicht sicher bewertet werden kann.
- `steps` ist nur der Katalog. Globale Pflicht entsteht ueber `required_steps`.
- Bedingte Schritte sollten ueber Decision Rules erwartet werden, nicht pauschal ueber `required_steps`.
- Parallelgruppen mit `order: any` erlauben unterschiedliche Reihenfolgen innerhalb der Gruppe.
- Unerwartete Ist-Kanten in Heatmaps sind Hinweise fuer Template-Luecken, Ruecklaeufer oder echte Prozessabweichungen.
