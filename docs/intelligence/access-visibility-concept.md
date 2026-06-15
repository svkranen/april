# Konzept: Sichtbarkeits- und Berechtigungspruefungen

Dieses Konzept beschreibt, wie APRIL Sichtbarkeits- und Berechtigungsanforderungen
in Amagno-Prozessen modellieren, pruefen und dokumentieren kann, ohne eine
vollstaendige Amagno-ACL-Engine nachzubauen.

Ziel ist ein kontrollierbarer Ansatz mit neutralen technischen Access-Probes,
fachlicher Logik im YAML-Template, historisierten Ergebnissen und spaeterer
human-readable Dokumentation. Amagno-Magnete sind dabei nur der erste konkrete
Probe-Typ.

## Bestehender Code: relevante Stellen

- Template-Parsing: `ProcessTemplateArrayFactory` baut aktuell `steps`,
  `transitions`, `parallel_groups`, `context_profile`, `field_mapping`,
  `decision_points`, `sign_checks`, `connector` und `context_policy`.
- Template-Modell: `ProcessTemplate` ist ein readonly DTO. Erweiterungen sollten
  ueber neue readonly Domain-Objekte ergaenzt werden, statt rohe Arrays
  durchzureichen.
- Eventverarbeitung: `GenericPayloadEventNormalizer` akzeptiert `eventPhase`
  mit `before`, `after`, `unknown`. `EventReceiver` speichert die Phase in
  `ProcessEventRecord`.
- Prozessinstanz: `ProcessInstanceManager` und `ProcessInstance::withEvent()`
  aktualisieren `currentStepKey` nur bei `after`. Das passt zum Ziel, dass
  `before` keine eigene fachliche Step-Position erzeugt.
- Timeline: `DocumentTimelineEventRow` enthaelt `eventPhase`; der Doctrine
  Timeline-Provider liefert Events inklusive Phase.
- Template-Vorschlag und Heatmap: Suggestion, Heatmap und Live-Diagramm filtern
  `before` standardmaessig aus und haben teils eine explizite
  `--include-before`-Option.
- Template-Check: `ProcessTemplateCheckService::actualStepEntries()` filtert
  aktuell nicht explizit auf `after`; es aggregiert nur gleiche unmittelbar
  aufeinanderfolgende `stepKey`. Fuer Visibility-Checks sollte die fachliche
  Ist-Schrittfolge explizit aus `after`-Events bzw. aus aggregierten Step-Gruppen
  gebildet werden.
- Source System: Events und `DocumentRef` enthalten bereits `sourceSystem`.
  Im Event-Intake ist der Default `amagno`. Bestehende Templates muessen das
  nicht explizit setzen; neue Access-Pruefungen sollten diesen Default aber
  bewusst modellieren und ueberschreibbar halten.
- Amagno Magnet Documents API: `DocumentFetcher::fetchDocuments()` nutzt bereits
  `GET /magnets/{magnetId}/documents`, ist aber im `AmagnoDocumentGateway` noch
  nicht exponiert.
- Diagramme/Reports: `ProcessTemplateGraphFactory`,
  `MermaidProcessGraphRenderer`, BPMN-View und Dokument-Timeline sind die
  naheliegenden Integrationspunkte fuer Anzeige, nicht fuer die Prueflogik.

## Architekturprinzip

APRIL sollte Access-Control nicht als Amagno-ACL interpretieren, sondern als
prozessbezogene Controls:

1. YAML-Template definiert fachliche Erwartung.
2. Access-Probe-Port liefert technische Beobachtungen: Dokument ist im
   technischen Kontrollpunkt sichtbar, nicht sichtbar, unbekannt oder nicht
   pruefbar.
3. Ein VisibilityCheckService bewertet Beobachtungen gegen das Template.
4. Ergebnisse werden append-only bzw. historisiert gespeichert.
5. Timeline, Dokumentcheck und Dokumentation lesen gespeicherte Ergebnisse.

