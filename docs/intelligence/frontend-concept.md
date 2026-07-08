# Frontend-Konzept: APRIL

Dieses Dokument beschreibt ein UI-/UX-Konzept, eine Informationsarchitektur und
einen MVP-Vorschlag fuer ein spaeteres Frontend von APRIL. Es ist bewusst ein
Konzept, keine Implementierung. Der konkrete technische Umsetzungsplan fuer
Iteration 1 wird separat gefuehrt (siehe Abschnitt "Naechster Schritt").

## Kurzfassung

APRIL hat heute einen ueberraschend reifen, sauber getrennten Application-Layer
(Reports, Provider, Services). Die Fachlogik steckt fast vollstaendig in
Services, nicht in den Commands. Das macht ein Frontend primaer zu einer
Lese-/Darstellungsaufgabe, nicht zu einer Neuimplementierung der Fachlogik.

Empfehlung: Twig + Symfony UX/Turbo als serverseitiges MVP, kein SPA zu Beginn.
Einstieg nicht mit dem Dashboard, sondern mit der Template-Detailseite inklusive
Access-Coverage und Access-Doku - das ist mit vorhandenen Services
(`ProcessTemplateProvider`, `AccessCoverageReportBuilder`,
`AccessDocumentation*Renderer`) fast ohne Backend-Neubau lieferbar und zeigt
sofort fachlichen Wert.

Der kritische Engpass ist nicht die UI, sondern fehlende persistierte
Lesemodelle fuer Abweichungen: Template-Checks werden heute on-demand berechnet
und nicht gespeichert. Dashboard und Abweichungs-Center brauchen deshalb
entweder On-demand-Berechnung (teuer) oder ein neues Projektions-/Result-Store-
Konzept (analog zu den bereits persistierten `VisibilityCheckResults`).

## Zielbild

APRIL als fachliches Prozessmonitoring- und Audit-Cockpit, nicht als Logviewer.
Drei Leitfragen praegen jede Seite:

1. Laeuft der Prozess korrekt? (Soll/Ist, Abweichungen, Schweregrad)
2. Warum? (Kontext, Decision Rules, Sichtbarkeit - nachvollziehbar)
3. Kann ich es belegen? (Audit-Trail, Pruefprotokolle, generierte Doku)

Durchgaengiges Prinzip: fachlich zuerst, technisch einklappbar. Rohdaten (JSON,
eventPhase, Probe-Details) sind immer erreichbar, aber nie die Default-Ebene.

## 1. Zielgruppen / Personas

| Persona | Wichtigste Fragen | Benoetigte Ansichten | Technische Tiefe |
|---|---|---|---|
| Prozessverantwortlicher (fachlich) | Laeuft mein Prozess wie gedacht? Wo hakt es? Welche Schritte/Standorte sind auffaellig? | Dashboard, Template-Detail, Abweichungs-Center (gefiltert), Heatmap | Niedrig - Statusfarben, Klartext, keine UUIDs/JSON im Vordergrund |
| Fachadministrator / Connector-Admin | Sind Templates/Mappings korrekt? Stimmen Connector-Mappings, Probes? Warum "missing context"? | Template-Detail (inkl. field_mapping, Probes), Coverage-Matrix, Dokument-Detail | Mittel - Mapping- und Konfig-Sicht, Probe-Parameter, Context-Felder |
| Auditor / Datenschutz / Revision | Wer hat wann freigegeben? Welche Sichtbarkeitskontrollen existieren, welche wurden geprueft? Wo ist die Evidenz? | Dokument-Detail (Timeline + SignCheck + VisibilityResults), Pruefprotokoll, Access-Doku, Export | Niedrig-mittel - Nachweis-orientiert, Zeitstempel, Versionen, "geprueft vs. manuell" |
| Entwickler / APRIL-Admin | Stimmen Events/Snapshots? Warum dieses Ergebnis? Was ist Roh-Payload? | Event-Liste, Raw-JSON-Drawer, Dokument-Detail technisch, Admin/Health | Hoch - volle Rohdaten, eventPhase, Provider-Auswahl, Re-Run |

