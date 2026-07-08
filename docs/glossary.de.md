# APRIL-Glossar

Dieses Glossar definiert die kanonische Community-Core-Terminologie fuer APRIL. Connector-spezifische Begriffe koennen abweichen, aber Core-UI und Dokumentation sollten diese Begriffe konsistent verwenden.

## Item

Das Prozessobjekt, dessen Lebenszyklus APRIL anhand von Events rekonstruiert und analysiert.

Connectoren duerfen ein Item je nach Domaene Dokument, Incident, Ticket, Request, Auftrag, Case oder anders nennen.

## Event

Ein gespeichertes Ereignis, das beschreibt, was zu einem bestimmten Zeitpunkt mit einem Item passiert ist.

## Journey

Die rekonstruierte Timeline eines Items ueber Events, Prozessinstanzen, Context Snapshots und Findings hinweg.

## Finding

Eine menschenlesbare Beobachtung von APRIL, zum Beispiel eine Abweichung, fehlender Context, eine Warnung oder eine Regelverletzung.

## Context Snapshot

Der erfasste fachliche Context eines Items zum Zeitpunkt eines Events. Snapshots sind historische Evidenz und duerfen spaeter nicht stillschweigend veraendert werden.

## Process Instance

Ein rekonstruierter Lauf eines Process Templates fuer ein Item und eine Version.

## Process Template

Das erwartete Prozessmodell, gegen das Events, Entscheidungen, Context, Routing und erlaubte Ergebnisse geprueft werden.

## Connector

Ein Adapter fuer ein Quell- oder Zielsystem. Connectoren uebersetzen systemspezifische IDs und Begriffe in APRIL-Core-Konzepte.
