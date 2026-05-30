# Amagno Intelligence Tool

Das Amagno Intelligence Tool ist ein Symfony-basiertes Werkzeug fuer Process Intelligence in dokumentenbasierten Prozessen.

Der aktuelle Fokus liegt auf:

- Prozessereignisse erfassen und als fachliche Prozessdaten auswerten
- Process Templates aus vorhandenen Dokument-Timelines vorschlagen
- Dokument-Timelines gegen Soll-Templates pruefen
- Heatmaps fuer Prozessfluss und Durchlaufzeiten erzeugen
- Abweichungen, Engpaesse, KPIs und spaeter SLA-Verletzungen sichtbar machen

Amagno ist dabei der erste konkrete Connector. Der fachliche Kern soll langfristig generisch bleiben, sodass andere DMS- oder Workflow-Systeme ueber weitere Connectoren angebunden werden koennen.

## Aktueller Stand

Das Repository enthaelt zwei Welten:

- **Intelligence Tool**: neue Prozesslogik unter `src/Intelligence`, aktuelle CLI-Auswertungen und Template-/Heatmap-Funktionen.
- **Legacy AmagnoExporter**: alte Sync-, Matching-, Rendering- und Exportfunktionen. Diese bleiben vorerst erhalten, sind aber nicht mehr das Zielprodukt.

Die alten Commands wie `amagno:sync` und `amagno:verify-signatures` werden nicht entfernt, gelten aber als Legacy-/Uebergangsbereich. Amagno-Zugriffsfunktionen, die fuer Dokumentdaten, Merkmale oder Signaturen weiter gebraucht werden, sollen schrittweise in einen klaren Amagno-Connector ueberfuehrt werden.

## Wichtige Intelligence-Commands

Template aus einer Dokument-Timeline vorschlagen:

```bash
bin/console intelligence:template:suggest-from-document <documentUuid> <processKey>
```

Template aus mehreren Dokumenten vorschlagen:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> <documentUuid1> <documentUuid2>
```

Ohne explizite Dokument-UUIDs kann der Command Dokumente fuer einen Prozess automatisch auswaehlen:

```bash
bin/console intelligence:template:suggest-from-documents <processKey> --limit=50
```

Dokument gegen ein Process Template pruefen:

```bash
bin/console intelligence:template:check-document <documentUuid> <processKey> --template=templates/<processKey>.yaml
```

Heatmap-Report fuer einen Prozess erzeugen:

```bash
bin/console intelligence:template:heatmap <processKey> --template=templates/<processKey>.yaml --format=yaml
```

Nuetzliche Optionen:

- `--output=<path>` schreibt YAML/JSON in eine Datei.
- `--force` ueberschreibt eine vorhandene Ausgabedatei.
- `--document-version=<n>` begrenzt die Auswertung auf eine Dokumentversion.
- `--since=<datetime>` begrenzt automatische Dokumentauswahl zeitlich.
- `--order-by=occurred-at|received-at|occurred-then-received` steuert die Timeline-Sortierung.
- `--include-before` bezieht Before-Events in Template-Vorschlaege oder Heatmaps ein.

## Architektur

Zielarchitektur:

```text
App -> Connector/Amagno -> Core
App -> Core
```

### Core

Der Core enthaelt fachliche Modelle und Prozesslogik. Er darf keine direkte Abhaengigkeit zu Symfony, Doctrine, Amagno, Controllern, Repositories oder Zugangsdaten haben.

Wichtige Modelle sind unter anderem:

- `ProcessEvent`
- `ProcessTemplate`
- `ProcessTemplateStep`
- `ProcessTemplateTransition`
- `ProcessTemplateParallelGroup`
- `ProcessTemplateSuggestionResult`

### Connector/Amagno

Der Amagno-Connector kapselt Amagno-spezifische Details:

- Amagno-Payloads und Tags lesen
- Dokumentkontext und Merkmale aufloesen
- Amagno-Daten in fachliche Modelle uebersetzen

Heute liegen einige wiederverwendbare Klassen noch im alten Namespace `App\Service\Amagno`. Diese sollen schrittweise in eine klarere Connector-Struktur ueberfuehrt werden.

### App

Die App-Schicht enthaelt Symfony-Commands, Controller, Persistenz und Konfiguration. Sie darf Core und Connector nutzen und kuemmert sich um YAML-/JSON-I/O, Doctrine, Console-Ausgabe und technische Verdrahtung.

## Legacy AmagnoExporter

Der fruehere Exporter ist weiterhin im Repository vorhanden, aber fachlich nicht mehr der Produktkern.

Zum Legacy-Bereich gehoeren insbesondere:

- `bin/console amagno:sync`
- `bin/console amagno:verify-signatures`
- `src/Service/FibuExportService.php`
- `src/Service/Export/*`
- `src/Service/Processing/*`
- `oldProject/*`
- die alte Settings-Bridge unter `/settings`

Diese Bestandteile sollen nicht unkoordiniert geloescht werden, weil sie noch durch Commands, Tests oder Amagno-Zugriffsfunktionen genutzt werden. Neue Process-Intelligence-Funktionalitaet sollte nicht mehr auf Export-Matching, alte Template-Renderer oder `oldProject` aufbauen.

## Open-Core-Ziel

Perspektivisch soll ein offener, DMS-unabhaengiger Process-Intelligence-Kern entstehen. Proprietaere oder kundenspezifische Anbindungen, etwa ein Amagno-Connector oder produktive Betriebsintegration, koennen darauf aufsetzen, ohne den Core an ein einzelnes System zu binden.

## Weitere Dokumentation

- [ARCHITECTURE.md](ARCHITECTURE.md): Zielarchitektur und Schichtungsregeln
- [docs/doctrine-persistence.md](docs/doctrine-persistence.md): Doctrine-Persistenz fuer Intelligence-Daten
- [docs/index.html](docs/index.html): historische Entwicklerdoku mit Legacy-Hinweisen
- [docs/admin-guide.html](docs/admin-guide.html): historische Betriebsdoku mit Legacy-Hinweisen
