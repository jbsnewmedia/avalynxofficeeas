# Project Instructions — avalynxofficeeas

## Project context

- This project is the **ActiveSync extension** for AvalynxOffice
  (core repository: `avalynxoffice`).
- It is a **separate process** sharing the AvalynxOffice PostgreSQL database:
  no AvalynxOffice core code may be used or required (AGPL aggregation
  boundary). The database schema is the interface.
- The vendored Z-Push fork lives in `z-push/` (AGPL-3.0, upstream 2.7.6).
- Licensing: device writes are blocked when the core's `license_state`
  reports READ_ONLY (`AvalynxOfficeEas\Licensing\LicenseState`); outgoing mail is
  only queued (delivery + licensing stay in the core). Successful device
  logons refresh the seat activity (`app_user.last_auth_at`,
  `AvalynxOfficeEas\Service\LastAuthenticationRecorder`) per the Active Seat
  definition of the AvalynxOffice Public License (APL 1.0); seat accounting
  itself stays in the core.

## Runtime and tooling

- Run Composer/PHPUnit inside a PHP 8.5 container with `ext-sodium`.
  Use the published dev image `jbsnewmedia/avalynxofficeeas-dev`
  (built from `Dockerfile.dev`, includes the dev dependencies) with
  `--user 1000:1000 --entrypoint /bin/bash` so Z-Push's FixFileOwner
  does not throw as root.
- Tests need a reachable PostgreSQL instance; they use the database
  `avalynxofficeeas_test` derived from `DATABASE_URL`:

```bash
docker run --rm --user 1000:1000 --entrypoint /bin/bash --network <db-network> \
  -v "$PWD":/app -w /app \
  -e DATABASE_URL="postgresql://user:pass@db:5432/avalynxofficeeas_test?serverVersion=16" \
  jbsnewmedia/avalynxofficeeas-dev -lc "vendor/bin/phpunit"
```

## Required checks

- PHPUnit: all tests must pass (`vendor/bin/phpunit`).
- PHPStan level max (`composer bin-phpstan`), ECS/PHP-CS-Fixer
  (`composer bin-ecs`, fixes via `composer bin-ecs-fix`) and Rector
  (`composer bin-rector`, fixes via `composer bin-rector-process`) must
  run clean — the whole set is wired as `composer ci` / `composer ci-fix`
  and executed by the `ci` GitHub Actions workflow.
- Keep `src/` strictly typed (`declare(strict_types=1)`, final classes,
  readonly where possible).
- Do not add code that instantiates `App\` classes — the PSR-4 root is
  `AvalynxOfficeEas\` only.
