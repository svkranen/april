# Chart-Rezepte

Diese Doku beschreibt, welche Diagramme in APRIL sinnvoll sind und wann sie genutzt werden sollten. Sie ist bewusst als Rezeptliste geschrieben, weil `intelligence:template:export-diagram` viele Optionen hat.

## Grundregel

Starte immer mit einem schlanken Diagramm und schalte Analyseinformationen nur gezielt dazu.

- Struktur klaeren: `structure`
- Prozessvolumen sehen: `flow`
- Liegedauer sehen: `dwell`
- Abweichungen sehen: `deviations`
- Management-/Analyseueberblick: `combined`
- Ursachenanalyse mit Context-Korrekturen: `--diagram-mode=audit`

## 1. Soll-Prozess-Struktur

Zweck: Template pruefen, Prozesspfade und Decision Points sichtbar machen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml
```

Sinnvoll fuer:

- Template-Review
- Abstimmung der Soll-Prozesslogik
- Pruefen von Parallelgruppen und Decision Points

Nicht sinnvoll fuer:

- Laufzeit-/Volumenanalyse
- Ursachenanalyse einzelner Abweichungen

## 2. Struktur mit impliziter Reihenfolge

Zweck: Auch die aus `steps` abgeleitete Default-Reihenfolge sichtbar machen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --show-default-order
```

Nutzen: Hilfreich bei Templates, die wenig explizite `transitions` enthalten.

## 3. Flow-Chart

Zweck: Welche Wege werden tatsaechlich genutzt?

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --show-node-metrics
```

Darstellung:

- Node-Fuellfarbe: relatives Dokumentvolumen
- Kantenbreite: relatives Kantenvolumen
- Node-Label `docs`: Anzahl eindeutiger Dokumente am Knoten

Sinnvoll fuer:

- haeufige Prozesswege erkennen
- selten genutzte Pfade finden
- pruefen, ob Decision Rules erwartete Mengen erzeugen

Wichtig: Rot bedeutet in dieser Ansicht hohes Volumen im aktuellen Datensatz, nicht automatisch ein Problem.

## 4. Flow-Chart mit Legende

Zweck: Farben und Bucket-Verteilung nachvollziehbar machen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --show-flow-legend \
  --show-node-metrics
```

Nutzen: Gut fuer Screenshots oder Reports, weil die Farbskala mit ausgegeben wird.

## 5. Dwell-Chart

Zweck: Wo liegen Dokumente lange?

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=dwell
```

Darstellung:

- Node-Fuellfarbe: relative Liegedauer im aktuellen Datensatz
- Default-Metrik: Median
- Virtuelle Knoten wie Decisions und Parallelgruppen bleiben neutral, wenn keine belastbare Dwell-Messung existiert

Sinnvoll fuer:

- Engpaesse finden
- lange Bearbeitungsstationen identifizieren
- Prozessverbesserungen priorisieren

Wichtig: Rot bedeutet laengere Liegedauer relativ zu den anderen Knoten im aktuellen Datensatz, nicht automatisch SLA-Verletzung.

## 6. Dwell-Chart mit anderer Metrik

Zweck: Ausreisser oder Durchschnitt anders betrachten.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=dwell \
  --dwell-metric=p95 \
  --show-dwell-legend
```

Metriken:

- `median`: robuster Standard
- `avg`: Durchschnitt, empfindlich gegen Ausreisser
- `p95`: zeigt lange Ausreisser deutlicher

## 7. Deviations-Chart

Zweck: Unerwartete Ist-Uebergaenge sichtbar machen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=deviations
```

Darstellung:

- rote gestrichelte Kanten: beobachtete Uebergaenge, die nicht sauber in den Soll-Graph projiziert werden konnten
- rote Node-Rahmen: Knoten mit Abweichungsbezug

Sinnvoll fuer:

- Template-Luecken finden
- echte Prozessabweichungen sichtbar machen
- Ruecklaeufer oder Sonderwege erkennen

## 8. Combined-Chart

Zweck: Flow, Dwell und Deviations gemeinsam sehen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined
```

Sinnvoll fuer:

- Gesamtueberblick
- Workshop mit Fachbereich
- erste Analyse nach Datenimport

Nachteil: Kann bei grossen Prozessen schnell dicht werden. Fuer Detailanalyse besser danach gezielt `flow`, `dwell` oder `deviations` nutzen.

## 9. Audit-Chart mit Context-Change-Annotationen

