<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

enum FolderFunction: string
{
    case NONE = 'none';
    case MAILBOX = 'mailbox';
    case INBOX = 'inbox';
    case OUTBOX = 'outbox';
    case DRAFTS = 'drafts';
}
