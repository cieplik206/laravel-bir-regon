<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum Environment: string
{
    case Production = 'prod';
    case Sandbox = 'dev';
}
