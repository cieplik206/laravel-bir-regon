<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Protocol\SearchResult;
use Spatie\LaravelData\Data;

class CompanyData extends Data
{
    public function __construct(
        public string $regon,
        public ?string $nip,
        public string $name,
        public ?string $city,
        public ?string $postalCode,
        public ?string $street,
        public ?string $buildingNumber,
        public ?string $apartmentNumber,
        public ?string $province,
        public ?string $district,
        public ?string $commune,
        public EntityType $type,
        public ?string $regon14,
        public ?NipStatus $nipStatus,
        public Silo $silo,
        public ?string $activityEndDate,
        public ?string $postCity,
    ) {}

    public static function fromSearchResult(SearchResult $result): self
    {
        return new self(
            regon: $result->regon,
            nip: $result->nip,
            name: $result->name,
            city: $result->city,
            postalCode: $result->postalCode,
            street: $result->street,
            buildingNumber: $result->buildingNumber,
            apartmentNumber: $result->apartmentNumber,
            province: $result->province,
            district: $result->district,
            commune: $result->commune,
            type: $result->type,
            regon14: $result->regon14,
            nipStatus: $result->nipStatus,
            silo: $result->silo,
            activityEndDate: $result->activityEndDate,
            postCity: $result->postCity,
        );
    }
}
