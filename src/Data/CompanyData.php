<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data;

use GusApi\SearchReport;
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
        public ?string $type,
        public ?string $regon14 = null,
        public ?string $nipStatus = null,
        public int $silo = 0,
        public ?string $activityEndDate = null,
        public ?string $postCity = null,
    ) {}

    public static function fromGusApiResult(SearchReport $result): self
    {
        return new self(
            regon: $result->getRegon(),
            nip: self::nullIfEmpty($result->getNip()),
            name: $result->getName(),
            city: self::nullIfEmpty($result->getCity()),
            postalCode: self::nullIfEmpty($result->getZipCode()),
            street: self::nullIfEmpty($result->getStreet()),
            buildingNumber: self::nullIfEmpty($result->getPropertyNumber()),
            apartmentNumber: self::nullIfEmpty($result->getApartmentNumber()),
            province: self::nullIfEmpty($result->getProvince()),
            district: self::nullIfEmpty($result->getDistrict()),
            commune: self::nullIfEmpty($result->getCommunity()),
            type: self::nullIfEmpty($result->getType()),
            regon14: self::nullIfEmpty($result->getRegon14()),
            nipStatus: self::nullIfEmpty($result->getNipStatus()),
            silo: $result->getSilo(),
            activityEndDate: self::nullIfEmpty($result->getActivityEndDate()),
            postCity: self::nullIfEmpty($result->getPostCity()),
        );
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
