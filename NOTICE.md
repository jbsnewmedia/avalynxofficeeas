# NOTICE — avalynxofficeeas

**avalynxofficeeas** — ActiveSync extension for **AvalynxOffice**
(core repository: `avalynxoffice`)
Copyright (C) 2026 JBS New Media GmbH

This program is licensed under the **GNU Affero General Public License
version 3** (see `LICENSE`). It is a separate, independently installable
extension: it runs as its own process, uses its own database models against
the AvalynxOffice database schema and contains no AvalynxOffice core code.

Portions of this project (database access patterns, folder/message/contact/
calendar handling and the Z-Push backend bridge) were ported from the
AvalynxOffice core and are relicensed here under AGPL-3.0 by the copyright
holder (JBS New Media GmbH), who is the author of both projects.

## Contains Z-Push (AGPL-3.0)

This project depends on **Z-Push 2.7.6**
(https://github.com/Z-Hub/Z-Push), licensed under the GNU Affero General
Public License v3, installed as the Composer package
`z-push/z-push-dev` (published on Packagist by the Z-Push maintainers).

> Z-Push is Copyright by the Z-Push contributors (Z-Hub project).
> License: GNU Affero General Public License v3.0

The package ships without an autoload definition; this project provides the
classmap/files entries in its own `composer.json`. Since Z-Push is AGPL-3.0
and its complete source is distributed with every release of this project
(in `vendor/` inside the Docker image), the license obligations are
fulfilled.

**Trademark notice:** "Z-Push" is a trademark of its respective owners. It is
used here solely for attribution and description as required by the license;
this project is not an official Z-Push release and is not endorsed by the
Z-Push project.

## Further dependencies

| Component | License |
|---|---|
| Doctrine DBAL | MIT |
| Monolog | MIT |
| PHPUnit (dev) | BSD-3-Clause |

These components keep their own licenses and copyrights.
