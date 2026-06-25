# Konzept: Human-readable Prozessdokumentation

Dieses Konzept beschreibt, wie APRIL aus YAML-Prozess-Templates eine
vollstaendige, fachlich lesbare Prozessdokumentation erzeugen kann.

Es ist bewusst ein Dokumentationskonzept und keine Implementierung.

## Ausgangslage

- APRIL-Prozess-Templates liegen unter `config/april/process-templates/`.
- `templates/` ist fuer Symfony/Twig/Frontend reserviert und darf nicht fuer
  APRIL-Prozess-YAMLs verwendet werden.
- Die YAML-Prozess-Templates sind die Source of Truth fuer den Soll-Prozess.
- Access-/Visibility-Dokumentation existiert bereits als eigener Baustein:
  - `intelligence:template:access-document <processKey> --format=markdown|html`
  - `intelligence:template:access-coverage <processKey>`
- Access-/Visibility nutzt bereits:
  - `access_probes`
  - `visibility_check_profiles`
  - `visibility_profile_resolvers`
  - `visibility_retry_policies`
  - `manual_access_tests`
  - `before`/`after` `visibility_checks` an Steps
- Weitere Template-Bausteine sind vorhanden:
  - `steps`
  - `transitions`
  - `parallel_groups`
  - `context_profile`
  - `field_mapping`
  - `decision_points`
  - `sign_checks`
  - `connector`
  - `context_policy`
  - `source_system` / `sourceSystem`

## Ziel

Ein neuer uebergreifender Dokumentationsbaustein soll spaeter aus einem
Prozess-Template eine vollstaendige Prozessdokumentation erzeugen koennen, z. B.:

```bash
bin/console intelligence:template:document ai-rechnungen --format=markdown
bin/console intelligence:template:document ai-rechnungen --format=html
bin/console intelligence:template:document ai-rechnungen --format=html --output=docs/generated/process-ai-rechnungen.html
```

Die Dokumentation soll fachlich lesbar sein, aber vollstaendig aus dem YAML
ableitbar bleiben.

## Grundprinzip: Soll-Dokumentation vs. Runtime-Daten

Die Prozessdokumentation ist zunaechst eine Soll-Dokumentation aus dem Template.
Sie beschreibt, was fachlich erwartet wird.

Runtime-Daten sind getrennt davon zu behandeln:

- Prozessereignisse
- Context Snapshots
- gespeicherte VisibilityCheckResults
- Heatmaps
- Timeline-/Dokumentcheck-Ergebnisse

Diese Runtime-Daten koennen spaeter optional als Audit- oder Auswertungsabschnitt
eingebunden werden. Sie duerfen aber nicht die Template-Dokumentation als Source
of Truth ersetzen.

## Zielbild der Gesamtprozess-Dokumentation

### Titel / Metadaten

- Prozess-Key
- Version
- Name/Titel, falls vorhanden
- `sourceSystem`
- Beschreibung, falls vorhanden
- Template-Dateipfad unter `config/april/process-templates/`
- Generiert am
- optional spaeter Owner, Scope, Zielgruppe, Lifecycle-Status

### Management Summary

- initial step
- Anzahl Schritte
- Anzahl Uebergaenge
- Anzahl Decision Points
- Anzahl Parallelgruppen
- Anzahl SignChecks
- Anzahl Access-Probes
- Anzahl Access-/Visibility-Checks
- Anzahl manueller Pruefpunkte
- Hinweis, dass das YAML die Source of Truth fuer den Soll-Prozess ist

### Prozessschritte

Je Step:

- `stepKey`
- Label / Name, falls vorhanden
- Beschreibung, falls vorhanden
- Typ
- erwartete Eingaben, falls modelliert
- before-Pruefungen
- fachliche Aktion / Stempel, falls modelliert
- after-Pruefungen
- erwartete Ausgaben / Zielzustaende, falls modelliert
- Rollen, falls vorhanden
- relevante Context-Felder
- Access-/Visibility Controls an diesem Schritt

`before` und `after` sind Kontrollphasen am gleichen fachlichen `stepKey`; sie
duerfen nicht als eigene Prozessschritte dokumentiert werden.

