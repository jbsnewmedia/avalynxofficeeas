<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Dto;

use Symfony\Component\Uid\UuidV7;

/**
 * Decoded "mail|mailboxId|folderId" folder server id.
 */
final readonly class EasServerId
{
    public function __construct(
        private UuidV7 $mailboxId,
        private UuidV7 $folderId,
    ) {
    }

    public function getMailboxId(): UuidV7
    {
        return $this->mailboxId;
    }

    public function getFolderId(): UuidV7
    {
        return $this->folderId;
    }
}
