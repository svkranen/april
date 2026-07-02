# APRIL Template DSL Reference

Stand: aktueller Codezustand. Diese Referenz beschreibt nur Syntax, die `ProcessTemplateArrayFactory` und die vorhandenen Checker heute parsen oder auswerten.

## 1. Überblick

APRIL Templates beschreiben erwartetes Verhalten auf Basis beobachteter Events, ProcessInstances und ContextSnapshots. Sie starten keine Prozesse, erzeugen keine Queue-Jobs und mutieren keine Runtime-Daten. Checks sind read-only Analysen.

Die Ebenen sind:

- Journey: fachliche Klammer über mehrere Prozesse.
- Process: ein beobachteter Prozess mit `processKey`.
- Step: fachlicher Schritt innerhalb eines Prozess-Templates, geprüft über `stepKey`.
- Event: gespeichertes Prozessereignis mit `processKey`, `stepKey`, `eventPhase`, Dokumentbezug und optionalem Context.

## 2. Root-Felder

Unterstützte Root-Felder im Template-Modell:

| Feld | Bedeutung | Default |
| --- | --- | --- |
| `key` | Template-Key. Bei Process-Templates meist identisch zum Runtime-`processKey`. | leerer String |
| `version` | Template-Version. | `draft` |
| `name` | Anzeigename. | `null` |
| `scope` | Template-Ebene: aktuell `process` oder `journey` als Konvention. | `process` |
| `initial_step` / `initialStepKey` | Initialer Step-Key. | `null` |
| `source_system` / `sourceSystem` | Quellsystem. | `amagno` |

`scope` wird aktuell geparst und vom Journey-Checker als fachliche Kennzeichnung genutzt. Bestehende Templates ohne `scope` bleiben Process-Templates.

## 3. Process-Templates

Process-Templates beschreiben erwartete `stepKey`-Abläufe innerhalb eines `processKey`. Der aktuelle Process-Check läuft über `ProcessTemplateCheckService`.

### Steps

```yaml
key: ai-rechnungen
version: 1.0
scope: process

steps:
  - key: "01 Eingang"
    name: Eingang

  - key: "03 Freigabe"
    type: start
```

Für klassische Steps sind `key`, optional `name` und optional `type` relevant. Wenn `type` fehlt, setzt der Parser `normal`. Bestehende Werte wie `start` bleiben erhalten; nur `type: process` hat im Journey-Checker eine besondere Semantik.

### Required Steps

```yaml
required_steps:
  - "01 Eingang"
  - "03 Freigabe"
```

Wenn `required_steps` gesetzt ist, verwendet der Process-Check diese Liste als globale Pflichtschritte. Ohne `required_steps` werden die Keys aus `steps` als erwartete Steps verwendet.

### Transitions

```yaml
transitions:
  - from: "01 Eingang"
    to: "03 Freigabe"
```

Transitions referenzieren Template-Step-Keys. Der Parser validiert, dass `from` und `to` bekannte Steps sind. Alternativ kann eine Transition auf eine Parallelgruppe zeigen:

```yaml
transitions:
  - from: "03 Freigabe"
    to_parallel_group: buchen_und_zahlung
```

Genau eines von `to` oder `to_parallel_group` muss gesetzt sein.

### Parallelgruppen

```yaml
parallel_groups:
  - key: buchen_und_zahlung
    required_steps:
      - "05 Buchen"
      - "07 Zahlung erwartet"
    order: any
    next: "09 Abschluss"
```

Der aktuelle Process-Check unterstützt `order: any` für reihenfolgeunabhängige Pflichtschritte. `next` beschreibt den erwarteten Step nach vollständiger Gruppe.

### Decision Rules

```yaml
decision_points:
  - key: freigabe_ab_1000
    after: "03 Freigabe"
    required_fields:
      - amount_net
    rules:
      - when:
          amount_net:
            gt: 1000
        expect_next: "04 Freigabe gross"

      - else:
          expect_next_parallel_group: buchen_und_zahlung
```

Decision Points werden nach dem Step in `after` bewertet. `rules[].expect_next` erwartet einen Step. `rules[].expect_next_parallel_group` aktiviert eine Parallelgruppe. Ein `else`-Block ist möglich.

Die Operatornotation in `when` ist nur für Process-Decision-Rules implementiert, nicht für Journey-`steps[].when`.

