# APRIL Onboarding Wizard

## Zielbild

Der interaktive Onboarding-Wizard soll neuen Nutzerinnen und Nutzern den Einstieg in APRIL erleichtern, ohne dass sie zuerst die komplette Architektur, alle CLI-Befehle oder das YAML-Template-Format verstehen muessen.

APRIL bleibt ein Analyse- und Intelligence-Werkzeug fuer dokumentenbasierte Prozesse. Der Wizard ist kein Ersatz fuer die Core-Mechanik, sondern eine gefuehrte Schicht darueber:

- Er erklaert die Kernkonzepte anhand neutraler Beispiele.
- Er erzeugt oder laedt Demo-Daten ohne Connector-Abhaengigkeit.
- Er fuehrt durch Template, Events, Context Snapshots, Checks und Diagramme.
- Er macht sichtbar, wie aus Ereignissen Prozesswissen entsteht.
- Er trennt Community-Core, optionale Connectoren und Enterprise-Funktionen klar.

Der Wizard soll fuer den Community-Core zuerst als Lern- und Demo-Werkzeug dienen. Spaeter kann er zu einem Setup-Assistenten fuer reale Projekte wachsen.

## Prinzipien

- Neutral: keine Amagno-, DMS- oder Enterprise-Begriffe im Community-Pfad.
- Reproduzierbar: alle Demo-Schritte laufen mit synthetischen Daten.
- Nicht-magisch: jeder Wizard-Schritt zeigt, welche Datei, welcher Befehl oder welches Konzept betroffen ist.
- Reversibel: Demo-Daten koennen geloescht oder neu geladen werden.
- Core-first: der Wizard nutzt vorhandene Ports, Templates, Event Store und Auswertungen.
- CLI und Web teilen dieselbe fachliche Wizard-Definition.

## Benutzerfluss

Der ideale Erstkontakt besteht aus einer kurzen, gefuehrten Strecke:

1. Willkommen
   - APRIL kurz erklaeren: Events, Context, Templates, Checks, Findings.
   - Zwischen Demo-Modus und Projekt-Modus unterscheiden.

2. Umgebung pruefen
   - PHP-/Symfony-/Datenbankstatus anzeigen.
   - Pruefen, ob Demo-Templates vorhanden sind.
   - Optional anzeigen, welche Schritte noch nicht eingerichtet sind.

3. Demo-Prozess waehlen
   - `incident-management` als neutrales Standardbeispiel.
   - Spaeter weitere Demo-Szenarien wie Procurement, HR Request oder Support Case.

4. Demo-Daten laden
   - synthetische Events importieren.
   - Context Snapshots inline oder aus neutralen Fixture-Dateien laden.
   - Prozessinstanzen erzeugen oder bestehende Demo-Daten zuruecksetzen.

5. Prozess verstehen
   - Steps und Decision Points anzeigen.
   - Mermaid-Diagramm generieren.
   - Context-Felder und Routing-Regeln erklaeren.

6. Check ausfuehren
   - erwarteten Pfad pruefen.
   - bewusste Abweichung pruefen, z. B. falsches Routing oder fehlender Abschluss.
   - Findings und Deviation-Texte erklaeren.

7. Naechste Schritte
   - eigenes Template kopieren.
   - eigene Events senden.
   - Dokumentation zu Event API, Templates und Docker Quickstart verlinken.

## CLI-Variante

Die CLI-Variante ist der erste sinnvolle Einstieg, weil APRIL bereits viele Console-Kommandos besitzt und Community-Nutzerinnen und -Nutzer damit reproduzierbare Schritte ausfuehren koennen.

Moegliche Kommandostruktur:

```text
bin/console april:onboarding
bin/console april:onboarding --scenario=incident-management
bin/console april:onboarding:status
bin/console april:onboarding:reset-demo
```

Der interaktive Ablauf koennte Symfony Console Questions nutzen:

- Szenario auswaehlen.
- Demo-Daten laden oder vorhandene Daten behalten.
- Diagramm als Mermaid anzeigen oder in Datei schreiben.
- Template-Check ausfuehren.
- Abweichungsfall simulieren.

Wichtig ist, dass jeder interaktive Schritt auch nicht-interaktiv ausfuehrbar ist. Fuer CI, Tutorials und Dokumentation sollten Flags reichen:

```text
bin/console april:onboarding --scenario=incident-management --load-demo --run-checks --format=text
```

Die CLI sollte keine produktiven Secrets erfragen. Connector-Credentials gehoeren nicht in den Community-Wizard.

## Web-Variante

Die Web-Variante soll denselben Lernpfad visuell fuehren. Sie ist spaeter sinnvoll, wenn der Community-Core einen stabilen lokalen Quickstart besitzt.

Moegliche Seitenstruktur:

- Start: Szenarioauswahl und Systemstatus.
- Prozess: Template-Steps, Routing-Regeln und Mermaid-Visualisierung.
- Events: importierte Demo-Events und Timeline.
- Context: relevante Felder und Snapshots je Event.
- Findings: Check-Ergebnis, Abweichungen, Warnungen und erklaerte Ursachen.
- Weiterbauen: Hinweise auf eigene Templates, Event API und lokale Entwicklung.

Die Web-UI sollte keine Marketing-Landingpage sein. Der erste Screen soll direkt ein nutzbares Onboarding-Dashboard zeigen: Status links, aktueller Wizard-Schritt in der Mitte, Ergebnis/Diagramm rechts oder darunter.