Konsequenz fuer die UI: ein Rollen-/Modus-Umschalter "Fachsicht <-> Technische
Sicht" (nicht zwingend Auth-gebunden im MVP, zunaechst ein UI-Toggle), der
Raw-Panels, UUIDs und Provider-Interna ein-/ausblendet.

## 2. Informationsarchitektur & Hauptnavigation

```
APRIL
- Dashboard ................... (KPIs, Top-Abweichungen)        [Phase 2]
- Prozesse / Templates ....... Liste -> Template-Detail         [MVP]
- Dokumente .................. Suche/Liste -> Dokument-Detail    [MVP+]
- Abweichungen .............. zentrales Findings-Center         [Phase 2]
- Access & Visibility ....... Coverage, Probes, Results, Doku   [MVP-nah]
- Auswertung
  - Timeline (pro Dokument)                                     [MVP+]
  - Heatmaps / KPIs                                             [Phase 3]
- Pruefprotokolle / Dokumentation .. generierte MD/HTML, Tests  [Phase 2/3]
- Administration ............ Health, Events, Versionen, Re-Run [Phase 3]
```

MVP / Spaeter-Bewertung:

| Bereich | Wertbeitrag | Aufwand (Backend) | Einstufung |
|---|---|---|---|
| Prozesse/Templates + Access-Coverage/Doku | hoch | sehr niedrig (Services da) | MVP - Start hier |
| Dokument-Detail + Timeline | hoch | niedrig (`DocumentTimelineProvider` da) | MVP |
| Template-Check-Ergebnis (Dokument) | hoch | mittel (on-demand-Service da, nicht persistiert) | MVP-nah |
| Access-Results pro Dokument | mittel-hoch | sehr niedrig (`VisibilityCheckResultProvider` da) | MVP-nah |
| Abweichungs-Center | hoch | hoch (kein persistiertes Lesemodell) | Phase 2 |
| Dashboard/KPIs | hoch (Aussenwirkung) | hoch (Aggregation noetig) | Phase 2-3 |
| Heatmaps | mittel | mittel (Builder da, SVG/Mermaid) | Phase 3 |
| Administration/Health | niedrig fachlich | niedrig | Phase 3 |

Begruendung der Reihenfolge: Bereiche mit vorhandenen Read-Providern zuerst;
Bereiche, die neue persistierte Aggregate brauchen (Dashboard,
Abweichungs-Center), spaeter.

## 3. Dashboard-Konzept

Drei klar getrennte Bloecke:

A) Fachliche KPIs (Prozessgesundheit)
- Gepruefte Dokumente gesamt / aktiver Zeitraum
- Dokumente mit Prozessabweichung (DEVIATION)
- Dokumente mit Missing Required Step
- Dokumente mit Decision-Rule-Violation
- Offene Prozessinstanzen je Step (aus `ProcessStatusReport` -> `countsByStep`)
- Top-Templates mit den meisten Abweichungen
- Top-Schritte mit den meisten Problemen (Step-Ranking)

B) Technische Warnungen (Datenqualitaet/Betrieb)
- Dokumente mit `WARNING` (Context fehlt/stale, Zeitskew)
- Technical Warnings aus Access-Probes (`probe_too_large`, API unknown)
- Nicht verarbeitete/abgewiesene Events

C) Audit-/Compliance-Indikatoren
- Dokumente mit Access-/Visibility-Violation (hoechste Prioritaet)
- Dokumente mit SignCheck partial/missing
- Coverage-Quote je Template: automatic / manual / unsupported / notCovered
- "Letzte 24h / 7 Tage": neue kritische Findings (Trend)

Visualisierung: Kennzahlkarten + zwei Ranking-Listen (Templates, Steps) + ein
kompakter Trend. Jede Karte ist ein Filter-Deeplink ins Abweichungs-Center.

Realitaets-Hinweis: Dashboard-Zahlen fuer Prozessabweichungen setzen ein
persistiertes Findings-Lesemodell voraus. Bis dahin kann das Dashboard zunaechst
nur die bereits persistierten Groessen zeigen: Prozessinstanz-Status
(`ProcessStatusReportProvider`), Context-Coverage
(`ContextCoverageReportProvider`), Access/Visibility-Results
(`VisibilityCheckResultProvider`).

## 4. Prozess-/Template-Detailseite