Der fachliche Kern bleibt DMS-unabhaengig. Amagno bleibt Adapter.

## Template-Erweiterung

Vorgeschlagene Top-Level-Keys:

```yaml
source_system: amagno

access_probes:
  approval_location_a_today:
    source_system: amagno
    type: amagno_magnet_documents
    magnet_id: 1001
    max_documents: 500
    description: "Heute bearbeitete Dokumente in Freigabe Standort A"

  approval_location_b_today:
    source_system: amagno
    type: amagno_magnet_documents
    magnet_id: 1002
    max_documents: 500
    description: "Heute bearbeitete Dokumente in Freigabe Standort B"

  external_today:
    source_system: amagno
    type: amagno_magnet_documents
    magnet_id: 1009
    max_documents: 200
    description: "Heute bearbeitete Dokumente sichtbar fuer Externe"

visibility_check_profiles:
  approval_location_a:
    expected_visible_in_probes:
      - approval_location_a_today
    expected_not_visible_in_probes:
      - approval_location_b_today
      - external_today

visibility_profile_resolvers:
  approval_location_by_context:
    field: standort
    map:
      A: approval_location_a
      B: approval_location_b

visibility_retry_policies:
  amagno_today_magnets:
    attempts_after_seconds: [10, 30, 60, 120]
    forbidden_found: violation
    expected_missing_after_last_attempt: warning
    probe_too_large: technical_warning

manual_access_tests:
  - key: approver_scope_test
    title: "Freigeberbezogene Sichtbarkeit"
    description: "Freigeber duerfen nur Dokumente sehen, bei denen sie als Freigeber eingetragen sind."
    test_procedure:
      - "Testdokument mit Benutzer A als Freigeber anlegen."
      - "Mit Benutzer A anmelden und pruefen, ob Dokument sichtbar ist."
      - "Mit Benutzer B anmelden und pruefen, ob Dokument nicht sichtbar ist."
    expected_result:
      - "Benutzer A sieht das Dokument."
      - "Benutzer B sieht das Dokument nicht."
    frequency: "bei Berechtigungsaenderungen und quartalsweise"
```

Step-nahe Checks:

```yaml
steps:
  - key: "01 Rechnungseingang"
    after:
      visibility_checks:
        - key: route_to_location_approval
          expected_profile_resolver: approval_location_by_context
          retry_policy: amagno_today_magnets
          source_system: amagno

    before:
      visibility_checks:
        - key: initial_visibility
          expected_profile: incoming_invoice_scope
```

`before` und `after` sind Kontrollphasen am gleichen fachlichen `stepKey`, keine
eigenen Steps. Die bestehenden `steps[*].key` bleiben die einzige Quelle fuer
fachliche Prozessknoten.

Empfehlung: `source_system` sollte global am Template stehen duerfen, aber
optional bleiben. Wenn der Key fehlt, gilt aus Kompatibilitaetsgruenden
`amagno`. Einzelne `access_probes` und bei Bedarf einzelne Checks duerfen
`source_system` ueberschreiben. Damit kann ein Template spaeter gemischte
Quellen abbilden, ohne bestehende Amagno-Templates anzupassen.

Aufloesungsreihenfolge:

1. `visibility_checks[*].source_system`
2. `access_probes[*].source_system`
3. `template.source_system`
4. Default `amagno`

`access_probes` ist bewusst neutraler als `access_magnets`. Eine Access-Probe beschreibt
einen technischen Kontrollpunkt. Fuer Amagno ist der erste konkrete Typ
`amagno_magnet_documents`; dieser nutzt `GET /magnets/{magnetId}/documents`.
Andere `source_system` koennen spaeter eigene Probe-Typen bekommen.

## Neue Domain-Objekte

Vorgeschlagene Objekte im Domain-Layer:

- `ProcessTemplateAccessProbe`
  - `key`, `sourceSystem`, `type`, `options`, `maxDocuments`, `description`
- `ProcessTemplateVisibilityProfile`
  - `key`, `expectedVisibleInProbes`, `expectedNotVisibleInProbes`