Zweck: Erklaeren, ob eine Decision Rule Violation durch spaetere Context-Korrektur plausibel wird.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --diagram-mode=audit
```

APRIL zeigt nur relevante Context-Aenderungen:

- Es existiert eine Decision Rule Violation.
- Nach dem betroffenen Decision Point wurde ein regelrelevantes Feld geaendert.
- Das Feld wird in der betroffenen Decision Rule oder ihren `required_fields` verwendet.

Darstellung:

- gelber Annotation-Knoten
- gestrichelte Verbindung zum Decision-Knoten
- Inhalt: Feld, alter Wert, neuer Wert, betroffene Decision

Beispielinhalt:

```text
Context changed
amount_net: 4149788 -> 41.49
affected decisions: route_after_pruefung
```

Wichtig:

- Es werden nicht alle Context Changes angezeigt.
- Vollstaendige Context Snapshots werden nicht in Prozessknoten geschrieben.
- Die Template-Check-Logik wird nicht veraendert.
- Eine DEVIATION wird dadurch nicht automatisch zu einer WARNING.

## 10. Chart fuer ein einzelnes Dokument

Zweck: Einen konkreten Fall analysieren.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --document-id=900001 \
  --debug-metrics
```

Statt externer Dokument-ID kann auch die Dokument-UUID verwendet werden.

Sinnvoll zusammen mit:

```bash
bin/console intelligence:document:timeline <documentUuid> ai-rechnungen \
  --with-context \
  --with-diff \
  --with-decisions
```

## 11. Zeitraum begrenzen

Zweck: Vergleichbare Auswertungsfenster erzeugen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --from=2026-05-01T00:00:00+02:00 \
  --to=2026-05-31T23:59:59+02:00
```

Sinnvoll fuer:

- Monatsauswertung
- Vorher-/Nachher-Vergleich
- Testdaten von Echtdaten trennen

## 12. Nur die letzte gebaselineten Prozessversion anzeigen

Zweck: Nur Dokumente anzeigen, die nach der aktuellsten Prozess-Baseline gestartet sind und nicht mehr aus aelteren Baseline-Versionen stammen.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --process-version=latest
```

Wenn nur fachlich vollstaendige/OK-Durchlaeufe angezeigt werden sollen:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --process-version=latest \
  --include-ok
```

Mit Diagnoseausgabe:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --process-version=latest \
  --include-ok \
  --debug-metrics
```

Unterschied zu `--from`: `--from` filtert nach Datum. `--process-version=latest` verwendet die letzte in APRIL definierte Prozessversion/Baseline. Aeltere Timelines werden dadurch nicht nur zeitlich, sondern versioniert aus der KPI-/Diagramm-Auswertung ausgeschlossen.

## 13. Nur OK- oder Abweichungsfaelle

Zweck: Diagramm auf bestimmte Dokumentstatus eingrenzen.

Nur Abweichungen:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --include-deviations
```

Nur OK-Faelle:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --include-ok
```

Beides zusammen bedeutet: OK- und Abweichungsdokumente werden explizit eingeschlossen.

## 14. KPI-Ausschluesse nachvollziehen

Zweck: Pruefen, welche Timelines aus Standardauswertungen ausgeschlossen wurden.

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --include-excluded \
  --debug-metrics
```

Typische Ausschlussgruende:

- `no_process_version_defined`
- `started_before_process_version`
- `started_mid_process`
- `crossed_version_boundary`

## 15. Gespeicherte Heatmap verwenden

Zweck: Ein reproduzierbares Diagramm aus einer vorher erzeugten Heatmap rendern.

```bash
bin/console intelligence:template:heatmap ai-rechnungen \
  --template=templates/ai-rechnungen.yaml \
  --format=json \
  --output=templates/ai-rechnungen-heatmap.json \
  --force

bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --metrics=templates/ai-rechnungen-heatmap.json
```

Wichtig: Mit `--metrics` wird bewusst diese Datei verwendet. Sie kann andere Counts zeigen als Live-Auswertungen, wenn sie veraltet ist.

## Empfohlene Standard-Charts

Fuer den Alltag reichen meistens diese vier:

```bash
# 1. Soll-Struktur
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml

# 2. Volumen
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=flow \
  --show-node-metrics

# 3. Engpaesse
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=dwell \
  --show-dwell-legend

# 4. Abweichungen mit Context-Erklaerung
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=deviations \
  --diagram-mode=audit

# 5. Nur letzte Baseline-Version, nur OK-Durchlaeufe
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --process-version=latest \
  --include-ok
```

## Ausgabe speichern

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --view=combined \
  --output=var/ai-rechnungen-combined.mmd
```

Die Datei ist Mermaid-Markdown und kann z. B. in Mermaid Live Editor, Obsidian oder in einer Doku-Pipeline gerendert werden.

Fuer Obsidian-kompatiblere Edge-Labels:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml \
  --compat=obsidian \
  --output=var/ai-rechnungen-obsidian.mmd
```
