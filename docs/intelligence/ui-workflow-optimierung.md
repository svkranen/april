# UI-Workflow-Optimierung: Vom Event zum Template zum Befund

Dieses Dokument ist ein Vorschlag, keine Implementierung. Es baut auf dem
umgesetzten MVP-Frontend auf (siehe `frontend-concept.md`) und beschreibt, wie
die vier Kern-Arbeitsablaeufe in der UI durchgaengig und intuitiv werden:

1. **Templates erstellen** (Prozess und Journey)
2. **Findings identifizieren** und klaeren
3. **Den per Event erfassten Prozess dokumentieren**
4. **Durchlaeufe von Prozessen und Events analysieren**

Der Feature-Umfang des Backends bleibt unveraendert; es geht um Bedienbarkeit,
das Schliessen von Sackgassen und das Verlagern haeufiger CLI-Befehle in die
Oberflaeche. Ein KPI-Dashboard (z. B. Liegezeiten je Prozessschritt) ist
bewusst **nicht** Teil dieses Vorschlags, wird aber als spaetere Ausbaustufe
vorbereitet (siehe Abschnitt 8).

---

## 1. Kurzfassung

Die UI kann heute fast alles **anzeigen**, aber nichts **erzeugen**. Die
Fachlogik dafuer existiert bereits vollstaendig als Services und ist nur ueber
CLI erreichbar (`TemplateSuggestionService`, `JourneyDocumentCheckService`,
`TemplateHeatmapReportBuilder`, `ContextDiffBuilder`, ...). Der groesste Hebel
ist deshalb nicht neues Backend, sondern:

1. **Einen durchgaengigen Analysepfad bauen**: Process Key → Item-Liste →
   Item-Detail → "Template aus diesem Lauf erstellen" → Match definieren →
   Kandidaten pruefen. Heute endet dieser Pfad nach dem Item-Detail in einer
   Sackgasse.
2. **Die zwei getrennten Welten verbinden**: Der Event Explorer
   (`/app/intelligence/...`, template-frei) und die Template-Welt
   (`/app/templates/...`) verlinken kaum aufeinander. Der Nutzer muss den
   Wechsel im Kopf machen.
3. **Schreiben ermoeglichen**: Template-Entwuerfe aus beobachteten Laeufen
   generieren, Assistant-Vorschlaege per Klick uebernehmen, Template-YAML im
   Frontend editieren (mit Lint und Vorschau).
