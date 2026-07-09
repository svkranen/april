# Intelligence Event API

Dieses Dokument beschreibt die Parameter fuer den HTTP-Intake von Prozessereignissen.

## Endpoint

```text
POST /api/intelligence/events
```

Der Endpoint nimmt Events entgegen und schreibt sie zuerst in die asynchrone
Incoming-Event-Queue. Die eigentliche Verarbeitung in `ProcessEvent`,
`ProcessInstance` und `ContextSnapshot` erfolgt anschliessend ueber den
Incoming-Event-Processor.

Unterstuetzte Content Types:

- `application/json`
- `application/x-www-form-urlencoded`

Query-Parameter werden mit dem Body zusammengefuehrt. Bei gleichen Feldnamen
gewinnt der JSON-Body bzw. das Form-Feld gegen den Query-Parameter.

## Kurzantwort: Welche Felder muss ein Event liefern?

Ein sendendes System muss nicht den kompletten Item-Kontext senden. Fuer die
Prozessanalyse braucht der Intake vor allem ein stabiles Ereignis mit
Item-Bezug:

| Feld | Pflicht im Betrieb | Zweck |
| --- | --- | --- |
| `processKey` | Ja | Ordnet das Event dem fachlichen Prozess und Template zu, z. B. `incident-management`. |
| `stepKey` | Ja, empfohlen | Der fachliche Schritt, der in Timeline, Template-Check und Heatmap erscheint. |
| `eventKey` | Empfohlen | Ereignisart, z. B. `stamp.applied` oder identisch zu `stepKey`. |
| `eventPhase` | Empfohlen | `after` fuer "Schritt wurde erreicht/ausgefuehrt"; `before` fuer Sichtbarkeits-/Vorher-Pruefungen. |
| `documentUuid` | Empfohlen | Stabile Item-UUID fuer Timeline und Kontextaufladung. |
| `documentId` | Empfohlen | Lesbare/externe Item-ID aus dem Quellsystem. |
| `documentVersion` | Empfohlen | Dokumentversion. Fehlt der Wert, wird `1` verwendet. Eine neue Version erzeugt eine eigene Prozessinstanz. |
| `occurredAt` | Empfohlen | Fachlicher Ereigniszeitpunkt. Fehlt der Wert, nutzt die Verarbeitung den aktuellen Zeitpunkt. |
| `externalEventKey` | Stark empfohlen | Idempotenz-Schluessel. Muss fuer dasselbe Ereignis stabil gleich bleiben. |
| Secret/API-Key | Ja, wenn `INTELLIGENCE_EVENT_SECRET` gesetzt ist | Authentifiziert die Stempelaktion gegen den Intake. |

Fachliche Merkmale koennen entweder inline unter `attributes` bzw. `context`
gesendet oder spaeter ueber einen optionalen Connector geladen werden. Der
Community-Demo-Pfad nutzt inline Context und speichert daraus unveraenderliche
`ContextSnapshot`s.

`attributes` ist fuer Zusatzdaten gedacht, die zum Ereignis gehoeren oder im
Community-Pfad ohne Connector ausgewertet werden sollen.

## Authentifizierung / Signatur

Das Secret bzw. die Signatur muss ueber einen dieser Header gesendet werden:

- `X-Intelligence-Secret`
- `X-Intelligence-Signature`
- `Signature`

Wenn `INTELLIGENCE_EVENT_SECRET` gesetzt ist, akzeptiert der lokale Verifier
entweder den identischen Secret-Wert oder eine HMAC-SHA256-Signatur ueber den
rohen Request-Body. Fuer HMAC wird der reine Hex-Digest oder das Format
`sha256=<digest>` akzeptiert.

Ist kein Secret konfiguriert, lehnt der lokale Verifier Events ab
(`accepted: false`, `error: invalid_signature`). Fuer lokale Entwicklung kann
unsignierter Intake nur explizit aktiviert werden:

```dotenv
APP_ENV=dev
INTELLIGENCE_EVENT_SECRET=
INTELLIGENCE_EVENT_ALLOW_UNSIGNED_DEV=true
```

Dieser Opt-in wirkt nur in `dev` und `test`, nicht in `prod`.

## Empfohlenes Payload

```json
{
  "externalEventKey": "demo:incident:10000000-0000-4000-8000-000000000001:classify",
  "sourceSystem": "community-demo",
  "processKey": "incident-management",
  "eventKey": "incident.classified",
  "stepKey": "classify_incident",
  "eventPhase": "after",
  "documentId": "INC-1001",
  "documentUuid": "10000000-0000-4000-8000-000000000001",
  "documentVersion": 1,
  "actorRef": "user-42",
  "occurredAt": "2026-05-29T10:00:00+00:00",
  "attributes": {
    "category": "security",
    "severity": "high",
    "data_exposure": true,
    "system_type": "internal"
  }
}
```

## Beispiel als URL/Form-Parameter