Eine Seite pro Template (`/templates/{processKey}`), gespeist aus
`ProcessTemplateProvider` (+ `ProcessVersionRepository`,
`AccessCoverageReportBuilder`).

Kopf: Template-Key, Version/Baseline (mit Versions-Switch aus
`ProcessVersionRepository`), Name, Zweck/Beschreibung, Source-System,
Coverage-Ampel.

Abschnitte:
1. Soll-Prozess (Diagramm) - Mermaid-Panel via `ProcessTemplateGraphFactory` +
   `MermaidProcessGraphRenderer`; optional Live-Metriken.
2. Schritte & Ablauf - Required Steps, Transitions, Parallelgruppen
   (`order: any`) als lesbare Liste/Matrix statt YAML.
3. Entscheidungen - Decision Points & Rules mit den verwendeten Context-Feldern.
4. Context-Profil - required fields + `field_mapping` (fachliches Feld <-> Connector-Feld).
5. SignChecks - erwartete Freigeber/Mehrpersonenregeln.
6. Access & Visibility - Probes, Profile, Resolver, Manual Tests, Coverage.
7. Dokumentation - Vorschau + Download der generierten MD/HTML.
8. Letzte Auswertung - Heatmap-Thumbnail, letzte Check-Laeufe.

YAML fuer Fachanwender verstaendlich machen:
- Niemals rohes YAML als Default. Jede Sektion bekommt eine fachliche
  Uebersetzung: Tabellen, Badges, Klartextsaetze.
- "Erklaer-Modus": Tooltip/Glossar pro Konzept.
- Toggle "YAML-Quelle anzeigen" (read-only) fuer Admins - eingeklappt.
- Resolver verstaendlich: "Standortbezogene Sichtbarkeit wird aus dem Feld
  Standort (Projekt) (`project_location`) abgeleitet".

## 5. Dokument-Detailseite

Eine Seite pro Dokument (`/documents/{uuid}`). Primaerquelle:
`DocumentTimelineProvider` -> `DocumentTimelineReport`, ergaenzt um
`ContextSnapshotHistoryProvider`/`ContextHistoryBuilder`,
`VisibilityCheckResultProvider`, on-demand `ProcessTemplateCheckService`.

Kopf: Dokumentnummer + UUID (UUID sekundaer/kopierbar), ProcessKey/Template +
Version, aktueller Status-Badge, Score/Ergebnis, letzter Event-Zeitpunkt.

Hauptbereich - fachliche Schrittfolge (Step-Accordion): `before`/`after` sind
Pruefphasen innerhalb eines fachlichen Schritts, keine eigenen Knoten:

```
01 Rechnungseingang
  - before: Eingangssichtbarkeit geprueft ............... OK
  - event:  Rechnungseingang (occurredAt 10:02)
  - after:  Sichtbarkeitspruefung Standortfreigabe
        approval_location_a_today ...................... OK
        approval_location_b_today ............... VIOLATION
        external_today ................................ OK
03 Freigabe klein
  - after:  Kontext fehlt (project_location) ........ WARNING
05 Ausgangsrechnung buchen ............................. OK
```

- Soll/Ist nebeneinander (erwartete Schrittfolge vs. beobachtete `after`-Steps;
  aggregierte Step-Gruppen, keine Doppelschritte).
- Pro Schritt aufklappbar: Abweichungen, Decision-Rule-Ergebnisse,
  Context-Snapshot, Context-Diff, Access-Results.

Zusaetzliche Panels: Context-Snapshots & Changes (Diff-View), Decision Rules
(Tabelle), SignChecks (erwartet vs. bestaetigt), Access/Visibility-Results,
Raw-JSON-Drawer (eingeklappt).

## 6. Abweichungs-Center

Zentrale Findings-Sicht mit Filtern:
- ProcessKey
- Status: OK / WARNING / DEVIATION / CRITICAL
- Abweichungstyp: Transition Violation, Decision Rule Violation, Missing
  Required Step, Context Missing/Changed, SignCheck partial/missing, Access
  Visibility Violation, Technical Warning
- Zeitraum, Dokument, Standort/Kontextfeld, Schweregrad