### Uebergaenge / Soll-Prozess

- explizite `transitions`
- Default-Reihenfolge aus `steps`, soweit fachlich genutzt
- `from`
- `to`
- `to_parallel_group`
- erlaubte naechste Schritte
- Sonderfaelle
- Darstellung von Parallelgruppen

### Parallelgruppen

- Key
- Startpunkt (`after`)
- erforderliche Steps
- Reihenfolge (`order`)
- Folgeschritt (`next`)
- fachliche Interpretation: parallele oder beliebig sortierbare Pflichtteile

### Decision Points

- Key
- Beschreibung, falls vorhanden
- `after`
- `required_fields`
- Regeln
- `when`-Bedingungen
- erwarteter naechster Step
- erwartete Parallelgruppe
- `else`-/Default-Logik
- fachliche Erklaerung in Textform

Beispiel fuer eine fachliche Beschreibung:

```text
Wenn amount_net > 1000, dann folgt "04 Freigabe_gross".
Andernfalls geht das Dokument in die Parallelgruppe "buchen_und_zahlung".
```

### Context / Field Mapping

- `context_profile.required`
- `field_mapping`
- sourceSystem-spezifische Felder, z. B. Amagno-Merkmale
- `tag_id`
- `tag_name`
- `value_type`
- `stability`
- `context_policy`
- bekannte Platzhalter, z. B. `cost_center` als Platzhalter fuer
  `project_location` / `"Standort (Projekt)"`
- Warnhinweise fuer fehlende produktive Tag-Mappings

Fehlende Beschreibungen oder produktive Tag-Mappings sollten als
Dokumentationswarnung erscheinen, nicht als Parserfehler.

### SignChecks / Mehrpersonenfreigaben

- Check-Key
- Label
- Soll-Feld
- Ist-Feld
- Operator
- erwartete Anzahl / Rollenlogik, soweit aus Feldern ableitbar
- fachliche Erklaerung
- Grenzen der automatischen Pruefung

### Access-/Visibility Controls

Die bestehende Access-Dokumentation soll wiederverwendet werden, nicht
dupliziert.

Einzubinden sind:

- Coverage Summary
- Access-Probes
- Visibility Profiles
- Visibility Profile Resolvers
- Visibility Retry Policies
- before/after Visibility Checks
- Manual Access Tests
- Grenzen: APRIL prueft definierte Access-Probes, aber baut keine vollstaendige
  Amagno-ACL-Engine nach

Access-/Visibility-Checks sollen in Diagrammen als Annotation am Step erscheinen,
nicht als eigene Prozessknoten.

### Manuelle Pruefpunkte / Pruefprotokoll

Aus `manual_access_tests`:

- Key
- Titel
- Beschreibung
- Test Procedure
- Expected Result
- Evidence Required
- Frequency

Spaeter kann ein separates Ergebnisobjekt fuer manuelle Pruefungen ergaenzt
werden, z. B. mit Pruefer, Datum, Status und Evidenz-Verweis. Diese Ergebnisse
gehoeren nicht in das Template selbst.

### Automatisierte Auswertungen / Reports

Die Doku sollte erklaeren, welche vorhandenen Commands welche Aussage pruefen:

- `intelligence:template:check-document`
- `intelligence:template:check-process`
- `intelligence:access:check-document`
- `intelligence:access:results`
- `intelligence:document:timeline`
- `intelligence:template:heatmap`
- `intelligence:template:export-diagram`
- `intelligence:template:bpmn-view`
- `intelligence:context:coverage`
- `intelligence:document:context-history`

Dieser Abschnitt dokumentiert Bedienbarkeit und Audit-Nachvollziehbarkeit. Er
soll im MVP keine Runtime-Daten laden.

### Diagrammabschnitt

Moegliche Inhalte:

- Mermaid-Code
- BPMN-like Mermaid
- eingebettetes standalone SVG
- Link auf generierte Artefakte unter `var/april/generated/diagrams/`

Fuer den ersten Schritt ist der Diagrammabschnitt optional. Wenn er aufgenommen
wird, sollte er vorhandene Diagramm-Services wiederverwenden.

