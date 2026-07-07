# GitHub-Readiness-Inventur fuer APRIL

Stand: 2026-07-07

Ziel dieser Inventur ist die Trennung zwischen einem public/communityfaehigen Kern und privaten bzw. enterprise-spezifischen Anteilen. Das Repository ist in der aktuellen Form nicht GitHub-ready. Es enthaelt produktnahe Konfiguration, interne Infrastrukturdetails, kundenspezifische Matching-/Template-Daten und eine echte Datenbank-URL mit Passwort.

## 1. Public geeignet

### Generische Kernlogik

- `src/Intelligence/Domain/*`
  - Process Templates, Canonical Events, DocumentRef, ContextSnapshot, Decision Rules, Deviations, KPI-/Timeline-Logik.
  - Der Bereich ist fachlich weitgehend DMS-neutral und bereits durch Architekturtests abgesichert.
- `src/Intelligence/Application/*`
  - Prozessauswertung, Template-Checks, Vorschlagslogik, Context-Diffs, Journey-/Timeline-Views.
  - Public geeignet, sofern Beispiele und Tests keine internen Prozessnamen mehr verwenden.
- `src/Intelligence/Infrastructure/EventStore/InMemoryEventStore.php`
- `src/Intelligence/Infrastructure/EventStore/JsonFileEventStore.php`
- `src/Intelligence/Infrastructure/Normalizer/GenericPayloadEventNormalizer.php`
- `src/Intelligence/Infrastructure/Context/NullContextProvider.php`
- `src/Intelligence/Infrastructure/Context/InMemoryContextProfileProvider.php`
- `src/Intelligence/Infrastructure/Process/InMemory*.php`
- `src/Intelligence/Bpmn/*`
  - Generische Mermaid-/SVG-/BPMN-nahe Darstellung.
- `src/View/*`, `src/Security/EnvUserProvider.php`
  - Generische Web-App-Hilfslogik.
- `src/Service/Settings/SettingsProfileProvider.php`
- `src/Service/Settings/TemplatePlaceholderParser.php`
  - Public geeignet, wenn die zugehoerigen Beispiel-Matchings/Templates anonymisiert sind.

### Tests

- `tests/Intelligence/Domain/*`
- `tests/Intelligence/Application/*`
- `tests/Intelligence/Infrastructure/Normalizer/*`
- `tests/Intelligence/Bpmn/*`
- `tests/Fake/*`
- `tests/View/*`
- `tests/Architecture/*`
- `tests/Controller/App/*`, soweit sie keine produktnahen Templates voraussetzen.
- `tests/Service/Settings/SettingsProfileProviderTest.php`
- `tests/Service/Settings/TemplatePlaceholderParserTest.php`

Viele Tests nutzen neutrale Fixtures wie `doc-1`, `uuid-1`, `user-1`. Einzelne Tests enthalten aber noch interne Namen wie `nevaris_export` und sollten vor Public bereinigt werden.

### Neutrale Beispiele

- Kleine synthetische Event-/Template-Beispiele koennen public werden, sollten aber aus echten Amagno-IDs herausgeloest werden.
- `tests/Fake/*` und InMemory-Provider eignen sich als Community-Beispiele.
- `config/amagno_connections.json` ist aktuell nur teilweise neutral und sollte in eine `config/amagno_connections.example.json` ueberfuehrt werden.

### Technische Dokumentation

- `ARCHITECTURE.md`
- `docs/intelligence/event-api.md`
- `docs/intelligence/context-history.md`
- `docs/intelligence/process-versioning.md`
- `docs/templates/reference.md`
- `docs/templates/cookbook.md`
- Teile von `README.md`, sofern Amagno/Legacy-Exporter- und interne Pfadabschnitte getrennt werden.

## 2. Nur nach Bereinigung public geeignet

### Dateien mit internen Namen

- `README.md`
  - Enthaelt `debitoren_intake`, `nevaris_export`, Amagno-spezifische Abschnitte und produktnahe Hinweise.
- `tests/Intelligence/Domain/ProcessTemplateArrayFactoryTest.php`
  - Enthält `nevaris_export`.
