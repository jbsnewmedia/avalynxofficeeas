<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Dto;

use Symfony\Component\Uid\UuidV7;

/**
 * Decoded "mail|accountId|folderId" folder server id.
 */
final readonly class EasServerId
{
    public function __construct(
        private UuidV7 $accountId,
        private UuidV7 $folderId,
    ) {
    }

    public function getAccountId(): UuidV7
    {
        return $this->accountId;
    }

    public function getFolderId(): UuidV7
    {
        return $this->folderId;
    }
}