Jede Zeile ist ein Deeplink zur Dokument-Timeline (Sprung direkt zum
betroffenen Schritt). Setzt ein persistiertes Findings-Lesemodell voraus
(siehe Risiken).

## 7. Access & Visibility Bereich

7a) Access-Coverage pro Template (`/templates/{key}/access`, aus
`AccessCoverageReportBuilder` -> `AccessCoverageReport`):
- Coverage-Matrix: Control (Step/Phase/Check) x Status
  (automatic/manual/unsupported/notCovered), Ampelfarben.
- Access-Probe-Tabelle: Probe-Key, Typ (`amagno_magnet_documents`),
  Source-System (Default `amagno`), `magnet_id`/`probe_ref`, `max_documents`,
  `page_size`, Beschreibung.
- Visibility-Profile: erwartet sichtbar / erwartet nicht sichtbar je Profil.
- Resolver: "Profil wird aus Context-Feld Standort (Projekt) bestimmt"; Map
  sichtbar; Hinweis auf `missing_context_field` / `unmapped_context_value` als
  nicht bewertbar, nicht als Violation.
- Manual Access Tests: Karten mit Titel, Testprozedur, erwartetem Ergebnis,
  Frequenz.

7b) Access-Check-Results pro Dokument (aus `VisibilityCheckResultProvider`):
Tabelle checkedAt, step, phase, check, probe, expected, actual, status, reason;
Filter nach Status; Verlinkung in die Timeline.

7c) Manual Test Protocols / Pruefprotokoll: Checklisten-UI je Manual Test;
perspektivisch Eintrag von Pruefer, Datum, Ergebnis, Evidenz-Verweis.

7d) Permanente Einordnung (Compliance-Schutz): ueberall ein fester, nicht
wegklickbarer Hinweis:

> APRIL prueft definierte Sichtbarkeits-Controls (Probes), keine vollstaendige
> Connector-ACL und keine vollstaendige Benutzerrechteanalyse. Automatische
> Ergebnisse belegen Sichtbarkeit in den technischen Kontrollpunkten zum
> Pruefzeitpunkt.

## 8. Human-readable Dokumentation / Pruefprotokoll

APRIL erzeugt bereits Markdown/HTML (`AccessDocumentationMarkdownRenderer`,
`AccessDocumentationHtmlRenderer`, jeweils `render(ProcessTemplate): string`).

Frontend-Integration:
- Inline-Vorschau der generierten HTML-Doku im Template-Detail. Da der
  HTML-Renderer ein vollstaendiges Standalone-Dokument liefert, per `<iframe>`
  einbetten (eigene Styles isoliert).
- Download als MD/HTML (spaeter PDF-Export fuer Audits).
- "Zuletzt generiert am ..." + "Jetzt neu erzeugen" (ruft den Renderer-Service).
- Verlinkung aus Template-Detailseite und Access-Bereich.
- Spaeteres Pruefprotokoll: pro Manual Test ein Eintrag mit
  Pruefer/Datum/Ergebnis/Evidenz -> kombinierte Audit-Mappe je Template.

Speicherung der generierten Doku (Empfehlung): nicht im Repo, sondern unter
`var/intelligence/access-docs/{processKey}/{version}-{timestamp}.{md,html}`, plus
optional ein leichter DB-Index. Im MVP kann ganz auf Persistenz verzichtet und
on-demand gerendert werden.

## 9. Technische UI-Architektur

| Ansatz | Pro | Contra | Verdikt |
|---|---|---|---|
| Twig MVP | nutzt vorhandene Controller-Konvention, schnellster Wert, Print/Audit-freundlich, kein Build | weniger Interaktivitaet | Start |
| Symfony UX / Turbo + Stimulus | progressive Interaktivitaet ohne SPA-Komplexitaet | leichte Lernkurve | Ausbaustufe |
| Vue/React SPA | reichste Interaktion | doppelte Datenmodelle, Build/Auth/State | spaeter |
| API-first | entkoppelt, mehrkanalfaehig | Aufwand vor erstem Wert | selektiv (JSON fuer Lazy-Panels) |

Empfehlung: Twig-first, Turbo-progressiv. Seiten serverseitig, einzelne schwere
Panels (Timeline, Mermaid, Coverage-Matrix, Raw-JSON) als Turbo-Frames/JSON-
Endpunkte nachladen. SPA erst bei echten Bearbeitungs-Workflows.