- `tests/Intelligence/Application/JourneyTemplateCheckServiceTest.php`
  - Enthaelt `nevaris_export`.
- `tests/Command/IntelligenceDocumentContextHistoryCommandTest.php`
  - Enthaelt `nevaris-import-row-created`.
- `docs/admin-guide.html`, `docs/index.html`, `docs/start.html`, `docs/process-analysis-guide.md`
  - Stark produktnah und Amagno-/Legacy-lastig.

### Beispielkonfigurationen mit echten Pfaden, Hosts oder IDs

- `.env`
  - Enthaelt aktuell eine echte `DATABASE_URL` mit Benutzer, Passwort und privater IP.
  - Muss fuer Public zu `.env.example` ohne Secrets werden.
- `.env.dev`
  - Enthaelt einen konkreten `APP_SECRET`; fuer Public nicht committen oder neutralisieren.
- `composer.json`
  - `license` steht auf `proprietary`.
  - Private Repository-URL: `http://10.0.3.198:3000/...`.
  - `secure-http: false` ist public nicht akzeptabel.
- `composer.lock`
  - Enthaelt dieselbe private VCS-Quelle.
- `.gitea/workflows/ci.yml`
  - Nutzt private Gitea Composer Auth und internen Hostnamen.
- `.gitea/workflows/deploy-staging.yml`
  - Staging-Deployment per SSH gehoert nicht in Community.
- `config/april/process-templates/ai-rechnungen.yaml`
  - Enthaelt Amagno-Connector, Magnet-IDs, konkrete Tag-UUIDs und produktnahe Prozessnamen.
- `config/amagno_connections.json`
  - Teilweise Platzhalter, aber weiterhin Amagno-/UNC-/GUID-nahe Struktur.

### Kundenspezifische Templates

- `oldProject/onprem.txt`
- `oldProject/debitoren.txt`
- `config/april/process-templates/ai-rechnungen.yaml`

Diese Dateien enthalten Rechnungs-/Debitoren-/Kreditoren-nahe Fachlogik und sollten fuer Public durch synthetische Minimalbeispiele ersetzt werden.

### Produktnahe Dokumentation

- `docs/admin-guide.html`
- `docs/index.html`
- `docs/start.html`
- `docs/process-analysis-guide.md`
- `docs/intelligence/access-visibility-concept.md`
- `docs/intelligence/frontend-concept.md`
- `docs/intelligence/process-documentation-concept.md`

Diese Dokumente sind wertvoll, aber fuer Public nur nach Produkt-/Kundenneutralisierung geeignet. Sie enthalten konkrete Betriebsablaeufe, Legacy-Exporter-Hinweise, Amagno-Betrieb und Beispielkonfigurationen mit Credential-Strukturen.

## 3. Privat/Enterprise behalten

### Konkrete Connectoren

- `src/Intelligence/Connector/Amagno/*`
- `src/Intelligence/Infrastructure/Access/AmagnoMagnetDocumentsAccessProbeProvider.php`
- `src/Service/Amagno/*`
- `src/EventSubscriber/AmagnoCredentialsSubscriber.php`
- `src/Service/SignatureCheck/AmagnoSignatureCheckService.php`
- `src/SignatureCheck/*`

Diese Komponenten enthalten konkrete Amagno-API- und Signatur-/Freigabeintegration. Fuer Community waere stattdessen ein generischer Connector-Port plus Fake-/Demo-Connector sinnvoll.

### Kundenspezifische Integrationen

- `src/Command/AmagnoSyncCommand.php`
- `src/Command/AmagnoVerifySignaturesCommand.php`
- `src/Service/FibuExportService.php`
- `src/Service/Export/AmagnoExporter.php`
- `src/Service/Export/AmagnoUploader.php`
- `src/Service/Export/FtpExporter.php`
- `src/Service/Export/SqlExporter.php`
- `src/Service/Processing/DocumentMatrixBuilder.php`
- `src/Service/Processing/TemplateRenderer.php`
- `src/Service/Processing/MatchingProvider.php`
- `src/Service/Processing/StampService.php`
- `src/Service/Checkpoint/CheckpointStore.php`

