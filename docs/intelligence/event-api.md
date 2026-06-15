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

## Authentifizierung / Signatur

Die Signatur kann ueber einen dieser Header gesendet werden:

- `X-Intelligence-Signature`
- `X-Amagno-Signature`
- `Signature`

Alternativ kann ein API-Key als Parameter gesendet werden:

- `apiKey`
- `api_key`

Wenn `INTELLIGENCE_EVENT_SECRET` gesetzt ist, wird die Signatur als
HMAC-SHA256 ueber den rohen Request-Body geprueft. Akzeptiert wird der reine
Hex-Digest oder das Format `sha256=<digest>`.

Ist kein Secret konfiguriert, akzeptiert der lokale Verifier alle Requests.

## Empfohlenes Payload

```json
{
  "externalEventKey": "amagno:workflow:12345:approved:2026-05-29T10:00:00Z",
  "sourceSystem": "amagno",
  "processKey": "invoice",
  "eventKey": "invoice.approved",
  "stepKey": "approved",
  "eventPhase": "after",
  "documentId": "4711",
  "documentUuid": "6f4a2c1e-9d2b-4d74-9d1e-0f18d6b1a234",
  "documentVersion": 1,
  "actorRef": "user-42",
  "occurredAt": "2026-05-29T10:00:00+00:00",
  "attributes": {
    "amount_net": 1200.5,
    "cost_center": "KST-100"
  }
}
```

## Parameter

| Parameter | Aliasnamen | Pflicht | Bedeutung |
| --- | --- | --- | --- |
| `processKey` | `process_key` | Ja | Fachlicher Prozessschluessel, z. B. `invoice` oder `ai-rechnungen`. Der Wert `unknown` wird abgelehnt. |
| `eventKey` | `event_key`, `event_type`, `eventType`, `type` | Empfohlen | Fachlicher Ereignistyp. Wenn kein separater `stepKey` gesetzt ist, wird er als Schritt verwendet. |
| `stepKey` | `step_key` | Empfohlen | Fachlicher Prozessschritt, der in Timeline und Template-Pruefung ausgewertet wird. Default ist `eventKey` oder `unknown`. |
| `eventPhase` | `event_phase`, `phase` | Optional | `before`, `after` oder `unknown`. Default ist `after`; unbekannte Werte werden zu `unknown`. Nur `after`-Events aktualisieren den aktuellen Schritt einer Prozessinstanz. |
| `externalEventKey` | `external_event_key`, `event_id`, `eventId`, `id` | Empfohlen | Idempotenz-Schluessel. Doppelte verarbeitete Events werden ueber diesen Wert erkannt. Fehlt der Wert, generiert die Verarbeitung einen Hash aus Dokument, Version, Prozess, Event, Schritt und Zeitpunkt. |
| `sourceSystem` | `source_system`, `source` | Optional | Quellsystem. Default ist `amagno`. |
| `connectorType` | `connector_type` | Optional | Connector-Typ fuer die Incoming-Queue. Default ist das Quellsystem bzw. `amagno`. |
| `connectionName` | `connection_name`, `connection` | Optional | Name der Amagno-/Connector-Verbindung, falls mehrere Verbindungen unterschieden werden muessen. |
| `documentId` | `document_id`, `documentExternalId`, `document_external_id`, `externalId` | Empfohlen | Externe Dokument-ID aus dem Quellsystem. |
| `documentUuid` | `document_uuid`, `externalUuid`, `uuid` | Empfohlen | Stabile Dokument-UUID. Sie wird fuer Timeline-Auswertungen und Kontextaufladung genutzt. |
| `documentVersion` | `document_version`, `version` | Optional | Dokumentversion. Default ist `1`. Eine neue Version erzeugt eine eigene Prozessinstanz. |
| `actorRef` | `actor_ref`, `actor`, `user` | Optional | Benutzer-, Rollen- oder Systemreferenz des Ausloesers. |
| `occurredAt` | `occurred_at`, `occured_at`, `occuredAt`, `timestamp`, `changeDate` | Empfohlen | Ereigniszeitpunkt. ISO-8601 mit Offset ist bevorzugt. Offsetlose Amagno-Zeiten werden als Berliner Lokalzeit interpretiert und intern nach UTC normalisiert. |
| `attributes` | - | Optional | Freie fachliche Zusatzdaten als Objekt. |
| `apiKey` | `api_key` | Optional | Alternative zur Signaturuebergabe, wenn der konkrete Verifier dies akzeptiert. |

## Minimalbeispiel als URL/Form-Parameter

```bash
curl -X POST 'https://example.test/api/intelligence/events?apiKey=<secret>' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'processKey=invoice' \
  --data-urlencode 'eventKey=invoice.received' \
  --data-urlencode 'stepKey=received' \
  --data-urlencode 'eventPhase=after' \
  --data-urlencode 'sourceSystem=amagno' \
  --data-urlencode 'documentId=4711' \
  --data-urlencode 'documentUuid=6f4a2c1e-9d2b-4d74-9d1e-0f18d6b1a234' \
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

Moegliche Fehler:

- `401 invalid_signature`: Signatur/API-Key wurde vom Verifier abgelehnt.
- `400 invalid_json`: JSON-Body ist syntaktisch ungueltig.
- `400 invalid_payload`: JSON-Body ist kein Objekt.
- `400 unknown_process_key`: `processKey` fehlt, ist leer oder ist `unknown`.

## Idempotenz und Prozessinstanzen

Die HTTP-Annahme speichert jeden gueltigen Request als Incoming Event. Die
Idempotenz wird bei der Verarbeitung in den append-only Event Store
durchgesetzt. Massgeblich ist `externalEventKey`.

Fuer produktive Sender sollte `externalEventKey` daher stabil und eindeutig
sein, etwa aus Quellsystem, Workflow-Event-ID, Dokument-UUID, Version,
Schritt und Ereigniszeitpunkt.

Eine neue `documentVersion` erzeugt eine eigene Prozessinstanz. Historische
Instanzen bleiben erhalten.
