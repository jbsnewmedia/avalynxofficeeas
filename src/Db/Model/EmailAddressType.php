<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

enum EmailAddressType: string
{
    case RECIPIENT = 'recipient';
    case CC = 'cc';
    case SENDER = 'sender';
    case BCC = 'bcc';
    case REPLY_TO = 'reply_to';
}
