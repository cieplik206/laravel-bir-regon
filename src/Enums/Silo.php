<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum Silo: int
{
    case Ceidg = 1;
    case Agriculture = 2;
    case Other = 3;
    case DeletedBefore20141108 = 4;
    case LegalUnits = 6;
}
