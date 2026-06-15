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
- `transitions` fuer explizite fachliche Uebergaenge
- `context_profile.required` fuer benoetigte Kontextfelder
- `field_mapping` fuer technische Herkunft der Kontextfelder
- optional `connector` fuer Amagno-Verbindungszuordnung

Wichtige aktuelle Template-Felder:

- `field_mapping.<field>.stability`: Pflicht fuer Decision-Felder. Gueltig sind `immutable`, `mutable`, `snapshot_required`.
- `context_policy.snapshot.max_delay_seconds`: Freshness-Fenster fuer historische Snapshot-Auswertung.
- `decision_points.rules.expect_next`: erwarteter naechster Step.
- `decision_points.rules.expect_next_parallel_group`: aktiviert eine Parallelgruppe.
- `parallel_groups.next`: erwarteter Step nach vollstaendig erfuellter Gruppe.
- `transitions.to_parallel_group`: aktiviert eine Parallelgruppe nach einem Step.

Beispiel fuer eine Decision Rule, die eine Parallelgruppe aktiviert:

```yaml
decision_points:
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
```

Beispiel fuer eine Any-Order-Gruppe mit Abschluss-Step:

```yaml
parallel_groups:
  - key: buchen_und_zahlung
    required_steps:
      - "05 Ausgangsrechnung buchen"
      - "07 Zahlungseingang erwartet"
    order: any
    next: "09 Rechnungen Abschluss"
```

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

## 6. Dev-/Test-Sample-Daten laden

Fuer lokale Graph-, Heatmap- und Projection-Tests gibt es einen Loader fuer `ai-rechnungen`:

```bash
bin/console intelligence:sample-data:load-ai-rechnungen --purge
```

Der Loader erzeugt acht kuenstliche Dokumente mit den `documentId`s `900001` bis `900008`. Er schreibt direkt interne `ProcessEvent`-, `ProcessInstance`- und `ContextSnapshot`-Daten und geht nicht ueber IncomingEvents, Worker oder Amagno-Context-Loading.

Enthaltene Varianten:

- Ausgangsrechnung: `01 -> 02 -> 05 -> 07 -> 09`
- Ausgangsrechnung mit `order:any` umgekehrt: `01 -> 02 -> 07 -> 05 -> 09`
- Eingang `> 50 <= 1000`: `01 -> 03 -> 05 -> 07 -> 09`
- Eingang `> 1000`: `01 -> 03 -> 04 -> 05 -> 07 -> 09`
- Eingang `<= 50`: `01 -> 05 -> 07 -> 09`
- echte Abweichung: `01 -> 02 -> 01 -> 07`
- Eingang `> 1000` mit Gruppenreihenfolge `07` vor `05`
- Eingang `> 50 <= 1000` mit Gruppenreihenfolge `07` vor `05`

Fuer Dwell-Heatmap-Tests gibt es ein zusaetzliches Fixture mit laenger gestaffelten Liegedauern:

```bash
bin/console intelligence:sample-data:load-ai-rechnungen --fixture=dwell-gradient --purge
```

Dieses Fixture erzeugt die Dokumente `901001` bis `901012`. Die erwarteten Abweichungsdokumente sind `901011` und `901012`. `--purge` loescht nur den Dokumentbereich des gewaehlten Fixtures; `--purge-all-samples` loescht alle Sample-Dokumente `900001` bis `900008` und `901001` bis `901012`.

## 7. Context und Field Mapping

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
    stability: immutable

  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
    value_type: number
    stability: snapshot_required
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

Historische Decision Checks duerfen mutable Felder nur verwenden, wenn ein Snapshot vorhanden und frisch genug ist. Das Freshness-Fenster steht im Template:

```yaml
context_policy:
  snapshot:
    max_delay_seconds: 300
    stale_behavior: uncertain
```

Zeitpunkte werden fachlich als UTC gespeichert. Amagno-Zeitwerte ohne Offset werden als konfigurierte Amagno-Zeitzone interpretiert und dann nach UTC normalisiert.

## 8. Heatmaps erzeugen

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

## 9. Mermaid ProcessGraph Export

Der direkte Mermaid-Export baut zuerst einen neutralen `ProcessGraph` aus dem Template. Dadurch kann derselbe Graph spaeter auch fuer andere Renderer genutzt werden.

Eine kompakte Uebersicht sinnvoller Diagramme und Standard-Kommandos steht in `docs/intelligence/chart-recipes.md`.

Struktur:

```bash
bin/console intelligence:template:export-diagram templates/<processKey>.yaml
```

Obsidian-kompatible Edge-Labels:

```bash
bin/console intelligence:template:export-diagram templates/<processKey>.yaml \
  --compat=obsidian
```

Implizite Reihenfolge aus `steps` wird standardmaessig nicht gerendert. Bei Bedarf:

```bash
bin/console intelligence:template:export-diagram templates/<processKey>.yaml \
  --show-default-order
```

Metrik-Views:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined
```

Views:

- `structure`: Soll-Struktur
- `flow`: Kanten-Counts
- `dwell`: Liegedauer-Buckets auf Nodes
- `deviations`: rote observed-only Kanten und Problem-Nodes
- `combined`: Flow, Dwell und Deviations kombiniert

Dwell-Farben nutzen standardmaessig eine relative Gelb-bis-Rot-Perzentil-Skala im aktuellen Datensatz:

- Default: `--dwell-metric=median`, `--dwell-buckets=8`
- p10 wird dem hellgelben Bucket `dwell-scale-0` zugeordnet.
- p90 wird dem roten Bucket `dwell-scale-7` zugeordnet.
- Dunkler/roter bedeutet laengere Liegedauer im aktuellen Datensatz, nicht automatisch fachlich kritisch.
- Virtuelle Knoten wie Start, End, Decisions und Parallelgruppen bleiben neutral.
- `no-dwell` ist hellgelb/neutral und bedeutet: keine belastbare Dwell-Messung oder virtueller Prozessknoten.
- Dwell-Klassen setzen nur die Fuellfarbe. Rahmen codieren Status oder Struktur, z. B. `required`, `node-deviation` oder `constraint`.

Legende:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=dwell \
  --show-dwell-legend
```

