<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Dto;

use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Db\Model\User;

/**
 * Resolved EAS login context: the synchronized mailbox and the owning application user.
 */
final readonly class EasMailboxContext
{
    public function __construct(
        private Mailbox $mailbox,
        private User $user,
    ) {
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
