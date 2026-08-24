<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum EntityType: string
{
    case LegalUnit = 'P';
    case NaturalPerson = 'F';
    case LegalUnitLocalUnit = 'LP';
    case NaturalPersonLocalUnit = 'LF';
}
