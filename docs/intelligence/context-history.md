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

Die bestehende Dokument-Timeline wird in diesem Schritt nicht um `--with-context` oder `--with-context-diff` erweitert. Die Context-History ist bewusst ein eigener Command, damit Audit-/Debug-Auswertung und Event-Timeline nicht staerker gekoppelt werden. Die History loest Event-/Step-Informationen trotzdem ueber die vorhandene Timeline auf, sofern ein Snapshot per `external_event_key` zugeordnet werden kann.
