# APRIL Core

APRIL Core ist der DMS- und Tool-unabhaengige Kern fuer die Aufzeichnung, Analyse und Bewertung dokumentenbasierter Prozesse. Der Core kennt fachliche Ereignisse, Dokumentreferenzen, Prozessinstanzen, Context Snapshots, Soll-Prozesse und Auswertungen. Er soll keine direkte Abhaengigkeit zu einem konkreten DMS, ERP, Workflow-System oder proprietaeren Connector enthalten.

## Event-basierte Prozessaufzeichnung

APRIL verarbeitet eingehende Ereignisse als fachliche Prozessereignisse. Ein Event beschreibt, was mit einem Dokument oder Prozess passiert ist, wann es passiert ist und zu welcher Prozessinstanz es gehoert. Der Event Store ist append-only gedacht: Ereignisse werden ergaenzt, aber nicht stillschweigend ueberschrieben.

Wichtige Core-Ideen:

- stabile externe Event-Schluessel fuer Idempotenz
- Dokumentreferenzen mit Quelle, externer ID, UUID und Version
- historische Prozessinstanzen statt nachtraeglicher Umschreibung
- Context Snapshots als Zustand zum Ereigniszeitpunkt

## YAML-Soll-Prozesse und Templates

Soll-Prozesse werden als versionierbare Templates modelliert. YAML eignet sich dafuer als menschenlesbares Format, das fachliche Schritte, Uebergaenge, Varianten, SLA-Regeln, Context Profile und Decision Rules beschreibt.

Der Core bewertet Ereignisse und Dokumentkontext gegen diese Templates. Dadurch kann APRIL feststellen, ob ein Prozess dem erwarteten Ablauf folgt oder ob Abweichungen entstehen.

## Context-aware Checks

APRIL trennt den Prozesskern vom konkreten Context Provider. Der Core fragt benoetigte Felder ueber Ports ab oder verarbeitet Kontext, der bereits im Event geliefert wurde. Dadurch kann derselbe Prozesscheck mit unterschiedlichen Quellen funktionieren.

Context-aware Checks koennen unter anderem pruefen:

- benoetigte fachliche Merkmale
- Dokumentversionen
- Entscheidungsregeln
- SLA-Fristen
- erlaubte und unerwartete Prozesspfade
- Sichtbarkeits- oder Zugriffsergebnisse, wenn ein passender Provider vorhanden ist

## Timeline- und Deviation-Analyse

Aus Events, Prozessinstanzen und Snapshots erzeugt APRIL Zeitachsen. Diese Timelines machen sichtbar, welche Schritte bereits passiert sind, welche Schritte fehlen, welche Reihenfolge verletzt wurde und wo Engpaesse oder Ruecklaeufer auftreten.

Deviation-Analysen leiten daraus fachliche Befunde ab:

- fehlende Schritte
- unerwartete Schritte
- falsche Reihenfolgen
- SLA-Verletzungen
- Kontextabweichungen
- unvollstaendige oder widerspruechliche Dokumentzustaende

## Mermaid-Visualisierung

Der Core kann Prozessmodelle und Auswertungsergebnisse in textbasierte Diagrammformate wie Mermaid ueberfuehren. Diese Darstellung ist bewusst leichtgewichtig und repository-freundlich. Sie eignet sich fuer Dokumentation, Reviews und einfache Visualisierungen ohne proprietaere Modellierungswerkzeuge.

## Human-readable Dokumentation

APRIL soll Prozesswissen nicht nur maschinenlesbar speichern, sondern auch fuer Menschen erklaeren. Aus Templates, Regeln und Befunden koennen lesbare Prozessdokumentationen, Check-Ergebnisse und Entscheidungsgrundlagen entstehen.

Das Ziel ist, dass Fachbereiche und Entwicklung dieselbe Prozessbeschreibung diskutieren koennen.

## Bewusst nicht Core

Nicht zum APRIL Core gehoeren:

- konkrete DMS-, ERP- oder Workflow-API-Clients
- Amagno-spezifische API-Aufrufe, Tokenprovider und Credential-Events
- produktive Kunden-Templates und Matching-Dateien
- kundenspezifische Export-, Upload- oder Signaturprozesse
- private Composer-Repositories und private CI/CD-Konfiguration
- Deployment-, Betriebs- und Infrastrukturwissen
- SaaS-/Multi-Tenant-Produktfunktionen, sofern sie nicht als generische Ports benoetigt werden

Diese Teile gehoeren in optionale Connectoren, private Erweiterungen oder Enterprise-Pakete.
