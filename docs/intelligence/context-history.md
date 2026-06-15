# Context History

APRIL speichert ContextSnapshots historisiert in `intelligence_context_snapshot`. Die Context-History-Auswertung macht diese gespeicherten Snapshots pro Dokument sichtbar und ist eine Audit-/Debug-Sicht.

Die fachliche `signCheck`-Bewertung bleibt unveraendert: Sie bewertet weiterhin den letzten relevanten Snapshot und aggregiert keine frueheren Werte aus `ToBeSignedBy` oder `SignedBy`.

## Command

```bash
bin/console intelligence:document:context-history <documentUuid> <processKey>
bin/console intelligence:document:context-history <documentUuid> <processKey> --diff
bin/console intelligence:document:context-history <documentUuid> <processKey> --json --diff
bin/console intelligence:document:context-history <documentUuid> <processKey> --fields=amount_net,cost_center,export_status,row_number
bin/console intelligence:document:context-history <documentUuid> <processKey> --with-empty
```

## Sortierung

Snapshots werden chronologisch sortiert:

1. `occurredAt`
2. `capturedAt`
3. `loadedAt`

## Diff

Der Diff zeigt hinzugefuegte, entfernte, geaenderte und unveraenderte Felder. JSON-Werte werden vor dem Vergleich deterministisch normalisiert, sodass Objekt-Key-Reihenfolgen keine falschen Aenderungen erzeugen. Fehlende Felder, explizites `null` und leere Strings bleiben unterscheidbar.

## Timeline

Die Dokument-Timeline kann Context-Snapshots optional direkt neben den Events anzeigen:

```bash
bin/console intelligence:document:timeline <documentUuid> <processKey> --with-context --with-diff --with-decisions
bin/console intelligence:document:timeline <documentUuid> <processKey> --format=json --with-context --with-diff --with-decisions
```

`--with-context` gibt den vollstaendigen Snapshot aus, sofern dem Event ein Snapshot zugeordnet ist. `--with-diff` zeigt Aenderungen gegenueber dem vorherigen verfuegbaren Snapshot desselben Timeline-Auszugs. Events ohne Snapshot werden angezeigt, setzen den vorherigen Snapshot aber nicht zurueck.

`--with-decisions` markiert Context-Aenderungen, deren Feld in `required_fields` oder einer Rule-Condition eines Decision Points verwendet wird. Das ist nur ein Analysehinweis. Die Template-Check-Logik wird dadurch nicht veraendert, und eine DEVIATION wird nicht automatisch zu einer WARNING herabgestuft.
