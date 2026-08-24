<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum ActivityStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Unknown = 'unknown';
}