Das ist Legacy-Exporter-/Enterprise-Funktionalitaet und sollte in ein privates Paket oder einen Enterprise-Ordner.

### Produktive Matching-/Template-Dateien

- `oldProject/matching.json`
  - Enthaelt Profile wie `Nevaris Export`, `Aufmass`, `Kreditoren_prod`, `Gutschriftsanzeigen_prod`, zahlreiche UUIDs und fachliche Mappingformeln.
- `oldProject/onprem.txt`
- `oldProject/debitoren.txt`
- `config/april/process-templates/ai-rechnungen.yaml`

### Infrastruktur-/Deploymentdetails

- `amagno_sync.bat`
- `.gitea/workflows/deploy-staging.yml`
- `.gitea/workflows/ci.yml`, solange private Package-Auth benoetigt wird.
- `.env`, `.env.dev`
- `config/amagno_connections.json`, sofern nicht konsequent zu einem anonymen Beispiel reduziert.

### Kommerziell wertvolle Spezialfunktionen

- Prozess-Template-Suggestion-Services koennen als Produktkern strategisch wertvoll sein:
  - `src/Intelligence/Application/ProcessTemplateSuggestionService.php`
  - `src/Intelligence/Application/ProcessTemplateMultiDocumentSuggestionService.php`
  - `src/Intelligence/Application/TemplateModelingSuggestionAnalyzer.php`
  - `src/Intelligence/Application/DecisionPointCandidateDetector.php`
  - `src/Intelligence/Application/DecisionPointFieldAnalyzer.php`
- Visibility-/Access-Checks mit Amagno-Probes:
  - `src/Intelligence/Application/VisibilityCheckService.php`
  - `src/Intelligence/Application/AccessCoverageReportBuilder.php`
  - `src/Intelligence/Infrastructure/Access/AmagnoMagnetDocumentsAccessProbeProvider.php`

Falls Community nur den Kern zeigen soll, diese Features als Enterprise-Add-on auslagern.

## 4. Sofort kritisch

Diese Punkte muessen vor jeder GitHub-Veroeffentlichung bereinigt werden.

### Secrets, Tokens, Passwoerter

- `.env`
  - `DATABASE_URL="postgresql://amagno_intelligence:MyXejEJGNKrB3G@10.0.3.70:5432/amagno_intelligence?..."`
  - Das ist ein echtes Datenbankpasswort mit interner IP und Datenbanknamen.
- `.env.dev`
  - Konkreter `APP_SECRET`.
- `amagno_sync.bat`
  - `set USER=TECHINFRA\svc_amagno`
  - `set PASS=HIER_DEIN_PASSWORT`
  - Auch wenn das Passwort ein Platzhalter ist, User/Domain/Pfade/IPs sind intern.
- `oldProject/settings.php`
  - Enthaelt Legacy-Code, der Passwoerter im Browser-`sessionStorage` speichert.
  - Enthaelt historischen AES-Key im Joomla-Zweig: `oRWyJKv5xyXFnw0fiJ4cW8fR9mIEgYy4jpZa1Mi7`.

### Hostnamen/IPs

- `.env`: `10.0.3.70`
- `composer.json`: `10.0.3.198:3000`
- `composer.lock`: private VCS-URL zu `10.0.3.198:3000`
- `.gitea/workflows/ci.yml`: Composer Auth fuer `10.0.3.198:3000`
- `amagno_sync.bat`: `172.30.74.146`, UNC-Share `\\172.30.74.146\FileService`, Pfad `C:\inetpub\amagno_nev_interface`
- `docs/admin-guide.html`: interne Betriebs-/Windows-Pfade und Legacy-Sync-Beispiele.

### Reale Kunden-/Firmennamen

- `oldProject/matching.json`: `Nevaris Export`
- `amagno_sync.bat`: `amagno_nev_interface`
- Tests und Dokumentation: `nevaris_export`, `nevaris-import-row-created`
- Fachbegriffe wie `Debitoren`, `Kreditoren`, `Aufmass`, `Gutschriftsanzeigen` sind nicht zwingend geheim, aber stark kundenspezifisch.

