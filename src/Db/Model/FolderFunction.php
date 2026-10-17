<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

enum FolderFunction: string
{
    case NONE = 'none';
    case MAILBOX = 'mailbox';
    case INBOX = 'inbox';
    case OUTBOX = 'outbox';
    case DRAFTS = 'drafts';
}
