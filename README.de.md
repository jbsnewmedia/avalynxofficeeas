# AvalynxOfficeEAS

[English](README.md)

**AvalynxOfficeEAS — ActiveSync-Erweiterung für [AvalynxOffice](https://github.com/jbsnewmedia/avalynxoffice)**
(Core-Repository: `avalynxoffice`) — Geräte-Synchronisation über Exchange
ActiveSync (EAS) auf Basis von [Z-Push](https://github.com/Z-Hub/Z-Push).

> Repository- und Paketname sind bewusst kleingeschrieben
> (`avalynxofficeeas`); der Produktname ist **AvalynxOfficeEAS**.

Lizenz: **AGPL-3.0** (siehe `LICENSE` und `NOTICE.md`).

## Was es ist

AvalynxOfficeEAS lässt ActiveSync-fähige Geräte (iOS, Android, Outlook) E-Mails,
Kontakte und Kalender mit einer AvalynxOffice-Installation synchronisieren.

Es ist eine **unabhängige Erweiterung, kein Teil von avalynxoffice**:

- läuft als eigener Prozess/eigenes Container neben dem avalynxoffice-Core,
- spricht über **eigene Datenbankmodelle** mit derselben Datenbank
  (PostgreSQL oder MariaDB/MySQL; das Datenbankschema ist die Schnittstelle —
  keine Core-Klassen werden verwendet),
- setzt den avalynxoffice-Lizenzstatus (`license_state`) für
  geräteinitiierte Schreibvorgänge durch und übergibt ausgehende Nachrichten
  an die Core-Mail-Warteschlange; SMTP-Versand und Lizenzentscheidungen
  bleiben im Core,
- erfasst jede erfolgreiche Geräte-Authentifizierung als Seat-Aktivität
  (`app_user.last_auth_at`), sodass reine Sync-Accounts ihren
  [Active Seat](https://github.com/jbsnewmedia/avalynxoffice) (APL 1.0)
  behalten,
- braucht keinen Daemon: ActiveSync ist request-driven (HTTP).

## Login und Account-Scope

Das Gerät meldet sich mit einem **Anwendungsbenutzer** an — der E-Mail-Adresse
des AvalynxOffice-Weblogins — und einem **App-Passwort**, das im Web-Client
unter *Profil → App-Passwörter* (`/profile/app-passwords`) erstellt wird. Das
Web-Login-Passwort selbst wird bewusst **nicht** akzeptiert: Es würde auf
jedem Gerät gespeichert und könnte nicht pro Gerät widerrufen werden.
App-Passwörter werden einmalig angezeigt, als `password_hash()`-Digests
gespeichert (`app_user_app_password`), können mit oder ohne
Bindestriche/Großschreibung eingegeben werden und werden einzeln im Profil
oder gesammelt durch einen Passwort-Reset widerrufen. Postfach-(Account-)
oder Alias-Adressen werden als Benutzernamen nicht akzeptiert: Eine
Geräte-Sitzung gehört immer zu einem Benutzer.

Der Benutzer erhält **jeden ihm zugewiesenen Account** im Office: alle
Mail-Ordner sowie der Kontakt- und Kalender-Ordner jedes Accounts werden
synchronisiert. Hat ein Benutzer mehr als einen Account, erhält jeder Ordner
einen Präfix mit dem Account-Namen (z. B. `Info / Posteingang`), damit das
Gerät sie unterscheiden kann. Antworten und Weiterleitungen werden unter dem
Account der Ursachennachricht abgelegt; neue Nachrichten gehen an den
primären Account des Benutzers (erster Account, sortiert nach E-Mail-Adresse).

## Status

**Vollständig portiert.** Mail, Kontakte und Kalender synchronisieren über
die gemeinsame Datenbank; ausgehende Geräte-Mail wird in die
avalynxoffice-Warteschlange übergeben (Versand und Lizenzierung bleiben im
Core). Geräte-Schreibvorgänge werden mit dem Lizenz-Read-Only-Spiegel
blockiert, und jeder erfolgreiche Geräte-Login aktualisiert die
Active-Seat-Aktivität (`app_user.last_auth_at`). Getestet: Backend-
Integrationssuite und ein live End-to-End-FolderSync über das Compose-Stack.

## Konfiguration

| Variable | Bedeutung |
|---|---|
| `DATABASE_URL` | Datenbankverbindung (dieselbe Datenbank wie avalynxoffice; PostgreSQL oder MariaDB/MySQL) |
| `ATTACHMENT_STORAGE_DIR` | Ablagepfad für Anhänge, **gemeinsames Verzeichnis** mit dem Core (Standard `data/attachments`) |
| `ATTACHMENT_STORAGE_MAX_BYTES` | maximale Größe eines einzelnen vom Gerät gesendeten Anhangs in Bytes (Standard `26214400` = 25 MB, Minimum `1024`); größere Anhänge brechen den Versand mit `MailSubmissionFailed` ab, statt still verworfen zu werden |
| `EAS_STATE_DIR` | Z-Push-State-Verzeichnis (Standard `var/eas-state`) |
| `EAS_LOG_DIR` | Z-Push-Log-Verzeichnis (Standard `var/log/eas`) |
| `EAS_LOGLEVEL` | Z-Push-Log-Level (`1` fatal, `2` error, `4` warn, `8` info, `16` debug, `32` wbxml; Standard `8`) |

Alle Variablen können aus der echten Umgebung (Docker/Compose) oder aus
einer lokalen `.env` neben der `composer.json` stammen; `.env`-Werte
überschreiben nie bereits gesetzte Variablen.

## Deployment

Empfohlen ist der `avalynxofficedocker`-Compose-Stack, der
`/Microsoft-Server-ActiveSync` an diesen Container leitet. Dasselbe Layout
funktioniert in einem klassischen PHP-Hosting: Ein Rewrite für
`/Microsoft-Server-ActiveSync` (oder eine Subdomain) zeigt auf
`public/index.php`.

## Entwicklung

```bash
composer install
vendor/bin/phpunit
```

Die Integrationstests verwenden eine eigene Datenbank
(`avalynxofficeeas_test`, abgeleitet aus `DATABASE_URL`) und benötigen eine
erreichbare PostgreSQL- oder MariaDB-Instanz sowie eine PHP-8.5-Runtime mit
`ext-sodium`. Das veröffentlichte Dev-Image
`jbsnewmedia/avalynxofficeeas-dev` (gebaut aus `Dockerfile.dev`, enthält die
Dev-Abhängigkeiten) ist die empfohlene Laufzeit — den exakten
`docker run`-Befehl findest du in `AGENTS.md`.

Z-Push ist eine reguläre Composer-Abhängigkeit (`z-push/z-push-dev` von
Packagist — das `-dev`-Suffix gehört zum Upstream-Paketnamen, die Version
ist ein stabiles Release). Unsere composer.json ergänzt die
Classmap-/Files-Autoload-Einträge, die das Upstream-Paket nicht mitbringt.