- `ProcessTemplateVisibilityProfileResolver`
  - `key`, `field`, `map`
- `ProcessTemplateVisibilityRetryPolicy`
  - `key`, `attemptsAfterSeconds`, `forbiddenFound`, `expectedMissingAfterLastAttempt`, `probeTooLarge`
- `ProcessTemplateVisibilityCheck`
  - `key`, `phase`, `expectedProfile`, `expectedProfileResolver`, `retryPolicy`
- `ProcessTemplateManualAccessTest`
  - `key`, `title`, `description`, `testProcedure`, `expectedResult`, `frequency`
- `VisibilityCheckResult`
  - technische und fachliche Bewertung eines einzelnen Probe-Checks.

`ProcessTemplateStep` sollte optional `beforeChecks` und `afterChecks` erhalten.
Alternativ koennen Checks top-level unter `visibility_checks` mit `step` und
`phase` modelliert werden. Step-nahe Checks sind fuer spaetere Dokumentation
lesbarer.

## Ports und Adapter

Neuer fachlicher Port:

```php
interface AccessProbeProvider
{
    public function evaluate(
        ProcessTemplateAccessProbe $probe,
        string $documentUuid,
        ?string $connectionName = null
    ): AccessProbeResult;
}
```

Der Port gibt keine Amagno-Rohdaten zurueck, sondern ein neutrales Ergebnis:

- `visible`
- `hidden`
- `unknown`
- `skipped`
- `documentCount`
- `details`

Amagno-Adapter:

- `AmagnoMagnetDocumentsAccessProbeProvider`
- unterstuetzt `sourceSystem=amagno` und `type=amagno_magnet_documents`
- nutzt `DocumentFetcher::fetchDocuments()` mit `magnet_id`
- `AmagnoDocumentGateway` sollte dafuer um `fetchDocuments()` erweitert werden
- Dokumenterkennung sollte `documentUuid` bevorzugen und bei Bedarf robuste
  Aliasfelder aus der Amagno-Antwort unterstuetzen

Damit bleibt die Bewertungslogik frei von Amagno-Details.

Eine `AccessProbeProviderRegistry` sollte Provider nach `sourceSystem` und
`type` auswaehlen. Wenn kein Provider fuer die Kombination existiert, wird der
Check nicht als technische Abweichung bewertet, sondern als `skipped` oder
`unknown` mit Reason `unsupported_probe_type`. Die fachliche Kontrolle kann
dann ueber `manual_access_tests` dokumentiert werden.

## Services

Vorgeschlagene Services:

- `VisibilityProfileResolver`
  - bestimmt aus Check-Konfiguration und Context Snapshot das Profil.
- `VisibilityCheckPlanner`
  - erzeugt aus Event, Template, Step und Phase konkrete Probe-Pruefaufgaben.
- `VisibilityCheckService`
  - prueft eine Aufgabe gegen den `AccessProbeProvider` und bewertet
    Status.
- `VisibilityCheckScheduler`
  - plant initiale und wiederholte Pruefungen.
- `VisibilityCheckResultStore`
  - persistiert einzelne Versuchsergebnisse und finale Bewertung.
- `VisibilityCheckReportProvider`
  - liefert Ergebnisse fuer Timeline, Dokumentcheck und Audit.

Die Pruefung sollte nicht in `ProcessTemplateCheckService` selbst eingebaut
werden. Der Template-Check kann spaeter gespeicherte Visibility-Ergebnisse
einbeziehen, aber die I/O-lastige Probe-Pruefung gehoert in einen separaten
Application-Service.

## Synchron oder asynchron

Empfehlung: asynchron/queued.

Begruendung:

- Technische Probes, insbesondere Amagno-Magnete, koennen nach Stempelereignissen
  verzoegert aktualisiert werden.
- Mehrere Probes pro Event erzeugen mehrere API-Aufrufe.
- `max_documents` schuetzt vor zu grossen Listen, verhindert aber keine
  Netzwerk-Timeouts.
