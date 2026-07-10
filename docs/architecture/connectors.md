# APRIL Connectors

Ein Connector verbindet APRIL Core mit einem konkreten externen System. Der Core bleibt dabei DMS- und Tool-unabhaengig; der Connector uebersetzt Systemdetails in die generischen Ports und Datenmodelle von APRIL.

## Was ist ein Connector?

Ein Connector ist ein optionaler Adapter fuer ein bestimmtes Quellsystem oder Zielsystem. Er kann Events normalisieren, Dokumentkontext laden, Signaturen pruefen, Zugriffssichten bewerten oder Ergebnisse zurueckschreiben.

Typische Connector-Aufgaben:

- externe Events in Canonical Events uebersetzen
- Dokument-Metadaten und fachliche Merkmale laden
- Dokumentversionen aufloesen
- Authentifizierung und Tokenhandling kapseln
- systemeigene IDs auf APRIL-Dokumentreferenzen abbilden
- optionale Sichtbarkeits-, Signatur- oder Freigabechecks bereitstellen

## Ports und Interfaces

APRIL Core spricht mit Connectoren ueber Ports. Ports beschreiben, was der Core braucht, aber nicht, wie ein konkretes System es liefert.

Wichtige Port-Arten:

- Event normalisieren: externe Payloads werden zu stabilen Prozessereignissen.
- Context liefern: benoetigte Felder werden fuer ein Dokument geladen.
- Signaturen oder Webhooks verifizieren: eingehende Events werden gegen ein Secret oder eine Systemsignatur geprueft.
- Dokumentzugriff kapseln: Connectoren koennen Dokumentlisten, Merkmale oder Versionen bereitstellen.
- Access Probes ausfuehren: optionale Provider pruefen, ob ein Dokument in einem definierten Ausschnitt sichtbar ist.

Tests fuer Core-Logik sollten Fake- oder InMemory-Implementierungen dieser Ports verwenden.

### Connector Context Provider Factories

Optionale Connector-Pakete stellen templateabhaengige Context Provider ueber den
neutralen Port `ConnectorContextProviderFactory` bereit. Implementierungen werden
mit `app.connector_context_provider_factory` getaggt und von
`ConnectorContextProviderFactoryRegistry` gesammelt. Der Core-Resolver kennt damit
weder Hersteller-Namespaces noch konkrete Connection-Registries.

Die Factory entscheidet mit `supports(connectorType, connectionName)`, ob sie zustaendig ist, und
erzeugt aus dem bereits validierten `ProcessTemplate` einen `ContextProvider`. Ein
nicht installierter Connector ergibt einen `UnavailableContextProvider`; dessen
Warning wird zusammen mit dem Snapshot gespeichert. Ein installiertes, aber
deaktiviertes Bundle kann ebenfalls einen warnenden Provider liefern, ohne den
Container-Boot zu verhindern.

Aus Kompatibilitaetsgruenden kann der Resolver den Connector-Typ aus einer
eindeutigen nicht-inline `field_mapping.source` ableiten, wenn `connector.type`
fehlt. Reine `event_context`-Mappings bleiben connectorfrei.

## Context per POST-Event

Ein Connector ist nicht zwingend noetig, um Context in APRIL zu verarbeiten. Ein aufrufendes System kann den benoetigten Context direkt im POST-Event mitliefern. In diesem Fall speichert APRIL den Context Snapshot aus dem Event und muss keine externen Merkmale nachladen.

Das ist besonders sinnvoll fuer:

- einfache Integrationen
- Systeme ohne geeignete API
- Offline- oder Batch-Szenarien
- Demo- und Community-Setups
- Events, bei denen der fachliche Zustand bereits vollstaendig bekannt ist

## Amagno als optionaler Connector

Amagno ist ein konkreter Connector, nicht der Core selbst. Amagno-spezifische Klassen laden Dokumentmerkmale, loesen Merkmalstypen auf, pruefen Freigaben oder schreiben Ergebnisse zurueck. Diese Logik gehoert in ein optionales Connector- oder Enterprise-Paket.

Der Community-Core darf ohne Amagno installierbar, testbar und nutzbar bleiben. Wenn Amagno benoetigt wird, kann ein privater oder Enterprise-Connector die passenden Ports implementieren.

## Moegliche Connectoren

APRIL ist nicht auf ein einzelnes System festgelegt. Denkbare Connectoren sind:

- REST-Connector fuer generische Webhook- oder API-Quellen
- ERP-Connector fuer Bestellungen, Rechnungen oder Stammdaten
- DMS-Connector fuer Dokumentmerkmale und Versionen
- SharePoint-Connector fuer Dokumentbibliotheken und Metadaten
- DocuWare-Connector fuer Archiv- und Workflowdaten
- ELO-Connector fuer Dokumentstatus, Metadaten und Workflows

Jeder Connector sollte als austauschbarer Adapter entworfen werden und den Core nicht mit proprietaeren SDKs oder internen Infrastrukturdetails belasten.
