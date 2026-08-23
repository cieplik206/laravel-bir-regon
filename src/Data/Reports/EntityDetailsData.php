<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use cieplik206\BirRegon\Enums\Silo;
use Spatie\LaravelData\Data;

final class EntityDetailsData extends Data
{
    public function __construct(
        public EntityIdentityData $identity,
        public ?AddressData $address,
        public ?ContactData $contact,
        public ?LegalFormData $legalForm,
        public ?RegistryData $registry,
        public ?ActivityLifecycleData $lifecycle,
        public ?NaturalPersonActivityKindsData $activityKinds,
        public ?Silo $silo,
        public ?string $siloSymbol,
        public ?string $siloName,
        public ?int $localUnitCount,
    ) {}
}
