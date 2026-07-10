# APRIL Template DSL Reference

Stand: aktueller Codezustand. Diese Referenz beschreibt nur Syntax, die `ProcessTemplateArrayFactory` und die vorhandenen Checker heute parsen oder auswerten.

## 1. Überblick

APRIL Templates beschreiben erwartetes Verhalten auf Basis beobachteter Events, ProcessInstances und ContextSnapshots. Sie starten keine Prozesse, erzeugen keine Queue-Jobs und mutieren keine Runtime-Daten. Checks sind read-only Analysen.

Die Ebenen sind:

- Journey: fachliche Klammer über mehrere Prozesse.
- Process: ein beobachteter Prozess mit `processKey`.
- Step: fachlicher Schritt innerhalb eines Prozess-Templates, geprüft über `stepKey`.
- Event: gespeichertes Prozessereignis mit `processKey`, `stepKey`, `eventPhase`, Item-Bezug und optionalem Context.

## 2. Root-Felder

Unterstützte Root-Felder im Template-Modell:

| Feld | Bedeutung | Default |
| --- | --- | --- |
| `key` | Template-Key. Bei Process-Templates meist identisch zum Runtime-`processKey`. | leerer String |
| `version` | Template-Version. | `draft` |
| `name` | Anzeigename. | `null` |
| `scope` | Template-Ebene: aktuell `process` oder `journey` als Konvention. | `process` |
| `initial_step` / `initialStepKey` | Initialer Step-Key. | `null` |
| `source_system` / `sourceSystem` | Quellsystem. Der aktuelle Parser hat aus Legacy-Gruenden noch einen Default, neue Community-Templates sollten das Feld explizit und neutral setzen. | `amagno` |

`scope` wird aktuell geparst und vom Journey-Checker als fachliche Kennzeichnung genutzt. Bestehende Templates ohne `scope` bleiben Process-Templates.

`intelligence:template:suggest-from-document` verwendet dieselbe Kennzeichnung:
`scope: process` beziehungsweise fehlendes `scope` fuehrt zur bisherigen
Process-Suggestion, `scope: journey` zur Journey-Suggestion ueber die
prozessuebergreifende Dokument-Timeline. Ohne vorhandenes Zieltemplate bleibt
`process` der kompatible Default; fuer neue Journey-Entwuerfe kann
`--scope=journey` gesetzt werden.

## 3. Process-Templates

Process-Templates beschreiben erwartete `stepKey`-Abläufe innerhalb eines `processKey`. Der aktuelle Process-Check läuft über `ProcessTemplateCheckService`.

### Steps

```yaml
key: incident-management
version: 1.0
scope: process

steps:
  - key: incident_received
    name: Incident received

  - key: classify_incident
    type: start
```

Für klassische Steps sind `key`, optional `name` und optional `type` relevant. Wenn `type` fehlt, setzt der Parser `normal`. Bestehende Werte wie `start` bleiben erhalten; nur `type: process` hat im Journey-Checker eine besondere Semantik.

### Required Steps

```yaml
required_steps:
  - incident_received
  - classify_incident
```

Wenn `required_steps` gesetzt ist, verwendet der Process-Check diese Liste als globale Pflichtschritte. Ohne `required_steps` werden die Keys aus `steps` als erwartete Steps verwendet.

### Transitions

```yaml
transitions:
  - from: incident_received
    to: classify_incident
```

Transitions referenzieren Template-Step-Keys. Der Parser validiert, dass `from` und `to` bekannte Steps sind. Alternativ kann eine Transition auf eine Parallelgruppe zeigen:

```yaml
transitions:
  - from: classify_incident
    to_parallel_group: security_and_business_review
```

Genau eines von `to` oder `to_parallel_group` muss gesetzt sein.

### Parallelgruppen

```yaml
parallel_groups:
  - key: buchen_und_zahlung
    required_steps:
      - trigger_security_review
      - route_to_specialist_group
    order: any
    next: close_incident
```

Der aktuelle Process-Check unterstützt `order: any` für reihenfolgeunabhängige Pflichtschritte. `next` beschreibt den erwarteten Step nach vollständiger Gruppe.

### Decision Rules

```yaml
decision_points:
  - key: route_after_classification
    after: classify_incident
    required_fields:
      - severity
    rules:
      - when:
          severity:
            in: [high, critical]
        expect_next: escalate_to_saas_provider

      - else:
          expect_next_parallel_group: security_and_business_review
```