Wenn ein System einen HTTP-POST mit `application/x-www-form-urlencoded`
ausloest, sollte die Aktion mindestens die folgenden Parameter uebergeben:

```text
POST https://example.test/api/intelligence/events
Content-Type: application/x-www-form-urlencoded

processKey=incident-management
stepKey=classify_incident
eventKey=incident.classified
eventPhase=after
sourceSystem=community-demo
documentId=<item-id>
documentUuid=<item-uuid>
documentVersion=1
occurredAt=<event-time-ISO-8601>
externalEventKey=<source>:<item-uuid>:<documentVersion>:<stepKey>:<occurredAt>
```

Pragmatische Vorgabe fuer sendende Systeme:

- `processKey` ist meist fest je Ereignisquelle, z. B. `incident-management`.
- `stepKey` ist der fachliche Schritt dieses Ereignisses, exakt wie im Template
  oder bewusst normalisiert.
- `eventKey` darf generisch sein, z. B. `incident.updated`; fuer die Analyse ist
  `stepKey` wichtiger.
- `eventPhase=after` verwenden, wenn das Ereignis den Schritt markiert.
- `documentUuid` ist wichtiger als `documentId`, weil Context und Timelines
  darueber stabil geladen werden.
- `documentVersion` mitsenden, sobald das Quellsystem Versionen kennt.
- `externalEventKey` aus stabilen Bestandteilen bauen. Nicht bei jedem Retry
  neu zufaellig erzeugen.
- Den Secret per Header `X-Intelligence-Secret` senden.
- Query- oder Form-Parameter wie `xIntelligenceSecret` oder `apiKey` werden aus
  Sicherheitsgruenden nicht als Authentifizierung akzeptiert.

Bei Form-Requests muessen Sonderzeichen URL-encoded werden. Das betrifft vor
allem Leerzeichen in `stepKey` und Plus-Zeichen in Zeitzonen, z. B.
`2026-05-29T10%3A00%3A00%2B02%3A00`.

## Parameter

| Parameter | Aliasnamen | Pflicht | Bedeutung |
| --- | --- | --- | --- |
| `processKey` | `process_key` | Ja | Fachlicher Prozessschluessel, z. B. `incident-management`. Der Wert `unknown` wird abgelehnt. |
| `eventKey` | `event_key`, `event_type`, `eventType`, `type` | Empfohlen | Fachlicher Ereignistyp. Wenn kein separater `stepKey` gesetzt ist, wird er als Schritt verwendet. |
| `stepKey` | `step_key` | Empfohlen | Fachlicher Prozessschritt, der in Timeline und Template-Pruefung ausgewertet wird. Default ist `eventKey` oder `unknown`. |
| `eventPhase` | `event_phase`, `phase` | Optional | `before`, `after` oder `unknown`. Default ist `after`; unbekannte Werte werden zu `unknown`. Nur `after`-Events aktualisieren den aktuellen Schritt einer Prozessinstanz. |
| `externalEventKey` | `external_event_key`, `event_id`, `eventId`, `id` | Empfohlen | Idempotenz-Schluessel. Doppelte verarbeitete Events werden ueber diesen Wert erkannt. Fehlt der Wert, generiert die Verarbeitung einen Hash aus Dokument, Version, Prozess, Event, Schritt und Zeitpunkt. |
| `sourceSystem` | `source_system`, `source` | Optional | Quellsystem. Default ist `community-demo`. |
| `connectorType` | `connector_type` | Optional | Connector-Typ fuer die Incoming-Queue. Default ist das Quellsystem. |
| `connectionName` | `connection_name`, `connection` | Optional | Name der Connector-Verbindung, falls mehrere Verbindungen unterschieden werden muessen. |
| `documentId` | `document_id`, `documentExternalId`, `document_external_id`, `externalId` | Empfohlen | Externe Dokument-ID aus dem Quellsystem. |
| `documentUuid` | `document_uuid`, `externalUuid`, `uuid` | Empfohlen | Stabile Dokument-UUID. Sie wird fuer Timeline-Auswertungen und Kontextaufladung genutzt. |
| `documentVersion` | `document_version`, `version` | Optional | Dokumentversion. Default ist `1`. Eine neue Version erzeugt eine eigene Prozessinstanz. |
| `actorRef` | `actor_ref`, `actor`, `user` | Optional | Benutzer-, Rollen- oder Systemreferenz des Ausloesers. |
| `occurredAt` | `occurred_at`, `occured_at`, `occuredAt`, `timestamp`, `changeDate` | Empfohlen | Ereigniszeitpunkt. ISO-8601 mit Offset ist bevorzugt. Offsetlose Zeiten werden als Berliner Lokalzeit interpretiert und intern nach UTC normalisiert. |
| `attributes` | - | Optional | Freie fachliche Zusatzdaten als Objekt. |

## Verschachteltes Dokumentobjekt