4. **Entscheidungswege anbieten**: Bei jedem Finding eine gefuehrte Klaerung
   ("erlaubte Variante → Template erweitern" vs. "echter Verstoss →
   bestaetigen"), statt den Nutzer mit einer Meldung allein zu lassen.

---

## 2. IST-Analyse: Wo die Bedienung heute bricht

### 2.1 Sackgassen im Kernpfad

Der vom Nutzer gewuenschte Leitpfad existiert in Fragmenten, bricht aber an
den entscheidenden Stellen ab:

| Schritt | Heute | Bruchstelle |
|---|---|---|
| Alle Items zu einem Process Key sehen | `app_intelligence_process_keys_index` → `.../documents` funktioniert | Keine Filter, keine Sortierung, kein Zeitraum |
| Item-Detail oeffnen | "Journey"-Link fuehrt zum Event Explorer (`app_intelligence_documents_show`) | Reine Anzeige. Keine einzige Aktion moeglich |
| Template aus diesem Lauf erstellen | **Nur CLI**: `intelligence:template:suggest-from-document[s]` | In der UI nicht vorhanden — der Pfad endet hier |
| Journey-Match definieren | **Nur YAML-Datei**: `match.any_process` von Hand eintragen | Kein UI, keine Vorschau, welche Items matchen wuerden |
| Kandidaten der Journey pruefen | **Nur CLI**: `intelligence:template:check-journey-documents` | Ergebnis nur im Terminal |

### 2.2 Zwei unverbundene Welten

- **Event Explorer** (`/app/intelligence/...`): kennt alle Events und Process
  Keys, auch ohne Template. Zeigt pro Process Key sogar an, ob ein Template
  existiert ("known/unknown") — bietet aber fuer "unknown" keine Aktion an.
- **Template-Welt** (`/app/templates/...`): Soll/Ist-Checks, Graph, Assistant,
  Access — aber kein Weg zurueck zur rohen Event-Sicht eines Items ausser
  ueber die Hauptnavigation.

Das Item-Detail existiert doppelt (template-gebunden unter
`app_template_document_show`, template-frei unter
`app_intelligence_documents_show`), ohne dass die beiden Sichten aufeinander
verweisen.

### 2.3 Lesen ohne Schreiben

- Der **Template-Assistant** berechnet Modellierungsvorschlaege inklusive
  YAML-Diff-Vorschau — der Nutzer muss den Diff aber manuell in die Datei
  kopieren. Fussnote im UI: "Aenderungen erfolgen weiterhin manuell in der
  YAML-Vorlage."
- Es gibt keinen Template-Editor, keinen Entwurfs-Status, keine Moeglichkeit,
  ein neues Template anzulegen.

### 2.4 CLI-Pflicht fuer Analyse-Alltag

Diese regelmaessig benoetigten Funktionen existieren nur als Konsolenbefehl:

| CLI-Befehl | Fachlicher Zweck |
|---|---|
| `intelligence:template:suggest-from-document` | Template-Entwurf aus einem Lauf |
| `intelligence:template:suggest-from-documents` | Template-Entwurf aus mehreren Laeufen |
| `intelligence:template:check-journey-documents` | Journey-Kandidaten finden und pruefen |
| `intelligence:template:heatmap` | Fluss-/Verweildauer-Analyse |
| `intelligence:template:export-diagram` | Diagramm mit Metrik-Overlays (flow/dwell/deviations) |
| `intelligence:document:context-history` | Context-Aenderungen als Diff nachvollziehen |
| `intelligence:events:list` / `events:show` | Events filtern und Roh-Payload inspizieren |
| `intelligence:process:status` | Instanzen je Schritt, offene Vorgaenge |
| `intelligence:process-version:list` / `:create` | Prozessversions-Baselines |

### 2.5 Querschnitts-Reibung

- Keine globale Suche, keine Sortierung, keine Pagination (Listen hart auf
  ~200–500 Eintraege begrenzt, ohne Hinweis was fehlt).
- Findings werden bei jedem Seitenaufruf synchron neu berechnet
  ("Mit Findings"-Toggle) — bei vielen Items droht Timeout, Ergebnisse sind
  fluechtig und nicht verlinkbar.
- Breadcrumbs und Kontext-Pills sind uneinheitlich (Template-Seiten haben sie,
  der Event Explorer teilweise nicht).
- Kaum Erklaerhilfen fuer Fachbegriffe (eventPhase, Probe, Visibility Profile)
  ausserhalb der Doku-Seiten.

---

## 3. Zielbild: Ein durchgaengiger Analysepfad

Leitprinzip: **Beobachten → Verstehen → Modellieren → Pruefen** als ein
zusammenhaengender Fluss, in dem jede Seite eine klare "naechste Aktion"
anbietet.

```
BEOBACHTEN            VERSTEHEN               MODELLIEREN                PRUEFEN
Process Keys   ──►    Item-Liste       ──►    Template-Entwurf     ──►   Kandidaten-Check
(known/unknown)       je Process Key          aus Lauf generieren        (Journey-Match-
                          │                   (Prozess oder Journey)      Vorschau)
                          ▼                        │                          │
                      Item-Detail                  ▼                          ▼
                      (Timeline, Context,     YAML-Editor              Findings-Center
                      Entscheidungspfad)      mit Lint + Graph-        mit Klaerungs-
                                              Vorschau                 Dialog
```

Konkret heisst das fuer die Navigation:

```
APRIL
├── Prozesse ............ vereinheitlichte Sicht: alle Process Keys,
│                         mit und ohne Template (heute zwei Menuepunkte)
│   └── {processKey}
│       ├── Items ....... Liste mit Filter/Sortierung/Pagination
│       ├── Graph ....... Struktur + optionale Overlays (Findings, spaeter Metriken)
│       ├── Template .... Detail + Editor + Assistant + Versionen
│       └── Access ...... wie heute
├── Items ............... globale Suche (UUID, externalId, Zeitraum)
│   └── {uuid} .......... EIN Item-Detail mit Tabs statt zwei Seiten
├── Findings ............ zentrales Findings-Center            [neu]
└── Guided Tours ........ wie heute
```

---

## 4. Vorschlaege je Arbeitsablauf

### W1 — Template aus beobachtetem Lauf erstellen (hoechste Prioritaet)

Der vom Nutzer beschriebene Pfad, vollstaendig in der UI:

1. **Einstieg A (explorativ):** Process-Key-Uebersicht. Bei Keys mit Status
   "unknown" (kein Template) erscheint eine primaere Aktion
   **"Template-Entwurf erstellen"**. Damit wird der heute angezeigte, aber
   folgenlose Status endlich handlungsfaehig.
2. **Einstieg B (vom Einzelfall):** Item-Detail. Aktion
   **"Template aus diesem Lauf erstellen"** — nutzt
   `TemplateSuggestionService` (heute
   `intelligence:template:suggest-from-document`).
3. **Schritt 1 des Dialogs — Scope waehlen:** "Prozess" (ein Process Key)
   oder "Journey" (prozessuebergreifend). Bei Journey werden die im Lauf
   beobachteten Process Keys angezeigt.
4. **Schritt 2 — Datenbasis waehlen:** nur dieses Item, oder mehrere Items
   des Process Keys (Checkboxen in der Item-Liste bzw. "letzte N / seit
   Datum"), entsprechend `suggest-from-documents`. Anzeige, welche Schritte/
   Transitionen aus wie vielen Laeufen abgeleitet wurden.
5. **Schritt 3 — Entwurf pruefen:** generiertes YAML nebeneinander mit
   gerenderter Mermaid-Vorschau (Renderer existiert). Warnungen des
   Suggestion-Services werden inline angezeigt.
6. **Schritt 4 — Speichern als Entwurf:** Ablage als
   `config/april/process-templates/{key}.yaml` mit `version: draft`.
   Anschliessend direkte Weiterleitung ins Template-Detail, wo Assistant-
   Checks sofort laufen.

Damit wird die Beispielanforderung "Zeige mir alle Dokumente mit einem
ProcessKey → Detailanzeige → Template fuer Journey oder Prozess aus diesem
Prozesslauf erstellen" ohne Terminal abbildbar.

### W2 — Journey-Match direkt definieren, mit Live-Vorschau

Wenn im Dialog aus W1 der Scope "Journey" gewaehlt wurde (oder auf einem
bestehenden Journey-Template):

1. **Match-Editor:** Die in den Laeufen beobachteten Process Keys werden als
   Checkbox-Liste angeboten und in `match.any_process` uebernommen. Freie
   Eingabe zusaetzlicher Keys moeglich.
2. **Live-Kandidaten-Vorschau:** Direkt unter dem Match-Editor zeigt eine
   Tabelle "N Items wuerden dieser Journey zugeordnet" — das ist exakt
   `JourneyDocumentCandidateProvider` + `JourneyDocumentCheckService`, heute
   nur via `intelligence:template:check-journey-documents` erreichbar.
   Pro Kandidat: Status (ok / Abweichung / unerwarteter Prozess), Link ins
   Item-Detail.
3. **Journey-Seite im Template-Bereich:** Journey-Templates erhalten analog zu
   Prozess-Templates eine "Items"-Unterseite, die die Kandidaten samt
   Check-Ergebnis listet (inkl. der neuen Unexpected-Process-Findings).
   Damit ist die Zuordnung anderer Dokumente zur Journey jederzeit sichtbar,
   ohne dass eine persistente Zuordnung noetig ist (das dynamische Matching
   bleibt unveraendert).

### W3 — Template im Frontend bearbeiten

Minimalloesung zuerst, Komfort spaeter:

1. **Stufe 1 — YAML-Editor:** Auf dem Template-Detail ein "Bearbeiten"-Tab
   mit Texteditor (CodeMirror o. ae. via importmap, passend zum vorhandenen
   Stack ohne Build-Tooling). Beim Speichern:
   - Parse + Validierung ueber den vorhandenen
     `ProcessTemplateArrayFactory` (Fehler inline anzeigen, nicht speichern),
   - Mermaid-Vorschau aktualisieren,
   - Schreiben in die YAML-Datei; die Datei bleibt die einzige Quelle der
     Wahrheit (kein Schatten-Zustand in der DB).
2. **Stufe 1b — Assistant-Vorschlaege uebernehmen:** Die bereits berechneten
   YAML-Diffs des `TemplateModelingSuggestionAnalyzer` erhalten einen
   "In Editor uebernehmen"-Button, der den Diff in den Editor einspielt
   (nicht direkt in die Datei — der Nutzer prueft und speichert bewusst).
3. **Stufe 2 — Leitplanken:** Beim Speichern Hinweis, wenn `version` nicht
   erhoeht wurde, obwohl strukturelle Aenderungen vorliegen; optionaler
   Schreibschutz fuer Nicht-`draft`-Versionen (Aenderung erzwingt neue
   Version). Das haelt die Historie der Soll-Modelle sauber, auf der spaetere
   Metriken (Prozessversions-Baselines) aufsetzen.
4. **Bewusst nicht vorgeschlagen:** ein grafischer Prozess-Designer. Die
   YAML-Templates sind das Produktversprechen ("explicit, inspectable");
   Editor + Live-Graph-Vorschau + Assistant-Checks liefern 90 % des Nutzens
   fuer einen Bruchteil des Aufwands.

### W4 — Findings identifizieren, klaeren, entscheiden

1. **Findings persistieren (technische Voraussetzung):** Ein Result-Store fuer
   Template-Check-Ergebnisse analog zu den bereits persistierten
   `VisibilityCheckResults` (im Frontend-Konzept bereits als Engpass
   identifiziert). Berechnung asynchron ueber die vorhandene
   Pending-Event-Mechanik bzw. einen "Neu berechnen"-Button mit
   Hintergrundlauf. Effekt: Listen laden schnell, Findings sind verlinkbar,
   der "Mit Findings"-Toggle mit Timeout-Risiko entfaellt.
2. **Findings-Center:** Eine zentrale Seite ueber alle Templates: Filter nach
   Severity, Typ (Transition / Decision Rule / Unexpected Process / Context /
   Access), Process Key, Zeitraum. Jede Zeile fuehrt zum Item-Detail mit
   vorselektiertem Finding.
3. **Entscheidungsvorlagen bei Unklarheiten (Klaerungs-Dialog):** Jedes
   Finding bietet einen gefuehrten Dialog mit den fachlich moeglichen
   Reaktionen — das ist die gewuenschte "Entscheidungsvorlage":

   | Finding-Typ | Angebotene Entscheidungen |
   |---|---|
   | Transition-Verletzung | (a) "Erlaubte Variante" → Vorschlag: Transition ins Template aufnehmen (YAML-Diff, oeffnet Editor) · (b) "Echter Verstoss" → Finding bestaetigen · (c) "Datenproblem" → Link auf Event-Rohdaten |
   | Decision-Rule-Verletzung | (a) Regel-Auswertung anzeigen: welche Felder, welche Werte, welche Regel haette gegriffen (Context Snapshot zum Zeitpunkt) · (b) "Regel anpassen" → Diff-Vorschlag in Editor · (c) Verstoss bestaetigen |
   | Unexpected Process (Journey) | (a) "Prozess gehoert zur Journey" → Journey-Step-Vorschlag ins Template · (b) Verstoss bestaetigen |
   | Fehlender Context | Link auf Context-Coverage des Feldes und die Field-Mapping-Definition |

   Stufe 1 kann rein navigierend sein (Erklaerung + Diff-Vorschlag + Link in
   den Editor); ein persistierter Triage-Status ("bestaetigt/erledigt/
   akzeptierte Ausnahme") ist eine spaetere Ausbaustufe, weil er ein neues
   Statusmodell braucht.
4. **Erklaerbarkeit von Decision Points generell:** Im Item-Detail bekommt
   jeder durchlaufene Decision Point eine aufklappbare Auswertungsspur:
   geprueftes Feld, Wert aus dem Context Snapshot, gegriffene Regel,
   erwarteter naechster Schritt, tatsaechlicher Schritt. Die Daten liefert
   `ContextDiffBuilder` / die Decision-Rule-Auswertung bereits (CLI:
   `intelligence:document:timeline --with-decisions`).

### W5 — Prozesslaeufe und Events analysieren

1. **Ein Item-Detail statt zwei:** Die template-gebundene Detailseite und der
   Event Explorer werden zu einer Seite mit Tabs zusammengefuehrt:
   - **Journey** (Timeline ueber alle Prozesse, heutiger Explorer),
   - **Soll/Ist** (pro zugeordnetem Template, heutige Check-Sicht),
   - **Context** (Snapshot-Historie mit Diff — heute nur CLI
     `intelligence:document:context-history`),
   - **Access** (heutige Visibility-Ergebnisse),
   - **Rohdaten** (nur in Expertensicht: Events mit Raw-/Normalized-Payload,
     heute nur CLI `intelligence:events:show`).
   Der vorhandene Fachsicht/Expertensicht-Umschalter steuert, welche Tabs und
   Spalten sichtbar sind.
2. **Prozess-Statusleiste je Process Key:** Auf der Item-Liste eines Process
   Keys eine kompakte Kopfzeile aus `ProcessStatusReportProvider` (heute CLI
   `intelligence:process:status`): Instanzen je aktuellem Schritt, offene
   Vorgaenge, letztes Event. Das ist bewusst kein Dashboard, sondern
   Orientierung im Arbeitskontext — und der natuerliche spaetere Andockpunkt
   fuer KPIs.
3. **Graph-Ansichten erweitern:** Die Graph-Seite erhaelt neben
   "Mit/Ohne Findings" weitere Overlays aus dem vorhandenen
   `TemplateHeatmapReportBuilder` / `export-diagram`: "Fluss" (Kantennutzung)
   und "Verweildauer" (Dwell je Schritt). Damit ist die wichtigste
   Analysefrage ("wo haengt es?") in der UI beantwortbar, lange bevor ein
   Dashboard existiert.
4. **Listen-Grundhygiene:** Pagination (Cursor auf `occurredAt`/`lastEventAt`),
   Sortierung per Spaltenkopf, Filter (Zeitraum, Status, externalId,
   Event-Anzahl), sichtbarer Hinweis "Anzeige begrenzt auf N — filtern oder
   blaettern" statt stillem Cap.

---

## 5. CLI-Befehle in der UI: Zuordnung

| CLI-Befehl | UI-Ort (Vorschlag) | Prioritaet |
|---|---|---|
| `template:suggest-from-document[s]` | Dialog "Template aus Lauf erstellen" (W1) | Hoch |
| `template:check-journey-documents` | Journey-"Items"-Seite + Match-Vorschau (W2) | Hoch |
| `document:context-history` | Item-Detail, Tab "Context" (W5) | Hoch |
| `template:heatmap`, `template:export-diagram` | Graph-Overlays "Fluss"/"Verweildauer" (W5) | Mittel |
| `process:status` | Statusleiste auf Item-Liste (W5) | Mittel |
| `events:list`, `events:show` | Item-Detail, Tab "Rohdaten" (Expertensicht) | Mittel |
| `template:check-document`, `check-process` | bereits in UI; kuenftig aus Result-Store (W4) | Mittel |
| `process-version:list` / `:create` | Template-Detail, Abschnitt "Versionen" | Niedrig |
| `context:coverage` | Template-Detail, Context-Profil-Abschnitt | Niedrig |
| `access:check-document`, `access:results` | bereits abgedeckt (Access-Seiten) | – |
| `process:reset`, `fixtures:load`, `demo:user:create`, `event:process-pending`, `amagno:*` | bewusst CLI-only (destruktiv / Ops / Connector) | – |

---

## 6. Querschnitts-Verbesserungen

- **Globale Suche** im Header: Item-UUID, externalId, Process Key,
  Template-Key — ein Eingabefeld, das auf die richtige Detailseite springt.
  Ersetzt die heutige reine UUID-Suchseite.
- **Konsistente Wegfuehrung:** einheitliches Breadcrumb-Muster
  (Prozesse → {processKey} → Items → {uuid}) auf allen Seiten; die
  Kontext-Pills (Graph/Assistant/Access/...) auf jeder Template-Unterseite
  identisch.
- **Begriffshilfen:** Glossar-Tooltips (aus `glossary.de.md`) fuer Begriffe
  wie Event Phase, Probe, Context Snapshot, direkt an den Tabellenkoepfen.
- **Interaktivitaet:** Fuer Live-Vorschauen (Match-Kandidaten, YAML-Lint,
  Graph-Refresh) reicht Turbo/Stimulus im vorhandenen
  AssetMapper/importmap-Setup; kein SPA noetig (deckt sich mit der Empfehlung
  im Frontend-Konzept).
- **Export dort, wo analysiert wird:** CSV/JSON-Export auf Item-Listen und im
  kuenftigen Findings-Center (Download-Muster existiert bereits bei der
  Access-Doku).

---

## 7. Priorisierte Umsetzungsreihenfolge

**Stufe 1 — Der Pfad wird durchgaengig (groesster gefuehlter Sprung):**
1. W1: "Template aus Lauf erstellen"-Dialog (Prozess + Journey, ein/mehrere
   Items)
2. W2: Journey-Match-Editor mit Live-Kandidaten-Vorschau + Journey-Items-Seite
3. W3 Stufe 1: YAML-Editor mit Validierung + "Vorschlag uebernehmen" aus dem
   Assistant
4. Navigation vereinheitlichen: ein Menuepunkt "Prozesse" (Keys mit und ohne
   Template), konsistente Breadcrumbs

**Stufe 2 — Verstehen und Klaeren:**
5. Item-Detail-Zusammenfuehrung mit Tabs (Journey / Soll-Ist / Context /
   Access / Rohdaten)
6. Decision-Point-Auswertungsspur + Klaerungs-Dialoge an Findings (W4.3/W4.4)
7. Listen-Hygiene: Pagination, Sortierung, Filter, globale Suche

**Stufe 3 — Skalieren und Vorbereiten:**
8. Persistierter Finding-/Check-Result-Store + asynchrone Berechnung +
   Findings-Center
9. Graph-Overlays Fluss/Verweildauer, Prozess-Statusleiste
10. Export-Funktionen, Glossar-Tooltips, Triage-Status fuer Findings

---

## 8. Vorbereitung auf das spaetere KPI-Dashboard

Das Dashboard selbst ist ausgeklammert, aber drei Punkte dieses Vorschlags
sind bewusst so geschnitten, dass es spaeter guenstig andockt:

- Der **Result-Store** (Stufe 3, Punkt 8) liefert die persistierte Datenbasis,
  ohne die ein Dashboard jede Kennzahl on-demand rechnen muesste.
- Die **Graph-Overlays** nutzen denselben `TemplateHeatmapReportBuilder`, der
  auch Liegezeiten je Prozessschritt berechnet — die Dashboard-Kachel
  "Verweildauer je Schritt" ist dann eine Aggregation bereits vorhandener
  Reports.
- Die **Prozessversions-Baselines** (`process-version:*` im Template-Detail)
  definieren die Zeitraeume, auf die sich Kennzahlen beziehen ("seit Version
  1.1 gilt ...").

---

## 9. Ausser Scope

- KPI-Dashboard und Kennzahlen-Kacheln (spaetere Ausbaustufe, siehe oben)
- Grafischer Prozess-Designer (bewusst gegen entschieden, siehe W3)
- Persistente Journey-Zuordnung von Items (dynamisches Matching bleibt)
- Rollen-/Rechtemodell fuer den Editor (im MVP: wer eingeloggt ist, darf
  editieren; ein Rechtemodell folgt mit Enterprise-Anforderungen)