### Cross-Process-Routing

```yaml
cross_process_routing:
  - key: route_to_aufmass
    after_step: "10 Intake abgeschlossen"
    when:
      document_type: aufmass
    expected_process: aufmass_workflow
```

Cross-Process-Routing ist eine read-only Prüfung: Nach einem Source-Step wird geprüft, ob für dasselbe Dokument der erwartete Zielprozess existiert. Es startet keinen Zielprozess.

`when` ist hier ein Equality-Shorthand. Alle angegebenen Felder müssen im Context passen.

## 4. Journey-Templates

Journey-Templates beschreiben eine fachliche Klammer über vorhandene Prozesse. Der aktuelle MVP wird über `JourneyTemplateCheckService` geprüft. Es gibt dafür aktuell noch keinen eigenen CLI-Command.

Ein Journey-Step vom Typ `process` ist ohne eigenes Detailtemplate prüfbar. APRIL prüft dann nur, ob Events oder eine ProcessInstance mit dem angegebenen `process_key` für das Dokument existieren. Ein Detailtemplate ist optionaler Drilldown und blockiert den Journey-Check nicht.

```yaml
key: aufmass_verarbeitung
version: 1.0
scope: journey

steps:
  - key: import
    type: process
    process_key: generic_document_import
    required: true
    when:
      amagno_known: false

  - key: pruefung
    type: process
    process_key: aufmass_pruefung
    required: true

  - key: export
    type: process
    process_key: nevaris_export
    required: true
    when:
      document_type: aufmass
      accounting_required: true

transitions:
  - from: import
    to: pruefung

  - from: pruefung
    to: export
```

### Journey Step-Felder

| Feld | Bedeutung | Default |
| --- | --- | --- |
| `key` | Journey-Step-Key. Transitions referenzieren diesen Key. | Pflicht für Parsing |
| `type` | Bei `process`: erfülle Step über `process_key`. Andere Werte werden vom Journey-MVP nicht geprüft. | `normal` |
| `process_key` / `processKey` | Runtime-`processKey`, der für `type: process` existieren muss. | `null` |
| `required` | Fehlt ein anwendbarer required Process-Step, ist das eine Deviation. | `true` |
| `when` | Equality-Shorthand gegen Context. | `{}` |

### Journey Transitions

Journey-Transitions laufen zwischen Journey-Step-Keys, nicht zwischen `process_key`s.

Bei `type: process` wird der Zeitpunkt aus dem ersten Event des jeweiligen `process_key` genommen. Wenn nur eine ProcessInstance ohne Event-Zeitpunkt vorhanden ist, kann die Existenz erfüllt sein, aber die Reihenfolge nur mit `WARNING` bewertet werden.

### Nicht im Journey-MVP

- keine Journey-Decision-Rules
- keine interne Detailtemplate-Prüfung
- keine Prozessausführung
- keine Queue
- keine Mutationen
- keine Subprozessmodellierung

## 5. Bedingungen und Context

APRIL arbeitet mit ContextSnapshots und Event-Context.

Aktuelle Context-Quellen:

- Process-Check: nutzt `DocumentTimelineEventRow.contextSummary.attributes`.
- Cross-Process-Routing: bevorzugt strukturierte `ContextSnapshot`s, fällt auf `contextSummary.attributes` zurück.
- Journey-Check: bevorzugt strukturierte `ContextSnapshot`s, fällt auf `contextSummary.attributes` zurück.

`context_profile.required` deklariert benötigte fachliche Felder:

```yaml
context_profile:
  required:
    - document_type
    - amount_net
```

`field_mapping` beschreibt, woher Felder technisch kommen:

```yaml
field_mapping:
  document_type:
    source: amagno_tag
    tag_name: Dokumentart
    value_type: string
    stability: snapshot_required
```

Unterstützte `stability`-Werte sind:

- `immutable`
- `mutable`
- `snapshot_required`

Decision-Felder müssen im aktuellen Process-Check eine Stability im `field_mapping` haben.

### Equality-Shorthand

`when` in Cross-Process-Routing und Journey-Steps ist ein Equality-Shorthand:

```yaml
when:
  document_type: aufmass
  accounting_required: true
```