### Produktive IDs

- `oldProject/matching.json`: zahlreiche Tag-/Gruppen-UUIDs und produktive Profile.
- `config/april/process-templates/ai-rechnungen.yaml`: konkrete `tag_id`-UUIDs und Magnet-IDs `1001`, `1002`, `1009`.
- Dokumentation enthaelt Beispiel-GUID-Strukturen, teils als Platzhalter; echte IDs muessen getrennt geprueft werden.

## Zielstruktur Community vs Enterprise

### Community-Repository

Vorschlag: `april-community`

```text
src/
  Intelligence/
    Domain/
    Application/
    Port/
    Infrastructure/
      EventStore/
      Normalizer/
      Context/
      Process/
      Tenant/
      Template/
    Bpmn/
  Controller/App/
  Controller/IntelligenceEventController.php
  Security/
  View/
config/
  april/process-templates/example-invoice.yaml
  packages/
  routes.yaml
templates/
  web/
tests/
docs/
  architecture.md
  event-api.md
  templates/
```

Community sollte enthalten:

- DMS-neutrale Ports.
- Fake-/InMemory-Adapter.
- Generische Process-Template-Engine.
- Generische Event-API.
- Demo-Template mit synthetischen Feldern.
- SQLite- oder in-memory-freundliche Testkonfiguration.

### Enterprise-/Private-Repository

Vorschlag: `april-enterprise` oder privates Paket `april-amagno-connector`

```text
src/
  Intelligence/Connector/Amagno/
  Intelligence/Infrastructure/Access/Amagno*/
  Service/Amagno/
  Service/Export/
  Service/Processing/
  SignatureCheck/
  Command/Amagno*
config/
  amagno_connections.example.json
private/
  process-templates/
  matching/
  deployment/
```

Enterprise sollte enthalten:

- Amagno-Connector.
- Legacy-Exporter.
- Signatur-/Freigabepruefung.
- Kundenspezifische Templates/Matchings.
- Deployment- und Betriebsanleitungen.
- Private CI/CD-Konfiguration.

## Dateien vor GitHub-Veroeffentlichung

### Loeschen oder aus Public entfernen

- `.env`
- `.env.dev`
- `amagno_sync.bat`
- `.gitea/workflows/deploy-staging.yml`
- `.gitea/workflows/ci.yml`
- `oldProject/matching.json`
- `oldProject/onprem.txt`
- `oldProject/debitoren.txt`
- `oldProject/settings.php`
- `settings_dump.html`

### Verschieben nach Enterprise

- `src/Intelligence/Connector/Amagno/*`
- `src/Intelligence/Infrastructure/Access/AmagnoMagnetDocumentsAccessProbeProvider.php`
- `src/Service/Amagno/*`
- `src/Service/Export/*`, sofern Exporter nicht generisch abstrahiert werden.
- `src/Service/Processing/*`, sofern es Legacy-Exporter-Rendering bleibt.
- `src/SignatureCheck/*`
- `src/Service/SignatureCheck/*`
- `src/EventSubscriber/AmagnoCredentialsSubscriber.php`
- `src/Command/AmagnoSyncCommand.php`
- `src/Command/AmagnoVerifySignaturesCommand.php`
- Amagno-spezifische Tests unter `tests/Intelligence/Connector/Amagno/*`, `tests/Service/Amagno/*`, `tests/SignatureCheck/*`.

### Anonymisieren oder ersetzen

- `config/april/process-templates/ai-rechnungen.yaml`
  - Durch `example-invoice.yaml` mit synthetischen Prozessschritten, synthetischen Tags und ohne Magnet-IDs ersetzen.
- `config/amagno_connections.json`
  - In `config/amagno_connections.example.json` umbenennen und alle produktnahen Namen/Pfade entfernen.
- `README.md`
  - Amagno-/Legacy-Abschnitte in Enterprise-Doku verschieben.
  - Community-README auf Installation, Event-API, Template-System und Demo beschraenken.
- `docs/admin-guide.html`, `docs/index.html`, `docs/start.html`, `docs/process-analysis-guide.md`
  - Entweder entfernen oder stark anonymisieren.

