<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\Silo;

final readonly class SearchResult
{
    public function __construct(
        #[\SensitiveParameter] public string $regon,
        #[\SensitiveParameter] public ?string $nip,
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
        #[\SensitiveParameter] public ?string $regon14,
        public ?NipStatus $nipStatus,
        public Silo $silo,
        public ?string $activityEndDate,
        public ?string $postCity,
    ) {}

    /** @param array<string, string> $record */
    public static function tryFromRecord(#[\SensitiveParameter] array $record): ?self
    {
        $regon = $record['Regon'] ?? '';

        if ($regon === '' || ! preg_match('/^(?:\d{9}|\d{14})$/D', $regon)) {
            return null;
        }

        $nip = self::nullIfEmpty($record['Nip'] ?? null);
        $type = EntityType::tryFrom($record['Typ'] ?? '');
        $silo = self::siloFromRecord($record['SilosID'] ?? '');
        $rawNipStatus = self::nullIfEmpty($record['StatusNip'] ?? null);
        $nipStatus = $rawNipStatus === null ? null : NipStatus::tryFrom($rawNipStatus);
        $activityEndDate = self::nullIfEmpty($record['DataZakonczeniaDzialalnosci'] ?? null);

        if ($activityEndDate !== null) {
            $activityEndDate = XsDate::normalize($activityEndDate);
        }

        if (
            ($nip !== null && preg_match('/^\d{10}$/D', $nip) !== 1)
            || $type === null
            || $silo === null
            || ($rawNipStatus !== null && $nipStatus === null)
            || ! XsDate::isValid($activityEndDate)
        ) {
            return null;
        }

        $result = new self(
            regon: $regon,
            nip: $nip,
            name: $record['Nazwa'] ?? '',
            city: self::nullIfEmpty($record['Miejscowosc'] ?? null),
            postalCode: self::nullIfEmpty($record['KodPocztowy'] ?? null),
            street: self::nullIfEmpty($record['Ulica'] ?? null),
            buildingNumber: self::nullIfEmpty($record['NrNieruchomosci'] ?? null),
            apartmentNumber: self::nullIfEmpty($record['NrLokalu'] ?? null),
            province: self::nullIfEmpty($record['Wojewodztwo'] ?? null),
            district: self::nullIfEmpty($record['Powiat'] ?? null),
            commune: self::nullIfEmpty($record['Gmina'] ?? null),
            type: $type,
            regon14: strlen($regon) === 14 ? $regon : null,
            nipStatus: $nipStatus,
            silo: $silo,
            activityEndDate: $activityEndDate,
            postCity: self::nullIfEmpty($record['MiejscowoscPoczty'] ?? null),
        );

        return $result->hasConsistentClassification() ? $result : null;
    }

    private static function siloFromRecord(string $value): ?Silo
    {
        $silo = XsInteger::toNonNegativeInt($value);

        if ($silo === null) {
            return null;
        }

        return Silo::tryFrom($silo);
    }

    public function hasConsistentClassification(): bool
    {
        $hasExpectedRegonLength = match ($this->type) {
            EntityType::LegalUnit,
            EntityType::NaturalPerson => strlen($this->regon) === 9,
            EntityType::LegalUnitLocalUnit,
            EntityType::NaturalPersonLocalUnit => strlen($this->regon) === 14,
        };

        $hasExpectedSilo = match ($this->type) {
            EntityType::LegalUnit,
            EntityType::LegalUnitLocalUnit => $this->silo === Silo::LegalUnits,
            EntityType::NaturalPerson,
            EntityType::NaturalPersonLocalUnit => $this->silo !== Silo::LegalUnits,
        };

        return $hasExpectedRegonLength && $hasExpectedSilo;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
