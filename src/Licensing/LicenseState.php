<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Licensing;

use Doctrine\DBAL\Connection;

/**
 * Read-only mirror of the AvalynxOffice licensing state.
 *
 * The core owns the license_state table and all licensing decisions; the
 * plugin only reads the effective status to mirror the read-only policy for
 * device-initiated writes. Outgoing messages handed over by devices are
 * queued with a license hold when the core is in read-only mode; the core
 * enforces the same policy again before the actual SMTP submission.
 */
final readonly class LicenseState
{
    public function __construct(private Connection $connection)
    {
    }

    public function isReadOnly(): bool
    {
        $status = $this->connection->fetchOne(
            'SELECT last_known_status FROM license_state WHERE id = 1',
        );

        return is_string($status) && 'READ_ONLY' === strtoupper($status);
    }

    /**
     * @throws MutationBlockedException when the installation is read-only
     */
    public function assertMutationAllowed(string $operation): void
    {
        if ($this->isReadOnly()) {
            throw new MutationBlockedException($operation);
        }
    }
}