## Fehlende Public-Metadaten

### `.env.example`

Fehlt. Sollte enthalten:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
APP_SHARE_DIR=var/share
APRIL_APP_USERNAME=april
APRIL_APP_PASSWORD_HASH=
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"
INTELLIGENCE_EVENT_SECRET=
APRIL_PROCESS_TEMPLATE_DIR=config/april/process-templates
```

Amagno-Variablen gehoeren in ein Enterprise-Beispiel, nicht in Community.

### README

Vorhanden, aber nicht GitHub-ready. Es fehlt:

- klare Community/Enterprise-Abgrenzung
- schnelle lokale Installation ohne private Composer-Quelle
- SQLite- oder Docker-basierte Demo
- Security-Hinweise fuer Event Secret und Web Login
- Hinweis auf nicht enthaltene Enterprise-Connectoren

### LICENSE

Fehlt. `composer.json` steht aktuell auf `proprietary`. Fuer Public muss eine Lizenzentscheidung getroffen werden, z. B. MIT, Apache-2.0, AGPL-3.0 oder weiterhin kein Public Release.

### CONTRIBUTING

Fehlt. Benoetigt:

- lokale Setup-Schritte
- Testbefehle
- Coding-Style
- Architekturregeln: Domain darf keine Connector-Abhaengigkeit haben
- Umgang mit Fixtures und Secrets

### GitHub Actions

Fehlt. Es gibt nur `.gitea/workflows/*`. Public braucht `.github/workflows/ci.yml` ohne private Composer-Auth:

- Checkout
- PHP Setup
- Composer install ohne private Repos
- PHP syntax check
- PHPUnit
- optional PHPStan/Psalm, wenn eingefuehrt

### SECURITY.md

Fehlt. Sinnvoll wegen Event-API und Connectoren.

## Sichere Reihenfolge fuer kleine Commits

1. `chore(security): remove committed environment secrets`
   - `.env` auf `.env.example` umstellen oder `.env` neutralisieren.
   - `.env.dev` entfernen oder neutralisieren.
   - Sicherstellen, dass `.env.local` ignoriert bleibt.

2. `chore(composer): remove private package source from community build`
   - Private VCS-Repository-URL entfernen.
   - Amagno-Connector-Abhaengigkeit aus Community entfernen oder durch Interface/Fake ersetzen.
   - `composer.lock` neu erzeugen.

3. `chore(ci): replace private gitea workflows`
   - `.gitea/workflows/*` entfernen oder nach Enterprise verschieben.
   - `.github/workflows/ci.yml` mit public-safe Tests einfuehren.

4. `refactor(connector): split amagno connector into enterprise package`
   - Amagno-spezifische Services, Commands, Tests und EventSubscriber verschieben.
   - Community behält nur Ports und Fakes.

5. `chore(fixtures): replace productive matching and templates`
   - `oldProject/*` entfernen.
   - `config/april/process-templates/ai-rechnungen.yaml` durch synthetisches Demo-Template ersetzen.
   - Tests auf synthetische Templates anpassen.

6. `docs: rewrite public readme and architecture docs`
   - README neu strukturieren.
   - Produkt-/Kundennamen entfernen.
   - Admin-/Legacy-Dokumente in Enterprise verschieben.

7. `chore(license): add public license and contribution docs`
   - `LICENSE`
   - `CONTRIBUTING.md`
   - `SECURITY.md`
   - optional `CODE_OF_CONDUCT.md`

8. `test: verify public baseline without private services`
   - frischer Clone-Test.
   - `composer install`
   - `php bin/console lint:container --env=test`
   - PHPUnit ohne externe DB und ohne Amagno.

## Empfehlung

APRIL sollte nicht als Ganzes public gestellt werden. Public-faehig ist vor allem der DMS-neutrale Intelligence-Kern mit Ports, InMemory-Implementierungen, Template-/Rule-Engine, Event-API und neutralen Tests. Der Amagno-Connector, Legacy-Exporter, produktive Matching-Dateien, produktive Templates und Deploymentdetails sollten privat bleiben oder als Enterprise-Erweiterung ausgeliefert werden.