Wichtigste Architektur-Regel: Controller duerfen nur Application-Services
verwenden, nie Commands aufrufen. Commands und Web-UI sind zwei duenne Adapter
ueber demselben Service-Kern. Das ist heute schon weitgehend gegeben.

Nuetzliche Read-Controller (Twig), ueberwiegend auf vorhandenen Services:

| Route | Service (vorhanden) |
|---|---|
| `/templates`, `/templates/{key}` | `ProcessTemplateProvider`, `ProcessVersionRepository` |
| `/templates/{key}/access` | `AccessCoverageReportBuilder` |
| `/templates/{key}/diagram` | `ProcessTemplateGraphFactory` + `MermaidProcessGraphRenderer` |
| `/templates/{key}/docs` | `AccessDocumentation{Markdown,Html}Renderer` |
| `/documents/{uuid}` | `DocumentTimelineProvider`, `ContextSnapshotHistoryProvider`, `VisibilityCheckResultProvider` |
| `/documents/{uuid}/check` | `ProcessTemplateCheckService` -> `ProcessTemplateCheckResult` |
| `/processes/{key}/status` | `ProcessStatusReportProvider` |
| `/events`, `/events/{id}` | `EventListProvider`, `EventDetailsProvider` |
| `/access/results/{uuid}` | `VisibilityCheckResultProvider` |

Daten, die NICHT aus Commands, sondern (neu) ueber Application-Services
bereitzustellen sind:
- Template-Liste: heute kein Service - die Glob-Logik steckt in
  `IntelligenceTemplateListCommand`. Neu: `ProcessTemplateCatalog`/
  `ProcessTemplateListProvider`, von Command und Controller genutzt.
- Dokumentliste/-suche mit letztem Status: heute nur
  `ProcessDocumentUuidProvider`. Neu: `DocumentListProvider`.
- Findings-/Abweichungs-Lesemodell: `ProcessTemplateCheckResult` ist on-demand,
  nicht persistiert. Neu: `DeviationQueryService` oder persistierter
  Check-Result-Store (analog `VisibilityCheckResultStore`).
- Dashboard-Aggregation: `DashboardMetricsProvider`.

## 10. MVP-Roadmap (2-4 Iterationen)

Iteration 1 - "Template & Access transparent" (Start)
- Ziel: Templates und ihre Access-/Visibility-Controls fachlich verstaendlich.
- Seiten: Template-Liste, Template-Detail, Access-Coverage, Access-Doku.
- Backend: `ProcessTemplateProvider`, `AccessCoverageReportBuilder`,
  `AccessDocumentation*Renderer`, `ProcessVersionRepository` - vorhanden.
  Neu: duenne Read-Controller + Twig + Layout, `ProcessTemplateCatalog`.
- Risiken: YAML verstaendlich uebersetzen; Doku-Speicherort.
- Tests: Controller-Smoke, Coverage-Darstellung, Renderer-Service-Tests.

Iteration 2 - "Dokument verstehen"
- Ziel: Pro Dokument Timeline + Soll/Ist + Check + Access-Results.
- Seiten: Dokumentsuche, Dokument-Detail (Step-Accordion), Access-Results.
- Backend: `DocumentTimelineProvider`, `VisibilityCheckResultProvider`,
  `ProcessTemplateCheckService` on-demand; neu `DocumentListProvider`.
- Risiken: Performance grosser Timelines; on-demand-Check-Kosten.
- Tests: Timeline-Mapping inkl. eventPhase-Gruppierung; keine Doppelschritte.

Iteration 3 - "Abweichungen & Audit"
- Ziel: zentrale Findings-Sicht + gespeicherte Visibility-Results querbar.
- Backend: neu `DeviationQueryService`/persistiertes Check-Result-Lesemodell.
- Risiken: groesster Brocken - Persistenz-/Aggregationsentscheidung.

Iteration 4 - "Ueberblick & Trends"
- Ziel: Dashboard, KPIs, Heatmaps.
- Backend: `DashboardMetricsProvider`, Heatmap-Builder + SVG/Mermaid.

## 11. UI-Komponenten (Designsystem)