Ohne `--metrics` oder `--heatmap` nutzt der Export fuer Metrik-Views live dieselbe Dokumentbasis wie `template:check-process`: `ProcessDocumentUuidProvider` und `DocumentTimelineProvider` auf Basis der gespeicherten `ProcessEvent`s. Dadurch sehen Check und Diagramm standardmaessig dieselben Dokumente.

In `--view=flow` codiert die Kantendicke die beobachtete Menge auf der Kante. Node-Fuellfarben nutzen `flow-scale-0` bis `flow-scale-7` relativ zum aktuellen Datensatz:

- Reale Steps: eindeutige Dokumente, die den Step mindestens einmal durchlaufen.
- Decision Nodes: Dokumente, bei denen der Decision Point projiziert wurde.
- Parallel Start: Dokumente, bei denen die Gruppe aktiviert wurde.
- Parallel Complete: Dokumente, bei denen alle Required Steps der Gruppe erfuellt wurden.
- Rot bedeutet hohes Volumen im aktuellen Datensatz, nicht kritisch.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --show-flow-legend \
  --show-node-metrics
```

Eine gespeicherte Heatmap kann explizit genutzt werden:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --metrics=templates/ai-rechnungen-heatmap.json
```

Wenn `--metrics` gesetzt ist, wird bewusst genau diese Datei verwendet. Eine veraltete Datei kann deshalb andere Counts zeigen als Live-Checks.

Audit-Annotationen fuer relevante Context-Aenderungen:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --diagram-mode=audit
```

Alternativ kann nur die Annotation aktiviert werden:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --with-context-changes
```

Die Standardausgabe bleibt schlank. Im Audit-Modus werden keine vollstaendigen Context-Snapshots in Prozessknoten geschrieben. APRIL erzeugt nur gelbe Annotation-Knoten fuer Context-Aenderungen, wenn eine Decision Rule Violation vorliegt und danach ein Feld geaendert wurde, das in der betroffenen Decision verwendet wird. Die Annotation wird gestrichelt mit dem Decision-Knoten verbunden und enthaelt Feldname, alten Wert, neuen Wert und betroffene Decisions.

Live-Metrik-Filter:

- `--process-key=<key>`
- `--process-version=<version>|latest`
- `--from=<datetime>`
- `--to=<datetime>`
- `--document-id=<externalId-or-uuid>`
- `--document-version=<n>`
- `--sample-only`
- `--include-ok`
- `--include-deviations`
- `--order-by=occurred-at|received-at|occurred-then-received`

Debug:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --debug-metrics
```

Die Debug-Ausgabe enthaelt:

- `documents_seen`
- `documents_projected`
- `documents_skipped`
- `skip_reasons`
- `raw_transition_count`
- `projected_edge_count`
- `unexpected_edge_count`
- pro Dokument: erkannte Transitionen und Projektion

### Projektion beobachteter Transitionen

Der Eventlog enthaelt reale Amagno-Steps. Der Soll-Graph enthaelt zusaetzlich virtuelle Knoten wie Decision Gateways, Parallel-Start, Parallel-Join und End. Der Metrics-Layer projiziert beobachtete direkte Step-zu-Step-Uebergaenge auf Soll-Kanten:

- `01 -> 03` wird auf `01 -> decision:route_after_pruefung -> 03` gezaehlt, wenn die Decision Rule passt.
- `03 -> 07` kann auf `03 -> decision:freigabe_ab_1000 -> parallel_start -> 07` gezaehlt werden.
- `05 -> 07` und `07 -> 05` innerhalb derselben `order:any`-Gruppe werden nicht rot gerendert.
- `05/07 -> 09` wird auf `required_step -> parallel_join -> 09` gezaehlt.
- Nur unerklarebare Uebergaenge bleiben rote observed-only Kanten.

## 10. BPMN-aehnliche Views

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

## 11. Empfohlener Workflow

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
7. Heatmap erzeugen oder Mermaid ProcessGraph mit Live-Metriken exportieren.
8. SVG Summary oder Bottleneck View fuer fachliche Analyse erzeugen.

## 12. Hinweise zur Interpretation

- `Missing context` ist nicht automatisch eine Prozessabweichung. Es bedeutet, dass eine Decision Rule nicht sicher bewertet werden kann.
- `steps` ist nur der Katalog. Globale Pflicht entsteht ueber `required_steps`.
- Bedingte Schritte sollten ueber Decision Rules erwartet werden, nicht pauschal ueber `required_steps`.
- Parallelgruppen mit `order: any` erlauben unterschiedliche Reihenfolgen innerhalb der Gruppe.
- Unerwartete Ist-Kanten in Heatmaps sind Hinweise fuer Template-Luecken, Ruecklaeufer oder echte Prozessabweichungen.
- Im Mermaid ProcessGraph werden beobachtete Step-zu-Step-Uebergaenge zuerst gegen virtuelle Soll-Knoten projiziert. Rote observed-only Kanten sollten dadurch echte unerwartete Pfade anzeigen, nicht nur fehlende virtuelle Knoten im Eventlog.
