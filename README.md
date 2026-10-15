# avalynxofficeeas

**ActiveSync extension for [AvalynxOffice](https://github.com/jbsnewmedia/avalynxoffice)**
(core repository: `avalynxoffice`) — Exchange ActiveSync (EAS) device
synchronization based on [Z-Push](https://github.com/Z-Hub/Z-Push).

License: **AGPL-3.0** (see `LICENSE` and `NOTICE.md`).

## What it is

`avalynxofficeeas` lets ActiveSync-compatible devices (iOS, Android, Outlook)
synchronize mail, contacts and calendars with an AvalynxOffice installation.

It is an **independent extension, not part of avalynxoffice**:

- runs as its own process/container next to the avalynxoffice core,
- talks to the same PostgreSQL database through **its own database models**
  (the database schema is the interface — no core classes are used),
- enforces the avalynxoffice licensing state (`license_state`) for
  device-initiated writes and hands outgoing messages to the core mail queue;
  the core remains responsible for SMTP delivery and licensing decisions,
- needs no daemon: ActiveSync is request-driven (HTTP).

## Status

**Fully ported.** Mail, contacts and calendar synchronize over the shared
database; outgoing device mail is handed to the avalynxoffice queue
(delivery and licensing remain in the core). Device writes are blocked with
the licensing read-only mirror. Tested: backend integration suite (13 tests)
and a live end-to-end FolderSync through the compose stack.

## Configuration

| Variable | Meaning |
|---|---|
| `DATABASE_URL` | PostgreSQL connection (same database as avalynxoffice) |
| `ATTACHMENT_STORAGE_DIR` | attachment storage path, **shared volume** with the core (default `data/attachments`) |
| `EAS_STATE_DIR` | Z-Push state directory (default `var/eas-state`) |
| `EAS_LOG_DIR` | Z-Push log directory (default `var/log/eas`) |

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