### Grenzen / Annahmen

- YAML ist die Source of Truth fuer den Soll-Prozess.
- Runtime-Ergebnisse liegen separat in der Datenbank oder in generierten
  Artefakten.
- Das Template beschreibt Soll-Prozess und Controls, nicht zwingend alle
  Amagno-ACL-Details.
- `before`/`after` sind Kontrollphasen am `stepKey`, keine eigenen Steps.
- APRIL bleibt im Kern DMS-unabhaengig; Amagno-Details gehoeren in Adapter oder
  Provider.
- `templates/` bleibt Symfony/Twig vorbehalten.

## Bestehende Code-Bausteine

### Domain-DTOs

Vorhandene Template-DTOs:

- `ProcessTemplate`
- `ProcessTemplateStep`
- `ProcessTemplateTransition`
- `ProcessTemplateParallelGroup`
- `ProcessTemplateDecisionPoint`
- `ProcessTemplateDecisionRule`
- `ProcessTemplateRuleCondition`
- `ProcessTemplateFieldMapping`
- `ProcessTemplateSignCheck`
- `ProcessTemplateConnector`
- `ProcessTemplateContextPolicy`

Vorhandene Access-/Visibility-DTOs:

- `ProcessTemplateAccessProbe`
- `ProcessTemplateVisibilityProfile`
- `ProcessTemplateVisibilityProfileResolver`
- `ProcessTemplateVisibilityRetryPolicy`
- `ProcessTemplateVisibilityCheck`
- `ProcessTemplateManualAccessTest`

### Provider und Parser

- `YamlProcessTemplateProvider`
  - laedt Templates aus `april.process_template_dir`
- `ProcessTemplateCatalog`
  - listet Templates aus demselben Verzeichnis
- `ProcessTemplateArrayFactory`
  - baut das Domain-DTO aus YAML-Arrays

### Access-Dokumentation

- `AccessDocumentationMarkdownRenderer`
- `AccessDocumentationHtmlRenderer`
- `AccessCoverageReportBuilder`
- `AccessCoverageReport`

Diese Bausteine sind der wichtigste Wiederverwendungspunkt fuer die
Gesamtprozess-Dokumentation.

### Diagramm / BPMN / Heatmap

- `ProcessTemplateGraphFactory`
- `MermaidProcessGraphRenderer`
- `MermaidProcessGraphRenderOptions`
- `ProcessTemplateBpmnViewBuilder`
- `BpmnMermaidRenderer`
- `BpmnSvgRenderer`
- `TemplateHeatmapReportBuilder`
- `TemplateFlowHeatmapBuilder`
- `TemplateDurationHeatmapBuilder`

### Relevante Commands

- `intelligence:template:list`
- `intelligence:template:access-document`
- `intelligence:template:access-coverage`
- `intelligence:template:check-document`
- `intelligence:template:check-process`
- `intelligence:template:export-diagram`
- `intelligence:template:bpmn-view`
- `intelligence:template:heatmap`
- `intelligence:template:suggest-from-document`
- `intelligence:template:suggest-from-documents`
- `intelligence:access:check-document`
- `intelligence:access:results`
- `intelligence:document:timeline`
- `intelligence:document:context-history`
- `intelligence:context:coverage`
- `intelligence:process:status`

## Vorschlag fuer optionale Template-Erweiterungen

Gute human-readable Dokumentation profitiert von optionalen beschreibenden
Feldern. Diese Felder duerfen nicht verpflichtend sein, damit bestehende
Templates rueckwaertskompatibel bleiben.

### Prozess-Metadaten

```yaml
process:
  title: "Eingangsrechnungsprozess"
  description: "..."
  owner: "Rechnungswesen"
  scope: "Eingangs- und Ausgangsrechnungen"
  audience:
    - Audit
    - Fachbereich
  lifecycle_status: active
```

Bewertung:

- `title`, `description`, `owner`, `scope` sind fachlich sinnvoll.
- `audience` kann helfen, Audit- und Fachbereichsdokumentation zu trennen.
- `lifecycle_status` kann spaeter fuer Governance genutzt werden.
- Kein Feld sollte fuer bestehende Templates verpflichtend sein.

