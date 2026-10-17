<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Licensing;

/**
 * Thrown when a device-initiated write operation is blocked because the
 * AvalynxOffice core is in read-only mode.
 */
final class MutationBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
    ) {
        parent::__construct(sprintf('The installation is in read-only mode: the operation "%s" is blocked by the license policy.', $operation));
    }
}
