<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use Spatie\LaravelData\Data;

final class AddressData extends Data
{
    public function __construct(
        public ?string $countryCode,
        public ?string $countryName,
        public ?string $provinceCode,
        public ?string $provinceName,
        public ?string $districtCode,
        public ?string $districtName,
        public ?string $communeCode,
        public ?string $communeName,
        public ?string $postalCode,
        public ?string $postCityCode,
        public ?string $postCityName,
        public ?string $cityCode,
        public ?string $cityName,
        public ?string $streetCode,
        public ?string $streetName,
        public ?string $buildingNumber,
        public ?string $apartmentNumber,
        public ?string $nonStandardLocation,
    ) {}
}