### Step-Dokumentation

```yaml
steps:
  - key: "01 Rechnungen pruefen"
    label: "Rechnungen pruefen"
    description: "Die Rechnung wird fachlich geprueft und geroutet."
    roles:
      - Rechnungspruefung
    inputs:
      - invoice_direction
      - amount_net
    outputs:
      - approval_required
    documentation:
      notes:
        - "Betragsgrenzen werden ueber Decision Points abgebildet."
```

Bewertung:

- `label` und `description` verbessern Lesbarkeit deutlich.
- `roles` sind fachlich hilfreich, sollten aber nicht mit Amagno-ACLs
  verwechselt werden.
- `inputs` und `outputs` sind sinnvoll, wenn sie fachlich gepflegt werden.
- `documentation.notes` erlaubt pragmatische Erlaeuterungen ohne neue
  Fachlogik.

### Decision-Point-Dokumentation

```yaml
decision_points:
  - key: route_after_pruefung
    description: "Leitet Ausgangsrechnungen oder Freigaben abhaengig von Richtung und Betrag."
```

Bewertung:

- Optionales `description` ist sinnvoll.
- Die eigentliche Logik bleibt in `required_fields` und `rules`.

### Field-Mapping-Dokumentation

```yaml
field_mapping:
  project_location:
    source: amagno
    tag_name: "Standort (Projekt)"
    value_type: string
    stability: snapshot_required
    documentation:
      placeholder_for:
        - cost_center
      notes:
        - "Produktives Tag-Mapping vor Go-live pruefen."
```

Bewertung:

- Nuetzlich fuer Platzhalter und Migrationshinweise.
- Sollte rein dokumentarisch bleiben und keine Runtime-Logik steuern.

## Architekturvorschlag

### Neuer Command

```bash
bin/console intelligence:template:document <processKey>
```

Optionen:

- `--format=markdown|html`
- `--output=<path>`
- `--include-access`
- `--include-diagram`
- `--include-commands`
- `--include-manual-tests`
- spaeter optional: `--include-runtime`

Default:

- sinnvolle Gesamtdoku aus dem Template
- Markdown nach stdout, wenn kein `--output` gesetzt ist
- HTML standalone und escaped
- Output unter `docs/generated/` moeglich
- `docs/generated/` bleibt ignoriert

### Zentrale Klassen

Vorschlag:

- `ProcessTemplateDocumentationBuilder`
  - baut ein neutrales ViewModel aus `ProcessTemplate`
- `ProcessTemplateDocumentation`
  - readonly ViewModel fuer alle Abschnitte
- `ProcessTemplateDocumentationRenderer`
  - Interface fuer Renderer
- `MarkdownProcessTemplateDocumentationRenderer`
- `HtmlProcessTemplateDocumentationRenderer`
- optional:
  - `ProcessTemplateDocumentationWarningsBuilder`
  - `ProcessTemplateDocumentationAccessSectionBuilder`
  - `ProcessTemplateDocumentationDiagramSectionBuilder`

### Neutrales Documentation-ViewModel

Das ViewModel ist der zentrale Architekturpunkt.

Der Builder soll aus dem Domain-Template strukturierte Daten erzeugen, z. B.:

- metadata
- summary
- steps
- transitions
- parallelGroups
- decisionPoints
- contextFields
- signChecks
- accessSummary
- manualTests
- commandReferences
- warnings

Renderer duerfen keine fachliche Traversal-Logik duplizieren. Sie rendern nur
das ViewModel nach Markdown oder HTML.

Vorteile:

- Markdown, HTML und spaetere Web-Ansicht bleiben konsistent.
- Tests koennen gegen das ViewModel laufen.
- Access-Dokumentation kann als Abschnitt wiederverwendet werden.
- Spaetere Runtime-Erweiterungen koennen optionale Abschnitte ergaenzen.

### Wiederverwendung der Access-Dokumentation

Kurzfristig:

