<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum Environment: string
{
    case Production = 'prod';
    case Development = 'dev';

    public static function fromConfig(?string $value): self
    {
        $normalized = strtolower((string) $value);

        return match ($normalized) {
            'prod', 'production' => self::Production,
            'dev', 'development' => self::Development,
            default => self::Production,
        };
    }
}
