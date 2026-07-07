# APRIL Enterprise

APRIL Enterprise beschreibt Funktionen, Konfigurationen und Integrationen, die ueber den neutralen Community-Core hinausgehen. Diese Teile koennen privat, kundenspezifisch oder kommerziell wertvoll sein und sollten nicht ungeprueft in einem public Repository landen.

## Produktive Templates

Produktive Prozess-Templates enthalten oft echte Prozessnamen, Feldnamen, IDs, SLA-Regeln, Varianten und fachliche Sonderfaelle. Sie bilden konkretes Organisationswissen ab und gehoeren deshalb in private Repositories oder kundenspezifische Pakete.

Fuer Community sollten stattdessen synthetische Demo-Templates verwendet werden.

## Kundenspezifische Integrationen

Integrationen fuer konkrete Kunden, Mandanten, DMS-Instanzen, ERP-Systeme oder Dateiablagen gehoeren in Enterprise-/Private-Pakete. Dazu zaehlen insbesondere:

- konkrete Connector-Konfigurationen
- produktive Matching-Profile
- kundenspezifische Exportlogik
- Spezialregeln fuer Freigaben, Signaturen oder Eskalationen
- Import- und Upload-Prozesse

## Dashboards und Reporting

Dashboards koennen generische Core-Daten anzeigen, enthalten aber schnell produktnahe Kennzahlen, Rollenmodelle und Management-Logik. Basisansichten koennen Community sein; kunden- oder produktnahe Reporting-Pakete gehoeren eher in Enterprise.

Typische Enterprise-Bereiche:

- Management-Dashboards
- SLA-Reporting
- Mandantenuebergreifende Auswertungen
- Export von KPI- oder Audit-Berichten
- kundenspezifische Diagramme und Filter

## Multi-Tenant und SaaS

Multi-Tenant- und SaaS-Funktionen betreffen Betrieb, Sicherheit, Lizenzierung und Mandantentrennung. Sie sind strategisch sensibel und sollten getrennt vom Community-Core behandelt werden.

Dazu gehoeren:

- Mandantenverwaltung
- Rollen- und Berechtigungskonzepte fuer SaaS
- Abrechnung und Lizenzpruefung
- tenant-spezifische Konfiguration
- Betriebsmetriken und Monitoring

## Deployment- und Betriebswissen

Deploymentdetails sind selten allgemein public-faehig. Private Infrastruktur, Hosts, Pfade, Secrets, CI/CD-Pipelines und Betriebsablaeufe gehoeren in geschuetzte Repositories oder interne Runbooks.

Nicht public geeignet sind insbesondere:

- echte Hosts, IPs und interne Domains
- SSH-/Deployment-Konfiguration
- private Composer- oder Container-Registries
- produktive `.env`-Dateien
- kundenspezifische Scheduler-, Queue- oder Batch-Mechanik

## Lizenzierung

Die Lizenzgrenze sollte klar zwischen Core, Connectoren und Enterprise-Funktionen gezogen werden.

Moegliche Aufteilung:

- Community-Core: public Lizenz nach strategischer Entscheidung.
- Connectoren: je nach System public, partnerbezogen oder privat.
- Enterprise-Funktionen: kommerzielle Lizenz, private Distribution oder kundenspezifische Lieferung.

Vor einer GitHub-Veroeffentlichung muss feststehen, welche Funktionen public nutzbar sein sollen und welche bewusst ausserhalb des Community-Repositories bleiben.
