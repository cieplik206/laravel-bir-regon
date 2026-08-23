<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class ContactData extends Data
{
    public function __construct(
        public ?string $phoneNumber,
        public ?string $phoneExtension,
        public ?string $faxNumber,
        public ?string $email,
        public ?string $secondaryEmail,
        public ?string $website,
    ) {}
}