- `AccessCoverageReportBuilder` im Gesamt-Dokumentationsbuilder verwenden.
- Access-Inhalte als strukturierter Abschnitt im neuen ViewModel abbilden.

Mittelfristig:

- bestehende Access-Renderer in kleinere Abschnittsrenderer oder ein eigenes
  Access-Documentation-ViewModel zerlegen.
- Gesamtprozess-Doku soll Access nicht durch String-Inklusion von Markdown oder
  HTML einbetten, weil das Formatlogik vermischt.

### HTML-Renderer

HTML-Anforderungen:

- standalone
- alle Werte escapen
- keine externen Assets
- einfache Tabellen und Abschnitte
- druckbar
- kompatibel mit `docs/generated/`

### Diagramm-Integration

Fuer Diagramme sollte bestehende Logik verwendet werden:

- erwartete Struktur: `ProcessTemplateBpmnViewBuilder`
- Mermaid: `BpmnMermaidRenderer`
- SVG: `BpmnSvgRenderer`
- alternativ klassisches Mermaid: `ProcessTemplateGraphFactory` +
  `MermaidProcessGraphRenderer`

Access-/Visibility-Checks bleiben Annotationen am Step und erzeugen keine
eigenen Prozessknoten.

## MVP-Vorschlag

### MVP 1: Statische Soll-Dokumentation

Umfang:

- neuer Command `intelligence:template:document`
- Markdown + HTML
- Abschnitte:
  - Metadaten / Uebersicht
  - Prozessueberblick
  - Steps
  - Transitions
  - Parallel Groups
  - Decision Points
  - Context / Field Mapping
  - SignChecks
  - Access-/Visibility Summary
  - Manual Access Tests
  - Grenzen / Annahmen
- keine Diagrammeinbettung
- keine Runtime-Daten aus DB
- keine Heatmap
- nur Template-Soll-Dokumentation

Technisches Ziel:

- `ProcessTemplateDocumentationBuilder`
- neutrales `ProcessTemplateDocumentation` ViewModel
- Markdown- und HTML-Renderer
- Tests fuer ViewModel und Renderer

### MVP 2: Diagramme und Dokumentationswarnungen

Umfang:

- Mermaid- oder BPMN-like Diagrammabschnitt
- Links oder Hinweise auf:
  - `access-document`
  - `access-coverage`
  - `check-document`
  - `bpmn-view`
  - `heatmap`
- Coverage Summary detaillierter einbinden
- Warnungen zu:
  - fehlenden Beschreibungen
  - fehlenden Field Mappings
  - Platzhaltern wie `cost_center`
  - nicht gemappten produktiven Amagno-Merkmalen

### MVP 3: Optionale Runtime-/Audit-Ergebnisse

Umfang:

- gespeicherte VisibilityCheckResults optional einbinden
- Dokumentcheck-Zusammenfassungen
- Timeline-Auszuege
- Heatmap-/Bottleneck-Ergebnisse
- Runtime-Abschnitte klar als Ist-/Audit-Daten kennzeichnen

Wichtig:

- Runtime-Daten bleiben optional.
- Sie duerfen die Soll-Dokumentation nicht veraendern.
- Persistierte Ergebnisse werden aus Providern gelesen, nicht direkt in
  Renderern abgefragt.

## Empfohlene Implementierungsreihenfolge

1. ViewModel entwerfen und mit `ai-rechnungen.yaml` testen.
2. Markdown-Renderer bauen.
3. HTML-Renderer bauen.
4. Command `intelligence:template:document` ergaenzen.
5. Access Summary ueber `AccessCoverageReportBuilder` einbinden.
6. Dokumentationswarnungen ergaenzen.
7. Diagrammabschnitt optional nachziehen.
8. Runtime-/Audit-Daten erst spaeter als klar getrennten Abschnitt aufnehmen.

## Nicht-Ziele im ersten Schritt

- keine Queue
- kein Retry-Scheduler
- keine automatische Eventverarbeitung
- keine neue Persistenz
- keine neuen Amagno-API-Aufrufe
- keine Aenderung an bestehender Prozessbewertung
- keine vollstaendige ACL-Dokumentation aus Amagno