Decision Points werden nach dem Step in `after` bewertet. `rules[].expect_next` erwartet einen Step. `rules[].expect_next_parallel_group` aktiviert eine Parallelgruppe. Ein `else`-Block ist möglich.

Die Operatornotation in `when` ist nur für Process-Decision-Rules implementiert, nicht für Journey-`steps[].when`.

### Cross-Process-Routing

```yaml
cross_process_routing:
  - key: route_to_security_review
    after_step: intake_completed
    when:
      category: security
    expected_process: security_review
```

Cross-Process-Routing ist eine read-only Pruefung: Nach einem Source-Step wird geprueft, ob fuer dasselbe Item der erwartete Zielprozess existiert. Es startet keinen Zielprozess.

`when` ist hier ein Equality-Shorthand. Alle angegebenen Felder müssen im Context passen.

## 4. Journey-Templates

Journey-Templates beschreiben eine fachliche Klammer über vorhandene Prozesse. Der aktuelle MVP wird über `JourneyTemplateCheckService` geprüft. Es gibt dafür aktuell noch keinen eigenen CLI-Command.

Ein Journey-Step vom Typ `process` ist ohne eigenes Detailtemplate pruefbar. APRIL prueft dann nur, ob Events oder eine ProcessInstance mit dem angegebenen `process_key` fuer das Item existieren. Ein Detailtemplate ist optionaler Drilldown und blockiert den Journey-Check nicht.

```yaml
key: incident_journey
version: 1.0
scope: journey

match:
  any_process:
    - incident-management

steps:
  - key: intake
    type: process
    process_key: incident_intake
    required: true
    when:
      imported: true

  - key: triage
    type: process
    process_key: incident-management
    required: true

  - key: follow_up
    type: process
    process_key: specialist_follow_up
    required: true
    when:
      category: business_process

transitions:
  - from: intake
    to: triage

  - from: triage
    to: follow_up
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

### Unerwartete Prozesse

Der Journey-Check betrachtet die vollstaendige Dokument-Timeline. Alle
`process_key`s aus Journey-Steps mit `type: process` gelten im aktuell geprueften
Journey-Template als erlaubt, auch wenn ein Step optional ist oder mehrfach
vorkommt. Kommt in der Timeline ein anderer Prozess vor, erzeugt APRIL ein
maschinenlesbares Finding mit `code: UNEXPECTED_PROCESS` und der Meldung
`Kritische Abweichung: Unerwarteter Prozess außerhalb des Templates`. Dies ist
eine fachliche `DEVIATION` mit `severity: CRITICAL`, weil das Dokument einen
nicht modellierten Prozess durchlaufen hat.

`match.any_process` dient nur zur Kandidatenermittlung. Ein Match-Prozess ist
nicht automatisch erlaubt, wenn er nicht zugleich als Journey-Step modelliert ist.
Gemeinsame optionale Einstiegsschritte anderer Journeys werden nicht global
ignoriert; erlaubt ist ein Prozess nur im Kontext des aktuell geprueften
Journey-Templates.

Process-Template-Checks sind enger geschnitten: sie filtern die Dokument-Timeline
auf den geprueften `processKey` und koennen deshalb fremde Prozesse derselben
Dokumenthistorie nicht als `UNEXPECTED_PROCESS` erkennen. Innerhalb eines
Process-Templates bleiben unbekannte `stepKey`s weiterhin normale
Process-Deviations wie `Unexpected step`.

### Journey Match

`match` beschreibt, welche Dokumente Kandidaten fuer eine Journey sind. Im MVP
wird nur OR-Semantik ueber Prozess-Keys unterstuetzt:

```yaml
match:
  any_process:
    - RM_TEST_aufmass
    - RM_TEST_NevarisExport
