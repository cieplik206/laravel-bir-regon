<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

enum TransportFailureType
{
    case Transport;
    case Protocol;
}
