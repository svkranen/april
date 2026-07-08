# GitHub-Readiness First-Time Experience Smoke Test

## Ziel

Dieser Smoke Test beschreibt die Erstinstallation aus Sicht eines neuen Community-Nutzers. Er prueft den Weg von einem frischen Clone bis zu einer lokal im Browser erreichbaren APRIL-Instanz mit geladenen Demo-Daten.

Der Test ist kein produktives Deployment-Szenario. Er dient dazu, Stolpersteine fuer den spaeteren Open-Source-Quickstart sichtbar zu machen, bevor daraus README-Anweisungen abgeleitet werden.

## Getesteter Pfad

```bash
git clone <repository-url>
cd <repository>
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate
docker compose exec app php bin/console april:demo:user:create
docker compose exec app php bin/console april:fixtures:load --reset
```

Danach:

```text
Browser oeffnen: http://localhost:8080/app
Login: admin@example.local / april
```

## Bekannte Stolpersteine

- Docker-in-Incus/PostgreSQL: In verschachtelten Containerumgebungen kann PostgreSQL beim Erzeugen von Unix-Domain-Sockets an AppArmor-/Runtime-Beschraenkungen scheitern.
- Fehlende PHP-Extensions im Dockerfile: `gd` und `sockets` werden fuer den bestehenden Composer-Lock benoetigt.
- Fehlendes `git` im Dockerfile: Composer braucht `git`, wenn Pakete aus Source installiert werden muessen.
- `.env` muss aus `.env.example` erzeugt werden, bevor Symfony lokal sinnvoll startet.
- Composer-Installation: Der Community-Core muss ohne private Composer-Repositories installierbar bleiben.
- Lokale HTTP-Erreichbarkeit: Der Quickstart soll unter `http://localhost:8080/app` ohne HTTPS-Zwang funktionieren.

## Erwartetes Zielbild

- Lokaler HTTP-Quickstart ohne Zertifikatswarnung.
- Kein HTTPS-Zwang im Docker-Dev-Setup.
- Keine privaten Composer-Credentials im Community-Installationspfad.
- Demo-Daten koennen geladen und im Browser sichtbar gemacht werden.
- Demo-User kann lokal reproduzierbar erzeugt werden.
- Guided Tours verlinken auf den First-Insight-Wizard.
- Der dokumentierte Pfad funktioniert fuer neue Community-Nutzer reproduzierbar.

## Offene Follow-ups

- Fresh-Clone-Dockerpfad in einer Umgebung mit Docker erneut verifizieren.