```

Ein Dokument ist Kandidat, sobald mindestens einer dieser Prozesse irgendwo in
seiner Timeline vorkommt. Die Match-Regel dient nur zur Kandidatenermittlung.
Nach einem Match laedt APRIL weiterhin die vollstaendige Dokument-Timeline ueber
alle Prozesse und prueft sie gegen das Journey-Template; Eintraege vor dem
Match-Prozess werden nicht abgeschnitten.

Gemeinsame optionale Einstiegsschritte sind dadurch moeglich. Ein allgemeiner
Prozess wie `RM_TEST_dokumenten_eingang` kann in mehreren Journey-Templates als
optionaler Step vorkommen, ohne allein alle diese Journeys zu matchen. Nur der
jeweilige `match`-Block entscheidet ueber die Kandidatenzuordnung. Ein Dokument
kann Kandidat mehrerer Journeys sein, wenn es mehrere Match-Regeln erfuellt.

Legacy-Fallback: Journey-Templates ohne `match` bleiben zunaechst pruefbar. APRIL
verwendet dann den ersten erforderlichen Journey-Step mit `type: process` und
gesetztem `process_key` als impliziten Match-Prozess. Optionale erste Steps werden
dabei bewusst uebersprungen. Gibt es keinen erforderlichen Prozess-Step, entsteht
ein nicht-matchbarer Zustand mit Warning statt eines unkontrollierten Fehlers.

`match` ist fachlich nur fuer `scope: journey` zulaessig. `any_process` muss eine
Liste nichtleerer Strings sein; doppelte Prozess-Keys werden beim Parsen
deterministisch auf den ersten Eintrag reduziert.

### Nicht im Journey-MVP

- keine Journey-Decision-Rules
- keine interne Detailtemplate-Prüfung
- keine Prozessausführung
- keine Queue
- keine Mutationen
- keine Subprozessmodellierung
- keine persistierte Journey-Dokumentzuordnung
- keine exklusiven Dokument-zu-Journey-Zuordnungen
- keine komplexen booleschen Match-Regeln
- kein Match ueber Context-Felder
- keine Zeitfenster oder Segmentierung mehrerer Journey-Durchlaeufe

### Journey-Suggestion aus Dokumenten

Der MVP fuer `suggest-from-document` leitet Journey-Templates ohne LLM aus einer
einzelnen Dokument-Timeline ab. Jeder beobachtete `processKey` wird als
`type: process`-Step mit `process_key` und `required: true` vorgeschlagen; direkte
Wiederholungen desselben Prozesses werden zu einem Step zusammengezogen. Wenn
derselbe Prozess spaeter erneut auftaucht, werden stabile Step-Keys wie
`process_key_2` verwendet. Zwischen aufeinanderfolgenden beobachteten
Journey-Steps werden Transitions vorgeschlagen.

Nicht automatisch abgeleitet werden `when`, optionale Prozesse,
Decision-Points, statistische Haeufigkeiten oder Persistenz. Gegen vorhandene
Journey-Templates wird nur eine Preview beziehungsweise ein erweiterter
YAML-Vorschlag erzeugt; bestehende Steps und Transitions werden nicht doppelt
vorgeschlagen.

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
    - category
    - severity
```

`field_mapping` beschreibt, woher Felder technisch kommen:

```yaml
field_mapping:
  category:
    source: event_context
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
  category: business_process
  requires_specialist: true
```

Alle Felder müssen passen. Cross-Process-Routing und Journey-Check vergleichen Scalars robust: Bool-Werte, numerische Werte und Strings werden stabil verglichen. Arrays und Objekte werden nicht implizit gleichgesetzt.

## 6. Operatoren

Operatornotation ist aktuell bei Process-Decision-Rules implementiert:

```yaml
when:
  severity:
    in: [high, critical]
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
      severity:
        in: [high, critical]
    expect_next: escalate_to_saas_provider

  - when:
      category:
        in: [security, business_process]
    expect_next: route_to_specialist_group

  - when:
      owner_group:
        exists: true
    expect_next: close_incident
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
- `WARNING`: Version ist mehrdeutig oder Zielprozess existiert nur in anderer Item-Version.
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
- Nutze generische Prozesse wieder, z. B. Intake, Triage oder Follow-up, und beschreibe fachliche Klammern als Journey.
- Überlade Journey-Templates nicht mit Detailprozesslogik. Detailtemplates bleiben optionaler Drilldown.
- Sammle zuerst Events und ContextSnapshots, dann schärfe Templates anhand echter Timelines.
- Dokumentiere Context-Felder mit `context_profile.required` und `field_mapping`, besonders wenn sie Decisions beeinflussen.
- Verwende `cross_process_routing`, wenn Prozess A einen Zielprozess B erwartet.
- Verwende `scope: journey`, wenn ein fachlicher Case mehrere vorhandene Prozesse umfasst.
