<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

enum EmailAddressType: string
{
    case RECIPIENT = 'recipient';
    case CC = 'cc';
    case SENDER = 'sender';
    case BCC = 'bcc';
    case REPLY_TO = 'reply_to';
}