CLI und Web sollten eine gemeinsame Wizard-Beschreibung nutzen, etwa als interne PHP-Konfiguration oder spaeter als YAML/JSON-Definition. Dadurch bleiben Texte, Szenarien, Voraussetzungen und Schrittlogik konsistent.

## Demo-Szenarien

### Incident Management

Das erste neutrale Szenario ist Incident Management mit Routing-Entscheidung.

Es zeigt:

- lineare Start- und Endschritte
- Routing nach Context-Feldern
- alternative Zielpfade
- Mermaid-Diagramm
- korrekten und falschen Prozesspfad
- Findings bei fehlendem oder unerwartetem Schritt

Beispielvarianten:

- Security Incident: `data_exposure=true` fuehrt zu Security Review.
- SaaS Outage: `system_type=saas`, `severity=critical` fuehrt zur Provider-Eskalation.
- Business Process Issue: `category=business_process` fuehrt zur Spezialistengruppe.
- Low Severity Ticket: `severity=low` fuehrt zur First-Level-Loesung.

### Weitere Community-Szenarien

Spaetere neutrale Szenarien sollten unterschiedliche APRIL-Faehigkeiten zeigen:

- Procurement Request: Betrag, Kostenstelle und Freigabestufe.
- Customer Support Case: SLA, Eskalation und Abschluss.
- Access Request: Pflichtschritte, Entscheidung nach Rolle und Audit Trail.
- Data Correction Request: Rueckfragen, Wiederholung und Abschluss.

Alle Szenarien muessen ohne DMS, ERP, private Connectoren oder echte Kundendaten funktionieren.

## Guided Learning

Der Wizard soll nicht nur Befehle ausfuehren, sondern Wissen aufbauen. Jeder Schritt sollte drei Ebenen anbieten:

- Kurz: Was passiert gerade?
- Technisch: Welche Datei, welcher Port oder welcher Command ist beteiligt?
- Vertiefung: Link zur relevanten Dokumentation.

Beispiele:

- Beim Template-Schritt: Verweis auf `docs/templates/reference.md`.
- Beim Event-Schritt: Verweis auf `docs/intelligence/event-api.md`.
- Beim Snapshot-Schritt: Verweis auf `docs/intelligence/context-history.md`.
- Beim Diagramm-Schritt: Verweis auf Mermaid-/BPMN-nahe Darstellung.
- Beim Quickstart-Schritt: Verweis auf `docs/architecture/docker-quickstart.md`.

Eine gute Guided-Learning-Erfahrung sollte ausserdem bewusst Fehler zeigen. Nutzerinnen und Nutzer lernen APRIL schneller, wenn sie sehen, wie ein falscher Pfad, fehlender Context oder ein nicht abgeschlossener Prozess aussieht.

## Erweiterungen

Moegliche spaetere Erweiterungen:

- Wizard-Definition als deklaratives Modell in YAML, JSON oder PHP-Konfiguration, das CLI und Web-UI gemeinsam verwenden.
- Wizard-State speichern, damit Nutzerinnen und Nutzer den Einstieg fortsetzen koennen.
- Demo-Daten als JSONL-Fixtures versionieren.
- Szenario-Katalog mit Tags wie `routing`, `sla`, `context`, `deviation`.
- Export der Ergebnisse als Markdown-Bericht.
- Webbasierter Template-Editor mit Vorschau.
- Mermaid/SVG-Export direkt aus dem Wizard.
- Schrittweise Migration vom Demo-Modus zum eigenen Projekt.
- Plugin-/Connector-Hinweise, ohne Community-Core davon abhaengig zu machen.
- Optionaler Enterprise-Pfad fuer echte DMS-/ERP-Connectoren.

## Open-Source-Roadmap

Der Onboarding-Wizard ist ein wichtiger Baustein fuer eine public-faehige APRIL-Version, weil er den Community-Core ohne private Umgebung nachvollziehbar macht.

Empfohlene Roadmap-Einordnung:

1. Dokumentationskonzept
   - Vision, Benutzerfluss und Szenarien beschreiben.
   - Keine Implementierung.

2. Neutrale Demo-Grundlage
   - `incident-management` Template bereitstellen.
   - synthetische Event-Fixtures ergaenzen.
   - keine Connector-Abhaengigkeit.

3. CLI-Wizard MVP
   - Status pruefen.
   - Demo laden.
   - Template anzeigen.
   - Check ausfuehren.
   - Diagramm ausgeben.

4. Docker-Quickstart verbinden
   - Wizard als naechsten Schritt nach `docker compose up`.
   - keine Node-/Vite-Abhaengigkeit fuer den MVP.

5. Web-Wizard
   - lokale Demo visuell fuehren.
   - gleiche Szenarien und Texte wie CLI verwenden.

6. Erweiterte Lernpfade
   - SLA, Context History, Decision Rules, Deviations und Reporting einzeln erklaeren.

7. Optionaler Connector-/Enterprise-Pfad
   - getrennt vom Community-Onboarding.
   - keine Secrets oder produktiven Systeme im Wizard speichern.

Der Wizard sollte erst implementiert werden, wenn Event Store, neutrale Demo-Daten, Template-Checks und Docker-Quickstart stabil genug sind. Bis dahin dient dieses Konzept als Leitplanke fuer die Open-Source-Vorbereitung.
