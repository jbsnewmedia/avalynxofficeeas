# AvalynxOfficeEAS

**AvalynxOfficeEAS — ActiveSync extension for [](https://github.com/jbsnewmedia/avalynxoffice)**
(core repository: `avalynxoffice`) — Exchange ActiveSync (EAS) device
synchronization based on [Z-Push](https://github.com/Z-Hub/Z-Push).

> The repository and package name are intentionally lowercase
> (`avalynxofficeeas`); the product name is **AvalynxOfficeEAS**.

License: **AGPL-3.0** (see `LICENSE` and `NOTICE.md`).

## What it is

AvalynxOfficeEAS lets ActiveSync-compatible devices (iOS, Android, Outlook)
synchronize mail, contacts and calendars with an AvalynxOffice installation.

It is an **independent extension, not part of avalynxoffice**:

- runs as its own process/container next to the avalynxoffice core,
- talks to the same PostgreSQL database through **its own database models**
  (the database schema is the interface — no core classes are used),
- enforces the avalynxoffice licensing state (`license_state`) for
  device-initiated writes and hands outgoing messages to the core mail queue;
  the core remains responsible for SMTP delivery and licensing decisions,
- records every successful device authentication as seat activity
  (`app_user.last_auth_at`), so sync-only accounts keep their
  [Active Seat](https://github.com/jbsnewmedia/avalynxoffice) (APL 1.0),
- needs no daemon: ActiveSync is request-driven (HTTP).

## Login and account scope

The device logs in with an **application user** — the e-mail address of the
AvalynxOffice web login — and an **app password** created in the web client
under *Profile → App passwords* (`/profile/app-passwords`). The web login
password itself is deliberately **not** accepted: it would be stored on every
device and could not be revoked per device. App passwords are shown once,
stored as `password_hash()` digests (`app_user_app_password`), can be typed
with or without their dashes/case, and are revoked individually in the
profile or all at once by a password reset. Mailbox (account) or alias
addresses are not accepted as usernames: a device session always belongs to
a user.

The user receives **every account assigned to them** in the office: all mail
folders plus the contacts and calendar folder of each account are
synchronized. When a user has more than one account, each folder is prefixed
with its account name (e.g. `Info / Posteingang`) so the device can tell them
apart. Replies and forwards are filed under the account of the source
message; fresh messages go to the user's primary account (first account,
sorted by e-mail address).

## Status

**Fully ported.** Mail, contacts and calendar synchronize over the shared
database; outgoing device mail is handed to the avalynxoffice queue
(delivery and licensing remain in the core). Device writes are blocked with
the licensing read-only mirror, and every successful device login refreshes
the Active Seat activity (`app_user.last_auth_at`). Tested: backend
integration suite and a live end-to-end FolderSync through the compose stack.

## Configuration

| Variable | Meaning |
|---|---|
| `DATABASE_URL` | PostgreSQL connection (same database as avalynxoffice) |
| `ATTACHMENT_STORAGE_DIR` | attachment storage path, **shared volume** with the core (default `data/attachments`) |
| `ATTACHMENT_STORAGE_MAX_BYTES` | maximum size of a single attachment sent from a device in bytes (default `26214400` = 25 MB, minimum `1024`); larger attachments abort the submission with `MailSubmissionFailed` instead of being dropped silently |
| `EAS_STATE_DIR` | Z-Push state directory (default `var/eas-state`) |
| `EAS_LOG_DIR` | Z-Push log directory (default `var/log/eas`) |
| `EAS_LOGLEVEL` | Z-Push log level (`1` fatal, `2` error, `4` warn, `8` info, `16` debug, `32` wbxml; default `8`) |

All variables can come from the real environment (Docker/compose) or from a
local `.env` next to `composer.json`; `.env` values never override variables
that are already set.

## Deployment

The recommended deployment is the `avalynxofficedocker` compose stack, which
routes `/Microsoft-Server-ActiveSync` to this container. The same layout works
in a classic PHP hosting setup: point a rewrite for
`/Microsoft-Server-ActiveSync` (or a subdomain) at `public/index.php`.

## Development

```bash
composer install
vendor/bin/phpunit
```

The integration tests use a dedicated database
(`avalynxofficeeas_test`, derived from `DATABASE_URL`) and require a
reachable PostgreSQL instance plus a PHP 8.5 runtime with `ext-sodium`.
The published dev image `jbsnewmedia/avalynxofficeeas-dev` (built from
`Dockerfile.dev`, includes the dev dependencies) is the recommended
runtime — see `AGENTS.md` for the exact `docker run` command.

Z-Push is a regular Composer dependency
(`z-push/z-push-dev` from Packagist — the `-dev` suffix is part of the
upstream package name, the version is a stable release). Our composer.json
adds the classmap/files autoload entries that the upstream package lacks.