Alle Felder müssen passen. Cross-Process-Routing und Journey-Check vergleichen Scalars robust: Bool-Werte, numerische Werte und Strings werden stabil verglichen. Arrays und Objekte werden nicht implizit gleichgesetzt.

## 6. Operatoren

Operatornotation ist aktuell bei Process-Decision-Rules implementiert:

```yaml
when:
  amount_net:
    gte: 1000
```

Unterstützte Operatoren:

| Operator | Bedeutung |
| --- | --- |
| `eq` | Feld existiert und ist gleich. Der Code verwendet PHPs lockeren Vergleich (`==`). |
| `neq` | Feld existiert und ist ungleich. Der Code verwendet PHPs lockeren Vergleich (`!=`). |
| `gt` | Numerisch größer als. |
| `gte` | Numerisch größer oder gleich. |
| `lt` | Numerisch kleiner als. |
| `lte` | Numerisch kleiner oder gleich. |
| `in` | Exakter Vergleich gegen eine Liste (`in_array` strict). |
| `exists` | Prüft, ob das Feld existiert und nicht `null` ist. |

Beispiele:

```yaml
rules:
  - when:
      amount_net:
        gt: 1000
    expect_next: "04 Freigabe gross"

  - when:
      document_type:
        in: [aufmass, ausgangsrechnung]
    expect_next: "03 Fachpruefung"

  - when:
      cost_center:
        exists: true
    expect_next: "05 Buchen"
```

Für SignChecks ist aktuell nur `required_subset_of_actual` unterstützt.

## 7. Ergebnisstatus

### Process-Check

`ProcessTemplateCheckResult::status()` liefert:

- `DEVIATION`, wenn Abweichungen oder nicht erfüllte SignChecks existieren.
- `OK`, wenn keine Abweichungen, keine Context-Issues und keine Context-Sonderlage existieren.
- `WARNING`, wenn Context-Issues existieren.
- Context-Sonderstatus aus dem Check, z. B. `UNCERTAIN_CONTEXT_STALE`, `UNCERTAIN_CONTEXT_TIME_SKEW`, `UNCHECKABLE_CONTEXT_MISSING`.

Die Prozess-CLI `intelligence:template:check-process` verdichtet zusätzlich einzelne Warnmeldungen in `WARNING` oder `DEVIATION`.

### Cross-Process-Routing

Gesamt- und Rule-Status:

- `SATISFIED`
- `DEVIATION`
- `WARNING`
- `NOT_APPLICABLE`

Typische Gründe:

- `SATISFIED`: erwarteter Zielprozess existiert plausibel.
- `DEVIATION`: Zielprozess fehlt oder startet vor dem Routing-Event.
- `WARNING`: Version ist mehrdeutig oder Zielprozess existiert nur in anderer Dokumentversion.
- `NOT_APPLICABLE`: Routing-Event fehlt oder `when` matcht nicht.

### Journey-Check

Gesamtstatus:

- `SATISFIED`
- `DEVIATION`
- `WARNING`
- `NOT_APPLICABLE`

Stepstatus:

- `PROCESS_EXISTS`
- `MISSING_REQUIRED_PROCESS`
- `CONDITION_NOT_APPLICABLE`
- `WARNING`

Transitionstatus:

- `SATISFIED`
- `PROCESS_EXISTS_WRONG_ORDER`
- `WARNING`
- `NOT_APPLICABLE`

## 8. Best Practices

- Verwende stabile, sprechende `key`s. Ändere Keys nicht leichtfertig, weil sie in Transitions und Rules referenziert werden.
- Wähle `process_key`s fachlich eindeutig und unabhängig von UI-Texten.
- Nutze generische Prozesse wieder, z. B. Import oder Export, und beschreibe fachliche Klammern als Journey.
- Überlade Journey-Templates nicht mit Detailprozesslogik. Detailtemplates bleiben optionaler Drilldown.
- Sammle zuerst Events und ContextSnapshots, dann schärfe Templates anhand echter Timelines.
- Dokumentiere Context-Felder mit `context_profile.required` und `field_mapping`, besonders wenn sie Decisions beeinflussen.
- Verwende `cross_process_routing`, wenn Prozess A einen Zielprozess B erwartet.
- Verwende `scope: journey`, wenn ein fachlicher Case mehrere vorhandene Prozesse umfasst.
