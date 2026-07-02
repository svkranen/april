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

## Kurzantwort: Welche Felder muss eine Amagno-Stempelaktion liefern?

Eine Stempelaktion muss nicht den kompletten Dokumentkontext senden. Fuer die
Prozessanalyse braucht der Intake vor allem ein stabiles Ereignis mit
Dokumentbezug:

| Feld | Pflicht im Betrieb | Zweck |
| --- | --- | --- |
| `processKey` | Ja | Ordnet das Event dem fachlichen Prozess und Template zu, z. B. `ai-rechnungen`. |
| `stepKey` | Ja, empfohlen | Der fachliche Schritt, der in Timeline, Template-Check und Heatmap erscheint. |
| `eventKey` | Empfohlen | Ereignisart, z. B. `stamp.applied` oder identisch zu `stepKey`. |
| `eventPhase` | Empfohlen | `after` fuer "Schritt wurde erreicht/ausgefuehrt"; `before` fuer Sichtbarkeits-/Vorher-Pruefungen. |
| `documentUuid` | Ja, wenn Kontext aus Amagno geladen werden soll | Stabile Amagno-Dokument-UUID fuer Timeline und Kontextaufladung. |
| `documentId` | Empfohlen | Lesbare/externe Dokument-ID aus Amagno. |
| `documentVersion` | Empfohlen | Dokumentversion. Fehlt der Wert, wird `1` verwendet. Eine neue Version erzeugt eine eigene Prozessinstanz. |
| `occurredAt` | Empfohlen | Fachlicher Ereigniszeitpunkt aus Amagno. Fehlt der Wert, nutzt die Verarbeitung den aktuellen Zeitpunkt. |
| `externalEventKey` | Stark empfohlen | Idempotenz-Schluessel. Muss fuer dasselbe Amagno-Ereignis stabil gleich bleiben. |
| Secret/API-Key | Ja, wenn `INTELLIGENCE_EVENT_SECRET` gesetzt ist | Authentifiziert die Stempelaktion gegen den Intake. |

Fachliche Merkmale wie Betrag, Dokumentart, Projektnummer, Kostenstelle,
Freigaben oder Signaturen werden im Normalfall **nicht** als Event-Parameter
gesendet. Sie werden nach Annahme des Events ueber `documentUuid`,
`context_profile.required` und `field_mapping` aus Amagno nachgeladen und als
unveraenderlicher `ContextSnapshot` gespeichert.

`attributes` ist nur fuer Zusatzdaten gedacht, die Amagno nicht spaeter
zuverlaessig liefern kann oder die ausschliesslich zum Event gehoeren.

## Authentifizierung / Signatur

Das Secret bzw. die Signatur kann ueber einen dieser Header gesendet werden:

- `X-Intelligence-Secret`
- `X-Intelligence-Signature`
- `X-Amagno-Signature`
- `Signature`

Alternativ kann das Secret als Parameter gesendet werden:

- `xIntelligenceSecret`
- `x_intelligence_secret`
- `X-Intelligence-Secret`
- `apiKey`
- `api_key`

Wenn `INTELLIGENCE_EVENT_SECRET` gesetzt ist, akzeptiert der lokale Verifier
entweder den identischen Secret-Wert oder eine HMAC-SHA256-Signatur ueber den
rohen Request-Body. Fuer HMAC wird der reine Hex-Digest oder das Format
`sha256=<digest>` akzeptiert.

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

## Beispiel fuer Amagno-Stempelaktion als URL/Form-Parameter

Wenn Amagno beim Stempelsetzen einen HTTP-POST mit
`application/x-www-form-urlencoded` ausloest, sollte die Aktion mindestens die
folgenden Parameter uebergeben. Die konkreten Platzhalter-Namen muessen an die
in Amagno verfuegbaren Stempel-/Dokumentvariablen angepasst werden:

```text
POST https://example.test/api/intelligence/events
Content-Type: application/x-www-form-urlencoded

processKey=ai-rechnungen
stepKey=01%20Rechnungen%20pruefen
eventKey=stamp.applied
eventPhase=after
sourceSystem=amagno
documentId=<Amagno-Dokumentnummer-oder-ID>
documentUuid=<Amagno-Dokument-UUID>
documentVersion=<Amagno-Dokumentversion>
occurredAt=<Stempelzeitpunkt-ISO-8601>
externalEventKey=amagno:<documentUuid>:<documentVersion>:<stepKey>:<occurredAt>
xIntelligenceSecret=<secret>
```

Pragmatische Vorgabe fuer die Stempelaktion:

