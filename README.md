# Amagno / Nevaris Interface

Dieses Projekt zieht Dokumente aus Amagno, bereitet sie anhand einer `matching.json` auf und exportiert die Ergebnisse (lokal, FTP, Amagno, SQL). Die wichtigsten Schritte zur Konfiguration und zum Betrieb sind hier zusammengefasst.

## 1. Grundkonfiguration

1. `.env` (bzw. `.env.local`) anpassen:
   ```env
   AMAGNO_BASE_URI=https://amagno.me        # optional, falls kein Base-URI in den Verbindungen steht
   AMAGNO_API_USERNAME=                     # optional: Default-Login
   AMAGNO_API_PASSWORD=
   AMAGNO_API_AUTH_TYPE=                    # z.B. "Windows" oder leer lassen
   AMAGNO_CREDENTIAL_ID=1                   # Default-Credential-ID für den Tokenabruf
   AMAGNO_MATCHING_FILE=oldProject/matching.json
   AMAGNO_TEMPLATE_DIR=oldProject
   AMAGNO_CHECKPOINT_DIR=var/checkpoints
   ```
   Die `.env` dient nur als Fallback; pro Verbindung kannst du alle Werte überschreiben.

2. `config/amagno_connections.json` kopieren und **NICHT** in Git einchecken (z. B. als `config/amagno_connections.local.json`, per `.gitignore` ausschließen). In `config/services.yaml` bzw. `.env.local` auf die lokale Datei verweisen:
   ```yaml
   parameters:
       amagno.connections_file: '%kernel.project_dir%/config/amagno_connections.local.json'
   ```

3. `config/amagno_connections.local.json` aufbauen. Es gibt typischerweise zwei Varianten:

   Reiner Datenexport:
   ```json
   {
     "credentials": [
       {
         "cid": 1,
         "base_uri": "https://amagno.me",
         "username": "demo@example.com",
         "password": "SECRET",
         "auth_type": null
       }
     ],
     "configurations": [
       {
         "id": "nev-onprem-export",
         "active": true,
         "credential_id": 1,
         "vault_id": "GUID",
         "magnet_id": "GUID",
         "profile": "Nevaris Export",
         "template": "debitoren",
         "system": "onprem",
         "export": "local",
         "folder": "\\\\server\\share\\amagno",   // UNC-Pfad für lokale Exporte
         "success_stamp": "GUID",                 // optional
         "error_stamp": "GUID",                   // optional
         "error_attribute": "GUID"                // optional
       }
     ]
   }
   ```

   Reiner Signature Check:
   ```json
   {
     "credentials": [
       {
         "cid": 1,
         "base_uri": "https://amagno.me",
         "username": "demo@example.com",
         "password": "SECRET",
         "auth_type": null
       }
     ],
     "configurations": [
       {
         "id": "nev-onprem-signature-check",
         "active": true,
         "credential_id": 1,
         "vault_id": "GUID",
         "magnet_id": "GUID",
         "signature_check": {
           "required_tag": "GUID-ZU-PRUEFEN-DURCH",
           "confirmed_tag": "GUID-GEPRUEFT-DURCH",
           "success_stamp": "GUID-ERFOLGREICH",
           "checkpoint_key": "nev-onprem-signature-check"
         }
       }
     ]
   }
   ```
   * `credentials`: technische Amagno-Accounts inkl. Basis-URL und Login.
   * `configurations`: beschreibt je Verbindung Magnet/Vault, Matching-Profil, Exportziel oder Signaturpruefung. Inaktive Einträge (`"active": false`) werden ignoriert.
   * `export`: `local`, `ftp`, `amagno` oder `sql`. Optional kannst du `ftp_*`, `db_*` usw. weiterhin über CLI setzen.
   * `folder`: Zielpfad für lokale Exporte (UNC-Pfade möglich).
   * `success_stamp`, `error_stamp`, `error_attribute`: GUIDs für Stempel/Merkmal (optional).
   * Bei einem reinen Signature-Check-Eintrag koennen `profile`, `template`, `system`, `export`, `folder`, `error_stamp` und `error_attribute` weggelassen werden.
   * `vault_id` muss aktuell trotzdem gesetzt sein, weil die gemeinsame Verbindungsvalidierung es noch verlangt.

4. `oldProject/matching.json` über `/settings` pflegen. Die Oberfläche ruft intern die alte `save.php` auf.

## 2. Sync ausführen

### Einmalig über CLI

```bash
bin/console amagno:sync --all-connections
```

* `--all-connections` läuft nacheinander über alle aktiven Profile (Default-Aufruf für Jobs).
* `--connection=<id>` zieht alle Werte aus der JSON-Konfiguration.
* Optional überschreibst du Parameter weiterhin via CLI, z. B. `--limit=25`, `--dry-run`, `--matching-profile=…`, `--folder=…` usw.
* Ohne `--connection` musst du Magnet, Vault, Profile etc. wie gewohnt per CLI übergeben.

### Automatisierung unter Windows

1. Batch-Datei erstellen, z. B. `C:\scripts\amagno_sync.bat`:
   ```bat
@echo off
set APP_ENV=prod
set APP_DEBUG=0
cd /d C:\inetpub\amagno_nev_interface
php bin\console amagno:sync --all-connections >> C:\logs\amagno_sync.log 2>&1
```