- Retries mit 10/30/60/120 Sekunden passen natuerlich zu Queue/Scheduler.

Technisch:

1. Nach Verarbeitung eines Events erzeugt APRIL geplante
   `VisibilityCheckAttempt`-Jobs fuer passende `stepKey`/`eventPhase` und
   `sourceSystem`.
2. Versuch 1 wird sofort oder nach dem ersten Delay ausgefuehrt.
3. Bei `expected missing` wird bis zum letzten Attempt erneut geplant.
4. Bei `forbidden found` kann sofort final `violation` gespeichert werden.
5. Bei `probe too large`, API-Fehlern oder nicht bewertbaren Antworten wird
   gemaess Policy `technical_warning`, `unknown` oder Retry gespeichert.

Timeouts:

- HTTP-Client-Timeout im Amagno-Adapter begrenzen.
- Pro Job genau eine Access-Probe pruefen.
- Keine grossen Probe-Ergebnislisten im Request/Response von Commands halten.
- Jobs idempotent machen ueber `(processEventId, checkKey, profileKey, probeKey, attemptNo)`.

## Bewertung

Statusprioritaet:

1. `violation`
2. `technical_warning`
3. `unknown`
4. `warning`
5. `skipped`
6. `ok`

Regeln:

- Erwartet sichtbar + Dokument sichtbar: `ok`
- Erwartet sichtbar + nach letztem Attempt nicht sichtbar:
  `warning` / `missing_expected_visibility`
- Erwartet verborgen + Dokument sichtbar: `violation`
- Erwartet verborgen + Dokument nicht sichtbar: `ok`
- Probe-Ergebnis groesser als `max_documents`: `technical_warning` oder `skipped`
- API nicht erreichbar: `unknown`, optional Retry
- Probe-Type fuer `sourceSystem` nicht unterstuetzt: `skipped` mit Hinweis auf
  manuelle Kontrolle

Ein Treffer in einer verbotenen Access-Probe ist staerker zu bewerten als ein
fehlender Treffer in einer erwarteten Access-Probe. Erwartete Sichtbarkeit kann
durch verzoegerte Probe-Aktualisierung oder Filter False Negatives erzeugen; verbotene
Sichtbarkeit ist fachlich kritischer.

## Persistenz

`VisibilityCheckResult` sollte ein eigener Result-Typ neben `ContextSnapshot`
sein, nicht Teil des Context Snapshots.

Gruende:

- ContextSnapshot beschreibt fachlichen Dokumentzustand zum Eventzeitpunkt.
- Visibility-Ergebnisse entstehen oft zeitverzoegert und mit mehreren Attempts.
- Pro Event/Step koennen mehrere Probes und Profile bewertet werden.
- Ergebnisse haben eigene technische Metadaten wie `documentCount`,
  `attemptNo`, `rawResult`.

Vorgeschlagene Tabelle `intelligence_visibility_check_result`:

- `id`
- `process_event_id`
- `process_instance_id`
- `external_event_key`
- `document_uuid`
- `document_version`
- `process_key`
- `source_system`
- `step_key`
- `event_phase`
- `check_key`
- `profile_key`
- `probe_key`
- `probe_type`
- `probe_ref`
- `expected` (`visible`, `hidden`)
- `actual` (`visible`, `hidden`, `unknown`, `skipped`)
- `status` (`ok`, `violation`, `warning`, `technical_warning`, `unknown`, `skipped`)
- `checked_at`
- `attempt_no`
- `is_final`
- `document_count`
- `raw_result_json`
- `details_json`
- `created_at`

Sinnvolle Indizes:

- `(document_uuid, process_key, document_version)`
- `(external_event_key, check_key)`
- `(process_key, source_system, step_key, event_phase)`
- `(status, checked_at)`
- Unique fuer Attempt-Idempotenz:
  `(external_event_key, check_key, profile_key, probe_key, attempt_no)`

