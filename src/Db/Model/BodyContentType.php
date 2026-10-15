<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

enum BodyContentType: string
{
    case TEXT = 'text';
    case HTML = 'html';
}
