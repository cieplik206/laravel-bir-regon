<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum NipStatus: string
{
    case Revoked = 'Uchylony';
    case Invalidated = 'Unieważniony';
}