Finale Aggregation kann entweder berechnet werden oder als `is_final`
markiert werden. Fuer den MVP reicht persistiertes Attempt-Ergebnis plus
ein finales Ergebnis pro Probe.

## Timeline und Reporting

Timeline:

- Bestehende Event-Timeline bleibt eventbasiert.
- Eine neue Projektion sollte fachliche Steps aggregieren:
  `documentUuid + processKey + documentVersion + stepKey`.
- Innerhalb einer Step-Gruppe werden `before`-Event, `after`-Event,
  ContextSnapshot und VisibilityCheckResults angezeigt.
- `before`/`after` werden als Kontrollphasen gerendert, nicht als Steps.

Beispiel:

```text
01 Rechnungseingang
  before: Eingangssichtbarkeit geprueft
  event: Rechnungseingang
  after: Standortfreigabe geprueft
    approval_location_a_today: OK
    approval_location_b_today: VIOLATION
    external_today: OK
```

Dokumentcheck:

- `ProcessTemplateCheckResult` kann spaeter um `accessControlResults` erweitert
  werden.
- Alternativ liefert ein eigener `AccessCheckDocumentResult` die Bewertung und
  wird im Command zusammen ausgegeben.
- Fachliche Schrittfolge sollte fuer Soll/Ist-Abgleich nur `after` bzw.
  aggregierte Steps verwenden.

Diagramm/Mermaid/BPMN:

- Access Checks nicht als neue Prozessknoten rendern.
- Sinnvoll sind Badges/Annotationen an Task-Nodes:
  - `access: ok`
  - `access: warning`
  - `access: violation`
- In Mermaid koennen zunaechst Kommentare oder Node-Labels erweitert werden.
- In BPMN/SVG spaeter Boundary-/Annotation-Elemente am Step darstellen.

## Manuelle Kontrollpunkte

`manual_access_tests` bleiben Template-Metadaten und werden zunaechst nicht
automatisiert ausgefuehrt.

Spaetere Nutzung:

- `intelligence:template:document` erzeugt Prozess- und Berechtigungsdoku aus
  YAML.
- `intelligence:template:access-coverage` bewertet Abdeckung:
  - automatisch geprueft: Step/Phase hat ausfuehrbaren Visibility-Check
  - halbautomatisch geprueft: Check vorhanden, aber mit manueller Bestaetigung
    oder externer Evidenz
  - manuell dokumentiert: `manual_access_tests`
  - nicht abgedeckt: keine Controls fuer relevante Berechtigungsaussage
- Spaeter kann ein `manual_access_test_result` mit Pruefer, Datum, Status und
  Evidenz-Verweis ergaenzt werden.

## Commands

MVP-nahe Commands:

- `intelligence:access:check-document <documentUuid> <processKey>`
  - plant oder fuehrt Visibility-Checks fuer ein Dokument aus.
- `intelligence:access:audit <processKey>`
  - aggregiert gespeicherte Ergebnisse nach Status, Check, Probe und Zeitraum.
- `intelligence:template:access-coverage <processKey>`
  - zeigt automatische/manuelle Access-Control-Abdeckung aus dem Template.
- `intelligence:template:document <processKey>`
  - erzeugt spaeter human-readable Prozess- und Berechtigungsdokumentation.

Bestehende Commands:

- `intelligence:document:timeline` sollte gespeicherte Access-Ergebnisse optional
  anzeigen koennen, z. B. `--with-access`.
- `intelligence:template:check-document` kann eine Option `--with-access`
  erhalten, sobald Result-Store und Report-Provider existieren.

## Tests

Unit-Tests:

- Template-Parsing fuer `source_system`, `access_probes`, Profile, Resolver, Policies,
  Step-Checks und manuelle Tests.
- `VisibilityProfileResolver` fuer Context-Mapping und fehlende Context-Felder.
- `VisibilityCheckService` fuer Statusbewertung und Prioritaet.
- Step-Aggregation: `before`/`after` mit gleichem `stepKey` erzeugen keine
  doppelten fachlichen Steps.