| Komponente | Zweck / Notiz |
|---|---|
| Status-Badge | OK / WARNING / DEVIATION / CRITICAL - eine Farbskala projektweit |
| Severity-Chip | Abweichungstyp + Schweregrad |
| Timeline-Component | chronologisch, gruppiert nach fachlichem Step; before/after als Phasen |
| Step-Accordion | aufklappbarer Schritt mit Phasen, Abweichungen, Context, Access-Results |
| Context-Diff-View | Feld-fuer-Feld Aenderungen, entscheidungsrelevant markiert |
| Rule-Evaluation-Table | Regel, Feld, Wert, erfuellt/verletzt |
| Access-Probe-Table | Probe, Typ, Source, Ref/Magnet, max_documents, page_size |
| Coverage-Matrix | Control x Status (automatic/manual/unsupported/notCovered) |
| Manual-Test-Checklist | Testprozedur + erwartetes Ergebnis + Protokoll-Erfassung |
| Mermaid-Diagram-Panel | Soll-Prozess + optional Live-Metriken |
| Raw-JSON-Drawer | seitlicher Slide-over, "Technische Sicht" |
| Compliance-Banner | fixer Hinweis "Controls, keine vollstaendige ACL" |
| Fach/Technik-Toggle | blendet UUIDs, Raw, Provider-Interna global ein/aus |

## 12. Offene Fragen / Risiken

1. Was ist schon als Service da? Sehr viel: Timeline, Status,
   Context-Coverage/History/Diff, Event-Liste/-Details, Access-Coverage,
   Access-Doku-Renderer, Visibility-Result-Provider/-Store, Template-Provider/
   -Versionen, Graph/Mermaid, Check-Service. Frontend ist ueberwiegend
   Read-Verdrahtung.
2. Welche Commands muessen in Services extrahiert werden? Kaum welche - die
   Commands sind duenn. Neu zu bauen: `ProcessTemplateCatalog` (Liste),
   `DocumentListProvider` (Suche), `DeviationQueryService`,
   `DashboardMetricsProvider`.
3. Grosse Timelines performant? Pagination/Lazy-Load per Turbo-Frame;
   serverseitige Aggregation auf Step-Gruppen; Default-Filter (z. B. `before`
   ausgeblendet, zuschaltbar).
4. Generierte Doku speichern? Empfehlung `var/intelligence/access-docs/...` +
   leichter DB-Index; im MVP on-demand ohne Persistenz.
5. Dev/Admin vs. Fachanwender trennen? MVP: UI-Toggle "Fach/Technik". Spaeter:
   rollenbasierte Auth (Symfony Security).
6. Access != ACL - Missverstaendnis verhindern? Fester Compliance-Banner +
   bewusste Wortwahl "Control/Probe/Sichtbarkeitskontrolle"; Status
   `unsupported`/`skipped`/`missing_context` sichtbar als nicht bewertet.
7. Konsistenz mit Prozessversionen: jede Auswertung muss die zum
   Ereigniszeitpunkt gueltige Baseline referenzieren.

## Empfehlung: Womit zuerst beginnen

Iteration 1, Einstieg mit der Template-Detailseite + Access-Coverage +
Access-Doku-Vorschau. Gruende: nutzt ausschliesslich vorhandene
Application-Services, kein neues Persistenz-/Aggregationsmodell noetig; zeigt
sofort den fachlich differenzierenden Teil; etabliert das Designsystem
(Status-Badges, Coverage-Matrix, Compliance-Banner, Fach/Technik-Toggle).

Den groessten Architekturentscheid (persistiertes Findings-Lesemodell vs.
on-demand) bewusst erst in Iteration 3 treffen.

## Frontend-Testkonzept

Frontend-Tests sind Pflichtbestandteil jeder Iteration - nicht nur manuelle
Browser-Pruefung. Grundsaetze:

- **Testart:** Symfony `WebTestCase` (KernelBrowser) fuer Controller-/HTML-Smoke-
  und Integrationstests. Pruefung ueber Response-Status und Response-Content
  bzw. CSS-Selektoren. **Keine** Browser-/E2E-Tests (Panther/Playwright) im MVP -
  keine JavaScript-Abhaengigkeit.