2. Aufgabenplanung:
   * Aufgabenplanung öffnen → „Aufgabe erstellen“.
   * Trigger: nach Bedarf (z. B. täglich 05:00 Uhr).
   * Aktion: „Programm starten“, Programm: `cmd.exe`, Argumente: `/c C:\scripts\amagno_sync.bat`.
   * „Mit höchsten Privilegien ausführen“ aktivieren, falls UNC-Pfade o. Ä. verwendet werden.

### Fehlerbehandlung

* Läuft alles glatt, wird – sofern `success_stamp` gesetzt – der entsprechende Stempel auf allen verarbeiteten Dokumenten abgelegt.
* Bei Fehlern setzt der Service (falls konfiguriert) zuerst `error_stamp` und schreibt die Fehlermeldung in das Merkmal `error_attribute`. Beide Felder sind optional.
* Das CLI liefert Exit-Code ≠ 0 bei Fehlern, sodass die Task eine fehlerhafte Ausführung erkennen kann.

## 3. Typische Probleme

| Problem | Ursache / Lösung |
| ------- | ---------------- |
| `No route found for "POST .../save.php"` | Controller nicht eingebunden oder `/settings` nicht neu geladen; sicherstellen, dass `SettingsActionController` aktiv ist. |
| `Credential-ID ... nicht vorhanden` | `credential_id` in der Verbindung stimmt nicht mit einem Eintrag in `credentials` überein. |
| `Keine Amagno Base URI` | Weder in `.env` noch in der Verbindung wurde `base_uri` gesetzt. |
| CORS-Fehler im Browser bei `/settings` | Die alte Oberfläche versucht, direkt auf `https://amagno.me` zu posten – diese Logins müssen in Zukunft serverseitig ersetzt werden (Proxy/Controller). |

## 4. Logs & Debugging

* Monolog schreiben in `var/log/dev.log` bzw. `var/log/prod.log` (nach Installation des `symfony/monolog-bundle`).
* `bin/console amagno:sync ... -vvv` für ausführlichere CLI-Ausgaben.
* Fehlgeschlagene Stempel/Merkmale werden im Logger protokolliert.

## 5. Signatur-Pruefung

Es gibt zusaetzlich ein separates Modul fuer Signatur-Vollstaendigkeit. Die Kernlogik liegt unter `src/SignatureCheck/` und ist bewusst ohne Symfony-/Amagno-Abhaengigkeit gehalten; Amagno-spezifisch sind nur der Adapter-Service und das CLI-Command.

### Konfiguration je Verbindung

In `config/amagno_connections.local.json` kann pro Verbindung optional ein Block `signature_check` hinterlegt werden:

```json
{
  "id": "nev-onprem",
  "magnet_id": "GUID",
  "signature_check": {
    "required_tag": "GUID-ZU-PRUEFEN-DURCH",
    "confirmed_tag": "GUID-GEPRUEFT-DURCH",
    "result_attribute": "GUID-ERGEBNIS",
    "success_stamp": "GUID-ERFOLGREICH",
    "complete_stamp": "GUID-VOLLSTAENDIG",
    "incomplete_stamp": "GUID-UNVOLLSTAENDIG",
    "checkpoint_key": "nev-onprem-signature-check"
  }
}
```

* `required_tag`: Merkmal fuer die erwarteten Pruefer, z. B. `Zu pruefen durch`
* `confirmed_tag`: Merkmal fuer die vorhandenen Unterschriften, z. B. `Geprueft durch`
* Die Pruefung vergleicht die Namen als eindeutige Namensmenge. Doppelte Eintraege in `required_tag` oder `confirmed_tag` werden ignoriert, damit zusaetzliche oder doppelt aufgetragene Merkmale in Amagno nicht zu false negatives fuehren.
* `success_stamp` ist der Stempel fuer erfolgreich vollstaendige Dokumente.
* `complete_stamp` bleibt als abwaertskompatibler Alias unterstuetzt.
* `result_attribute` und `incomplete_stamp` sind optional.
* Fuer einen reinen Signature-Check-Eintrag werden keine Export-Felder wie `folder` oder `export` benoetigt.

### Manuell starten

```bash
bin/console amagno:verify-signatures --connection=nev-onprem --use-checkpoint
```

Oder direkt ohne Verbindungsdefinition:

```bash
bin/console amagno:verify-signatures \
  --magnet=GUID \
  --required-tag=GUID-ZU-PRUEFEN-DURCH \
  --confirmed-tag=GUID-GEPRUEFT-DURCH \
  --result-attribute=GUID-ERGEBNIS \
  --success-stamp=GUID-ERFOLGREICH \
  --use-checkpoint
```

Wichtige Optionen:

* `--all-connections`: verarbeitet alle Verbindungen mit `signature_check`
* `--use-checkpoint`: prueft nur seit dem letzten Lauf geaenderte Dokumente
* `--dry-run`: schreibt weder Ergebnis-Merkmal noch Stempel zurueck
* `--success-stamp`: optionaler Stempel fuer erfolgreich gepruefte Dokumente
* `--incomplete-stamp`: optionaler Stempel fuer unvollstaendige Dokumente

### Alle 5 Minuten unter Windows

Beispiel fuer eine zweite Batch-Datei:

```bat
@echo off
set APP_ENV=prod
set APP_DEBUG=0
cd /d C:\inetpub\amagno_nev_interface
php bin\console amagno:verify-signatures --all-connections --use-checkpoint >> C:\logs\amagno_signature_check.log 2>&1
```

In der Aufgabenplanung dann einen Trigger "taeglich" mit Wiederholung alle 5 Minuten hinterlegen.