JSON-Sender koennen Dokumentfelder alternativ unter `document` senden. Die
Normalizer lesen diese Werte ebenfalls:

```json
{
  "processKey": "incident-management",
  "stepKey": "classify_incident",
  "eventKey": "incident.classified",
  "document": {
    "id": "4711",
    "uuid": "6f4a2c1e-9d2b-4d74-9d1e-0f18d6b1a234",
    "version": 2
  }
}
```

Fuer einfache Webhook-Sender ist die flache Form meist einfacher.

## Kontextfelder und Template-Konfiguration

Welche Merkmale fuer Analyse, Decision Rules und SLA-/Konformitaetspruefung
benoetigt werden, steht nicht in der Stempelaktion, sondern im Prozess-Template:

```yaml
context_profile:
  required:
    - category
    - severity
    - data_exposure
    - system_type
    - documentVersion

field_mapping:
  category:
    source: event_context
    value_type: string
    stability: snapshot_required
```

Unterstuetzte eingebaute Kontextfelder des neutralen Event-Context-Pfads:

| Kontextfeld | Herkunft |
| --- | --- |
| `documentVersion` | Version aus dem Event bzw. `DocumentRef`. |
| `documentId` | Externe Dokument-ID aus dem Event. |
| `documentUuid` | Dokument-UUID aus dem Event. |
| `approvals` | Platzhalter fuer Freigaben, aktuell leer sofern nicht spezifisch angebunden. |
| `signatures` | Platzhalter fuer Signaturen, aktuell leer sofern nicht spezifisch angebunden. |

Alle anderen Felder werden ueber `field_mapping` abgebildet. Im Community-Pfad
nutzt `source: event_context` die inline gesendeten Attribute. Optionale
Connectoren koennen weitere Quellen bereitstellen.

Typische fachliche Felder:

- `category`: fachliche Kategorie, z. B. Security oder Business Process.
- `severity`: Prioritaet oder Kritikalitaet.
- `system_type`: Systemklasse, z. B. SaaS oder intern.
- `data_exposure`: boolescher Hinweis auf potenziellen Datenabfluss.
- `approvals` / `signatures`: Freigabe- und Signaturinformationen, sobald der konkrete Adapter sie liefert.

Der `ContextSnapshot` wird zum Zeitpunkt der Eventverarbeitung gespeichert und
spaeter nicht nachtraeglich veraendert. Wenn ein Feld fuer eine Decision Rule
benoetigt wird, muss es in `context_profile.required` stehen und technisch ueber
`field_mapping` aufloesbar sein.

## Minimalbeispiel als URL/Form-Parameter

```bash
curl -X POST 'https://example.test/api/intelligence/events' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -H 'X-Intelligence-Secret: <secret>' \
  --data-urlencode 'processKey=incident-management' \
  --data-urlencode 'eventKey=incident.received' \
  --data-urlencode 'stepKey=incident_received' \
  --data-urlencode 'eventPhase=after' \
  --data-urlencode 'sourceSystem=community-demo' \
  --data-urlencode 'documentId=INC-1001' \
  --data-urlencode 'documentUuid=10000000-0000-4000-8000-000000000001' \
  --data-urlencode 'documentVersion=1' \
  --data-urlencode 'occurredAt=2026-05-29T10:00:00+00:00'
```

Bei Form-Requests muessen Plus-Zeichen in Zeitzonen sauber URL-encoded werden
(`%2B`), z. B. `2026-05-29T10%3A00%3A00%2B02%3A00`. `--data-urlencode`
uebernimmt das automatisch.

## Antworten

Erfolgreich angenommener Request:

```json
{
  "status": "accepted",
  "accepted": true,
  "duplicate": false,
  "incoming_event_id": 123,
  "external_event_key": "amagno:workflow:12345:approved:2026-05-29T10:00:00Z"
}
```

Der Endpoint antwortet fuer den Ingest-POST immer mit HTTP 200. Fehler werden
im JSON-Body ueber `accepted: false` und `error` transportiert:

- `invalid_signature`: Signatur/API-Key wurde vom Verifier abgelehnt.
- `invalid_json`: JSON-Body ist syntaktisch ungueltig.
- `invalid_payload`: JSON-Body ist kein Objekt.
- `unknown_process_key`: `processKey` fehlt, ist leer oder ist `unknown`.

## Idempotenz und Prozessinstanzen

Die HTTP-Annahme speichert jeden gueltigen Request als Incoming Event. Die
Idempotenz wird bei der Verarbeitung in den append-only Event Store
durchgesetzt. Massgeblich ist `externalEventKey`.

Fuer produktive Sender sollte `externalEventKey` daher stabil und eindeutig
sein, etwa aus Quellsystem, Workflow-Event-ID, Dokument-UUID, Version,
Schritt und Ereigniszeitpunkt.

Eine neue `documentVersion` erzeugt eine eigene Prozessinstanz. Historische
Instanzen bleiben erhalten.
