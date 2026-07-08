# Docker Quickstart Decision

Der GitHub-Quickstart fuer APRIL soll eine kleine, reproduzierbare lokale Umgebung bereitstellen. Ziel ist nicht produktionsnahes Deployment, sondern ein schneller Einstieg fuer Entwicklung, Tests und Demo-Daten.

## Runtime

APRIL benoetigt PHP `>=8.2`. Fuer den Docker-Quickstart soll PHP 8.4 bevorzugt werden, weil die bestehende CI bereits mit PHP 8.4 arbeitet und damit ein einheitlicher Entwicklungsstandard entsteht.

Composer ist erforderlich. Die Anwendung nutzt Symfony Flex Auto-Scripts, PHPUnit ueber die Symfony PHPUnit Bridge und Symfony AssetMapper/Importmap.

APRIL basiert aktuell auf Symfony 7.4 Komponenten. Der Quickstart sollte deshalb ein Image verwenden, das Symfony 7.4 mit den benoetigten PHP-Extensions ausfuehren kann.

## Datenbank

PostgreSQL ist die Quickstart-Datenbank. Obwohl `.env.example` aktuell einen SQLite-freundlichen Default enthaelt, verwenden die vorhandenen Doctrine-Migrationen PostgreSQL-nahe Features wie `JSONB`. Ein PostgreSQL-Container vermeidet dadurch eine zweite, abweichende Demo-Persistenz.

MariaDB oder MySQL sind fuer den ersten Community-Quickstart nicht vorgesehen.

## Assets

Node, Vite oder ein separates Frontend-Build sind fuer den Quickstart nicht notwendig. APRIL nutzt Symfony AssetMapper und Importmap. Die vorhandenen Assets koennen ueber Composer-/Symfony-Mechanik vorbereitet und direkt durch die Symfony-Anwendung ausgeliefert werden.

## Web Runtime

FrankenPHP ist die bevorzugte einfache Runtime fuer den GitHub-Quickstart. Sie reduziert den lokalen Einstieg auf einen App-Container ohne separates Nginx-/PHP-FPM-Setup.

PHP-FPM plus Nginx bleibt eine moegliche Betriebsvariante, ist fuer den ersten Quickstart aber unnoetig komplex. Symfony CLI ist fuer lokale Entwicklung bequem, aber als Docker-Standard weniger reproduzierbar als FrankenPHP.

## Minimale Compose-Services

Ein erstes `docker-compose` sollte nur diese Services enthalten:

- `app`: FrankenPHP mit PHP 8.4, Composer, Symfony-App und benoetigten Extensions.
- `db`: PostgreSQL, z. B. Version 16 oder 17.

Weitere Services wie Adminer, Mailpit, Worker oder Queue-Infrastruktur sollten erst ergaenzt werden, wenn ein Demo- oder Produktworkflow sie wirklich benoetigt.

## Skeleton

Das Phase-1.1-Skeleton stellt `compose.yaml`, `Dockerfile` und `.dockerignore` bereit. Es mountet den Sourcecode nach `/app`, haelt PostgreSQL-Daten in einem benannten Volume und setzt lokale Development-Defaults direkt im Compose-Service.

Die Umgebung ist bewusst noch kein vollstaendiger 15-Minuten-Quickstart. Build, Start, Migrationen und Composer-Installationsschritte bleiben dem spaeteren Quickstart vorbehalten.

## Demo-Daten

Demo-Daten fuer den Community-Quickstart sollen connectorfrei funktionieren. Events sollten den benoetigten Context inline mitliefern, damit kein DMS-, ERP- oder Amagno-Connector notwendig ist.

Ein spaeterer Demo-Satz sollte enthalten:

- synthetisches Process Template ohne produktive IDs
- wenige Beispiel-Events als JSON oder JSONL
- inline Context Snapshots in den Events
- dokumentierte erwartete Timeline- und Deviation-Ergebnisse

## Community vs Enterprise ENV

Amagno- und Enterprise-spezifische ENV-Werte sollen langfristig aus dem Community-Default herausgeloest werden. Der Community-Quickstart sollte nur neutrale APRIL-, Symfony- und Datenbankwerte benoetigen.

Connector-spezifische Variablen gehoeren in Connector-/Enterprise-Beispiele, etwa fuer `april-amagno-connector`.
