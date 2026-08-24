<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum EntityType: string
{
    case LegalUnit = 'P';
    case NaturalPerson = 'F';
    case LegalUnitLocalUnit = 'LP';
    case NaturalPersonLocalUnit = 'LF';

    public function isNaturalPersonFamily(): bool
    {
        return match ($this) {
            self::NaturalPerson,
            self::NaturalPersonLocalUnit => true,
            self::LegalUnit,
            self::LegalUnitLocalUnit => false,
        };
    }

    public function isLegalUnitFamily(): bool
    {
        return match ($this) {
            self::LegalUnit,
            self::LegalUnitLocalUnit => true,
            self::NaturalPerson,
            self::NaturalPersonLocalUnit => false,
        };
    }
}