Application-/Functional-Tests:

- Fake `AccessProbeProvider` ohne Amagno.
- Retry-Planung mit stabilen Attempt-Zeitpunkten.
- Idempotenz pro Attempt.
- Dokumentcheck mit gespeicherten Access-Ergebnissen.
- Timeline-Ausgabe mit `before`/`after`-Kontrollphasen.

Adapter-Tests:

- `AmagnoMagnetDocumentsAccessProbeProvider` nutzt `fetchDocuments()`.
- Provider-Registry waehlt nach `sourceSystem` und `type`.
- `max_documents` fuehrt zu `technical_warning`/`skipped`.
- API-Fehler fuehren zu `unknown` ohne Prozessabbruch.

## MVP-Vorschlag

Kleinster sinnvoller erster Schritt:

1. Template-Struktur und Domain-Objekte fuer Access-Metadaten einlesen,
   inklusive optionalem `source_system`-Default und Override-Regel.
2. Manuelle Access-Tests und Visibility-Checks in `ProcessTemplate` abbilden.
3. `intelligence:template:access-coverage` als rein statische Auswertung bauen.
4. Fachliche Step-Aggregation klaeren und in Tests absichern:
   `before`/`after` duerfen keine eigenen Soll/Ist-Schritte erzeugen.

Noch nicht automatisiert im ersten Schritt:

- Keine automatischen Probe-Abfragen gegen Amagno.
- Keine Queue/Retry-Ausfuehrung.
- Keine neue Persistenztabelle.
- Keine Diagramm-Metriken aus Access-Ergebnissen.

Zweiter Schritt:

1. `AccessProbeProvider` Port, Provider-Registry und Fake-Implementierung.
2. `VisibilityCheckService` fuer einen synchronen Einzelcheck mit Fake-Provider.
3. Command `intelligence:access:check-document` im Dry-Run/Synchronmodus.
4. Bewertung und Ausgabe ohne Persistenz oder mit InMemory-Store in Tests.

Dritter Schritt:

1. Doctrine-Entity und Migration fuer `VisibilityCheckResult`.
2. Amagno-Adapter fuer `type=amagno_magnet_documents` ueber
   `DocumentFetcher::fetchDocuments()`.
3. Async Scheduling und Retry-Policy.
4. Timeline-/Document-Check-Integration mit gespeicherten Ergebnissen.

Bewusst akzeptierte Grenzen im MVP:

- APRIL prueft nur definierte Access-Probes, keine vollstaendigen ACLs.
- Amagno-Magnetlisten muessen durch Amagno-Filter klein gehalten werden.
- Erwartete Sichtbarkeit kann wegen Probe-Latenz als Warning statt harter
  Abweichung bewertet werden.
- Manuelle Tests werden zunaechst nur dokumentiert und in Coverage erfasst.
- Automatische Ergebnisse beweisen Sichtbarkeit in den technischen Probes, nicht
  alle moeglichen Benutzer- und Rollenberechtigungen.

## Offene Entscheidungen vor Implementierung

- Soll das Template Step-nahe Checks (`steps[*].before/after`) oder eine
  top-level Liste `visibility_checks` als Primaerstruktur verwenden?
- Soll der Template-Key `source_system` oder analog zum Event-Payload
  `sourceSystem` heissen? Empfehlung fuer YAML ist `source_system`; die Factory
  kann beide Aliasnamen akzeptieren.
- Soll `expected_missing_after_last_attempt` als eigener Status
  `missing_expected_visibility` gespeichert oder als `warning` mit Reason
  modelliert werden?
- Welche Amagno-Dokumentlistenfelder enthalten in allen Zielsystemen sicher die
  `documentUuid`?
- Soll ein `forbidden_found` sofort alle weiteren Attempts fuer den Check
  beenden?
- Soll die erste automatische Pruefung direkt nach Eventverarbeitung oder erst
  nach dem ersten Delay ausgefuehrt werden?