- `processKey` ist meist fest je Stempelaktion, z. B. `ai-rechnungen`.
- `stepKey` ist der fachliche Schritt dieses Stempels, exakt wie im Template
  oder bewusst normalisiert.
- `eventKey` darf generisch sein, z. B. `stamp.applied`; fuer die Analyse ist
  `stepKey` wichtiger.
- `eventPhase=after` verwenden, wenn der Stempel den Schritt markiert.
- `documentUuid` ist wichtiger als `documentId`, weil Kontext und Timelines
  darueber stabil geladen werden.
- `documentVersion` immer mitsenden, sobald Amagno den Wert liefern kann.
- `externalEventKey` aus stabilen Bestandteilen bauen. Nicht bei jedem Retry
  neu zufaellig erzeugen.
- Den Secret bevorzugt per Header `X-Intelligence-Secret` senden. Wenn die
  Stempelaktion keine Header setzen kann, `xIntelligenceSecret` oder `apiKey`
  als Parameter nutzen.

Bei Form-Requests muessen Sonderzeichen URL-encoded werden. Das betrifft vor
allem Leerzeichen in `stepKey` und Plus-Zeichen in Zeitzonen, z. B.
`2026-05-29T10%3A00%3A00%2B02%3A00`.

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
| `xIntelligenceSecret` | `x_intelligence_secret`, `X-Intelligence-Secret`, `apiKey`, `api_key` | Optional | Alternative zur Signaturuebergabe, wenn der konkrete Verifier dies akzeptiert. |

## Verschachteltes Dokumentobjekt

JSON-Sender koennen Dokumentfelder alternativ unter `document` senden. Die
Normalizer lesen diese Werte ebenfalls:

```json
{
  "processKey": "ai-rechnungen",
  "stepKey": "01 Rechnungen pruefen",
  "eventKey": "stamp.applied",
  "document": {
    "id": "4711",
    "uuid": "6f4a2c1e-9d2b-4d74-9d1e-0f18d6b1a234",
    "version": 2
  }
}
```

Fuer Amagno-Stempelaktionen ist die flache Form meist einfacher.

## Kontextfelder und Template-Konfiguration

Welche Merkmale fuer Analyse, Decision Rules und SLA-/Konformitaetspruefung
benoetigt werden, steht nicht in der Stempelaktion, sondern im Prozess-Template:

```yaml
context_profile:
  required:
    - invoice_direction
    - amount_net
    - documentVersion

field_mapping:
  invoice_direction:
    source: amagno
    tag_id: "d9a8a028-f5ec-4c17-d0f3-08db1e05182d"
    stability: immutable

  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
    value_type: number
    stability: snapshot_required
```

Unterstuetzte eingebaute Kontextfelder des Amagno-Context-Providers:

| Kontextfeld | Herkunft |
| --- | --- |
| `documentVersion` | Version aus dem Event bzw. `DocumentRef`. |
| `documentId` | Externe Dokument-ID aus dem Event. |
| `documentUuid` | Dokument-UUID aus dem Event. |
| `approvals` | Platzhalter fuer Freigaben, aktuell leer sofern nicht spezifisch angebunden. |
| `signatures` | Platzhalter fuer Signaturen, aktuell leer sofern nicht spezifisch angebunden. |

Alle anderen Felder werden ueber `field_mapping` auf Amagno-Merkmale abgebildet.
Robuster ist `tag_id`; `tag_name` ist lesbarer, kann aber bei umbenannten oder
mehrdeutigen Merkmalen Warnungen erzeugen.

Typische fachliche Felder:

- `amount_net`: Nettobetrag fuer Betragsgrenzen und SLA-/Freigabevarianten.
- `invoice_direction` oder `document_type`: Dokumentart bzw. Eingangs-/Ausgangsrichtung.
- `project_number`: Projektnummer.
- `cost_center` oder `project_location`: Kostenstelle, Standort oder Organisationseinheit.
- `approvals` / `signatures`: Freigabe- und Signaturinformationen, sobald der konkrete Adapter sie liefert.

Der `ContextSnapshot` wird zum Zeitpunkt der Eventverarbeitung gespeichert und
spaeter nicht nachtraeglich veraendert. Wenn ein Feld fuer eine Decision Rule
benoetigt wird, muss es in `context_profile.required` stehen und technisch ueber
`field_mapping` aufloesbar sein.

## Minimalbeispiel als URL/Form-Parameter

```bash
curl -X POST 'https://example.test/api/intelligence/events?xIntelligenceSecret=<secret>' \
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
