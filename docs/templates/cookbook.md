# APRIL Template DSL Cookbook

Dieses Cookbook zeigt kleine, aktuell unterstützte Muster. Es ergänzt die [Template DSL Reference](./reference.md).

## Linearer Prozess

```yaml
key: eingangsrechnung
version: 1.0

steps:
  - key: "01 Eingang"
  - key: "02 Pruefung"
  - key: "03 Freigabe"

transitions:
  - from: "01 Eingang"
    to: "02 Pruefung"
  - from: "02 Pruefung"
    to: "03 Freigabe"
```

Ohne `scope` gilt `scope: process`.

## Bedingter Prozesspfad

```yaml
key: ausgangsrechnung
version: 1.0

steps:
  - key: "01 Pruefung"
  - key: "02 Freigabe klein"
  - key: "03 Freigabe gross"
  - key: "04 Abschluss"

field_mapping:
  amount_net:
    source: amagno_tag
    tag_name: Nettobetrag
    value_type: number
    stability: snapshot_required

decision_points:
  - key: freigabe_ab_1000
    after: "02 Freigabe klein"
    required_fields:
      - amount_net
    rules:
      - when:
          amount_net:
            gt: 1000
        expect_next: "03 Freigabe gross"
      - else:
          expect_next: "04 Abschluss"
```

## Optionaler Journey-Step mit `when`

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

transitions:
  - from: import
    to: pruefung
```

Wenn `amagno_known` nicht zu `false` passt, wird `import` als `CONDITION_NOT_APPLICABLE` bewertet.

## Generischer Import, fachlicher Prozess, generischer Export

```yaml
key: aufmass_verarbeitung
version: 1.0
scope: journey

steps:
  - key: import
    type: process
    process_key: generic_document_import
    required: true

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

Ein Detailtemplate für `generic_document_import`, `aufmass_pruefung` oder `nevaris_export` ist optional. Die Journey-Prüfung blockiert nicht, wenn es fehlt.

## Cross-Process-Routing

```yaml
key: debitoren_intake
version: 1.0

steps:
  - key: "10 Intake abgeschlossen"

cross_process_routing:
  - key: route_to_aufmass
    after_step: "10 Intake abgeschlossen"
    when:
      document_type: aufmass
    expected_process: aufmass_workflow
```

Das prüft read-only, ob nach dem Source-Step ein Zielprozess mit `processKey = aufmass_workflow` für dasselbe Dokument existiert.

## Detailtemplate als optionaler Drilldown

Journey:

```yaml
key: aufmass_verarbeitung
version: 1.0
scope: journey

steps:
  - key: pruefung
    type: process
    process_key: aufmass_pruefung
    required: true
```

Optionales Detailtemplate:

```yaml
key: aufmass_pruefung
version: 1.0

steps:
  - key: "01 Eingang"
  - key: "02 Sachpruefung"
  - key: "03 Abschluss"

transitions:
  - from: "01 Eingang"
    to: "02 Sachpruefung"
  - from: "02 Sachpruefung"
    to: "03 Abschluss"
```

Die Journey prüft nur, ob `aufmass_pruefung` existiert. Die interne Prüfung des Detailtemplates ist ein separater Check.
