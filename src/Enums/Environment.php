<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum Environment: string
{
    case Production = 'prod';
    case Sandbox = 'dev';

    public function endpoint(): string
    {
        return match ($this) {
            self::Production => 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
            self::Sandbox => 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
        };
    }

    public function apiKeyEnvironmentVariable(): string
    {
        return match ($this) {
            self::Production => 'BIR_API_KEY',
            self::Sandbox => 'BIR_SANDBOX_API_KEY',
        };
    }
}
