<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum IdentifierValidationMode: string
{
    case FormatOnly = 'format';
    case FormatAndChecksum = 'checksum';

    public function validatesChecksum(): bool
    {
        return $this === self::FormatAndChecksum;
    }
}