- **Benoetigte Dev-Pakete:** `symfony/browser-kit`, `symfony/css-selector`
  (DomCrawler kommt als Abhaengigkeit). Test-Modus ist via `when@test:
  framework.test: true` bereits aktiv.
- **Testpyramide:** breite Basis aus reinen Unit-Tests (Application-Services,
  Renderer, Catalog) + duenne Schicht `WebTestCase`-Smoke-Tests pro Seite. Twig-
  Templates werden nicht isoliert gerendert, sondern ueber den Controller-Test
  mit abgedeckt (rendert das Template real, faengt Twig-Fehler als 500).
- **Architektur-Guard:** ein Test stellt sicher, dass Controller **keine
  Commands** aufrufen (Quelltext-Scan auf `App\\Command\\`). Damit bleibt die
  Regel "Controller nutzen nur Application-Services" pruefbar.

Konkrete Testfaelle je Seite (Muster):

| Seite | Testfaelle |
|---|---|
| `/app/templates` (Liste) | HTTP 200; Base-Layout gerendert; Navigation enthaelt "Templates"; reale Templates (z. B. `incident-management`) erscheinen; unbekannte Unterroute -> 404 |
| Template-Detail (spaeter) | 200 fuer existierenden Key; 404 fuer unbekannten Key; fachliche Sektionen vorhanden |
| Access-Coverage (spaeter) | Coverage-Status (automatic/manual/unsupported/notCovered) sichtbar; Compliance-Banner vorhanden |
| Access-Doku (spaeter) | Preview liefert `text/html`; Download setzt `Content-Disposition`; MD/HTML korrekt |

Service-/Unit-Tests (Beispiele Iteration 1):
- `ProcessTemplateCatalog`: findet `*.yaml`, ignoriert Nicht-YAML; liefert
  key/version/name/path; ungueltige Templates -> Warnings statt Abbruch.
- `intelligence:template:list`: Text-/JSON-Ausgabe bleibt kompatibel
  (bestehende Tests gruen).

Verifikation jeder Iteration: `composer test`, `bin/console lint:container`,
`git diff --check`.

## Verzeichnis-Konventionen (verbindlich)

Prozess-Templates und Web-Views sind strikt getrennt:

- **APRIL Prozess-Templates (YAML):** `config/april/process-templates/*.yaml`.
  Massgeblich konfiguriert ueber den Parameter `april.process_template_dir` in
  `config/services.yaml`. Genutzt von `YamlProcessTemplateProvider`,
  `ProcessTemplateCatalog`, `IntelligenceTemplateListCommand`,
  `IntelligenceTemplateHeatmapCommand` und (fuer die 404-Meldung) vom
  `TemplateController`.
- **Twig/Web-Views:** `templates/web/` (Parameter `twig.default_path`). Twig
  liest ausschliesslich hier und niemals aus `config/april/process-templates`.
  Das Projektverzeichnis `templates/` enthaelt keine Prozess-YAMLs mehr.
- **Generierte Artefakte:** `var/april/generated/` (Heatmaps, Diagramme) und
  `docs/generated/` sind nicht versioniert (siehe `.gitignore`).
- **Access-/Visibility-Dokumentation:** wird on-demand aus dem Template gerendert
  und nicht persistiert.

Diese Trennung ist durch `ProcessTemplateLocationTest` abgesichert (Catalog/
Provider/List-Command lesen aus `config/april/process-templates`; aus
`templates/web` werden keine Prozess-Templates geladen).

## Umsetzungsstand (Iteration 1 + Start Iteration 2)

Umgesetzt und durch WebTestCase-/Unit-Tests abgesichert:

- `symfony/twig-bundle` ergaenzt; `twig.default_path = templates/web`.
- `ProcessTemplateCatalog` als Application-Service (vom List-Command genutzt).
- Frontend-Seiten unter dem Prefix `/app`: Template-Liste, Detail, Access-
  Coverage, Access-Dokumentation (iframe-Vorschau + MD/HTML-Download),
  Dokumentliste pro Template.
- Fach-/Technik-Toggle (`?view=`/Cookie, rein UI).

Hinweis: Die konzeptionellen Routen weiter oben (`/templates/...`) sind real
unter dem Prefix `/app/templates/...` implementiert.
