<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Protocol\SearchResult;

it('exposes the exact full report identifiers from the official BIR 1.2 specification', function (): void {
    $actual = [];

    foreach (ReportType::cases() as $reportType) {
        $actual[$reportType->name] = $reportType->value;
    }

    expect($actual)->toBe([
        'NaturalPerson' => 'BIR12OsFizycznaDaneOgolne',
        'NaturalPersonCeidg' => 'BIR12OsFizycznaDzialalnoscCeidg',
        'NaturalPersonAgro' => 'BIR12OsFizycznaDzialalnoscRolnicza',
        'NaturalPersonOther' => 'BIR12OsFizycznaDzialalnoscPozostala',
        'NaturalPersonDeletedBefore20141108' => 'BIR12OsFizycznaDzialalnoscSkreslonaDo20141108',
        'NaturalPersonLocals' => 'BIR12OsFizycznaListaJednLokalnych',
        'NaturalPersonLocal' => 'BIR12JednLokalnaOsFizycznej',
        'NaturalPersonActivity' => 'BIR12OsFizycznaPkd',
        'NaturalPersonLocalActivity' => 'BIR12JednLokalnaOsFizycznejPkd',
        'Organization' => 'BIR12OsPrawna',
        'OrganizationActivity' => 'BIR12OsPrawnaPkd',
        'OrganizationLocals' => 'BIR12OsPrawnaListaJednLokalnych',
        'OrganizationLocal' => 'BIR12JednLokalnaOsPrawnej',
        'OrganizationLocalWithNip' => 'BIR121JednLokalnaOsPrawnej',
        'OrganizationLocalActivity' => 'BIR12JednLokalnaOsPrawnejPkd',
        'OrganizationPartners' => 'BIR12OsPrawnaSpCywilnaWspolnicy',
        'UnitType' => 'BIR12TypPodmiotu',
    ]);
});

it('exposes the exact bulk report identifiers from the official BIR 1.2 specification', function (): void {
    $actual = [];

    foreach (BulkReportType::cases() as $reportType) {
        $actual[$reportType->name] = $reportType->value;
    }

    expect($actual)->toBe([
        'NewLegalEntitiesAndNaturalPersons' => 'BIR11NowePodmiotyPrawneOrazDzialalnosciOsFizycznych',
        'UpdatedLegalEntitiesAndNaturalPersons' => 'BIR11AktualizowanePodmiotyPrawneOrazDzialalnosciOsFizycznych',
        'DeletedLegalEntitiesAndNaturalPersons' => 'BIR11SkreslonePodmiotyPrawneOrazDzialalnosciOsFizycznych',
        'NewLocalUnits' => 'BIR11NoweJednostkiLokalne',
        'UpdatedLocalUnits' => 'BIR11AktualizowaneJednostkiLokalne',
        'DeletedLocalUnits' => 'BIR11SkresloneJednostkiLokalne',
    ]);
});

it('accepts and rejects entity combinations according to the full report matrix', function (
    ReportType $reportType,
    EntityType $supportedType,
    Silo $supportedSilo,
    string $supportedRegon,
    EntityType $unsupportedType,
    Silo $unsupportedSilo,
    string $unsupportedRegon,
): void {
    expect($reportType->supports(reportTypeSearchResult(
        $supportedType,
        $supportedSilo,
        $supportedRegon,
    )))->toBeTrue()
        ->and($reportType->supports(reportTypeSearchResult(
            $unsupportedType,
            $unsupportedSilo,
            $unsupportedRegon,
        )))->toBeFalse();
})->with([
    'natural person' => [ReportType::NaturalPerson, EntityType::NaturalPerson, Silo::Ceidg, '012345678', EntityType::NaturalPerson, Silo::DeletedBefore20141108, '012345678'],
    'natural person CEIDG' => [ReportType::NaturalPersonCeidg, EntityType::NaturalPerson, Silo::Ceidg, '012345678', EntityType::NaturalPerson, Silo::Agriculture, '012345678'],
    'natural person agricultural activity' => [ReportType::NaturalPersonAgro, EntityType::NaturalPerson, Silo::Agriculture, '012345678', EntityType::NaturalPerson, Silo::Ceidg, '012345678'],
    'natural person other activity' => [ReportType::NaturalPersonOther, EntityType::NaturalPerson, Silo::Other, '012345678', EntityType::NaturalPerson, Silo::Agriculture, '012345678'],
    'natural person deleted before 2014-11-08' => [ReportType::NaturalPersonDeletedBefore20141108, EntityType::NaturalPerson, Silo::DeletedBefore20141108, '012345678', EntityType::NaturalPerson, Silo::Other, '012345678'],
    'natural person local-unit list' => [ReportType::NaturalPersonLocals, EntityType::NaturalPerson, Silo::Agriculture, '012345678', EntityType::NaturalPersonLocalUnit, Silo::Agriculture, '01234567800001'],
    'natural person local unit' => [ReportType::NaturalPersonLocal, EntityType::NaturalPersonLocalUnit, Silo::Ceidg, '01234567800001', EntityType::NaturalPerson, Silo::Ceidg, '012345678'],
    'natural person activity' => [ReportType::NaturalPersonActivity, EntityType::NaturalPerson, Silo::Other, '012345678', EntityType::NaturalPerson, Silo::DeletedBefore20141108, '012345678'],
    'natural person local-unit activity' => [ReportType::NaturalPersonLocalActivity, EntityType::NaturalPersonLocalUnit, Silo::Other, '01234567800001', EntityType::NaturalPersonLocalUnit, Silo::DeletedBefore20141108, '01234567800001'],
    'organization' => [ReportType::Organization, EntityType::LegalUnit, Silo::LegalUnits, '012345678', EntityType::LegalUnit, Silo::Other, '012345678'],
    'organization activity' => [ReportType::OrganizationActivity, EntityType::LegalUnit, Silo::LegalUnits, '012345678', EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001'],
    'organization local-unit list' => [ReportType::OrganizationLocals, EntityType::LegalUnit, Silo::LegalUnits, '012345678', EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001'],
    'organization local unit' => [ReportType::OrganizationLocal, EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001', EntityType::LegalUnit, Silo::LegalUnits, '012345678'],
    'organization local unit with NIP' => [ReportType::OrganizationLocalWithNip, EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001', EntityType::LegalUnitLocalUnit, Silo::Other, '01234567800001'],
    'organization local-unit activity' => [ReportType::OrganizationLocalActivity, EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001', EntityType::LegalUnit, Silo::LegalUnits, '012345678'],
    'organization partners' => [ReportType::OrganizationPartners, EntityType::LegalUnit, Silo::LegalUnits, '012345678', EntityType::LegalUnit, Silo::Other, '012345678'],
    'unit type' => [ReportType::UnitType, EntityType::LegalUnit, Silo::LegalUnits, '012345678', EntityType::LegalUnit, Silo::LegalUnits, '0123456780'],
]);

it('supports the unit-type report for every documented entity type and REGON length', function (
    EntityType $type,
    Silo $silo,
    string $regon,
): void {
    expect(ReportType::UnitType->supports(reportTypeSearchResult(
        $type,
        $silo,
        $regon,
    )))->toBeTrue();
})->with([
    'F with REGON9' => [EntityType::NaturalPerson, Silo::Ceidg, '012345678'],
    'LF with REGON14' => [EntityType::NaturalPersonLocalUnit, Silo::Agriculture, '01234567800001'],
    'P with REGON9' => [EntityType::LegalUnit, Silo::LegalUnits, '012345678'],
    'LP with REGON14' => [EntityType::LegalUnitLocalUnit, Silo::LegalUnits, '01234567800001'],
]);

it('rejects the unit-type report for an unsupported REGON length', function (): void {
    expect(ReportType::UnitType->supports(reportTypeSearchResult(
        EntityType::LegalUnit,
        Silo::LegalUnits,
        '0123456780',
    )))->toBeFalse();
});

it('rejects the unit-type report for an inconsistent search classification', function (
    EntityType $type,
    Silo $silo,
    string $regon,
): void {
    expect(ReportType::UnitType->supports(reportTypeSearchResult(
        $type,
        $silo,
        $regon,
    )))->toBeFalse();
})->with([
    'legal unit in a natural-person silo' => [
        EntityType::LegalUnit,
        Silo::Ceidg,
        '012345678',
    ],
    'local legal unit with REGON9' => [
        EntityType::LegalUnitLocalUnit,
        Silo::LegalUnits,
        '012345678',
    ],
]);

it('passes through the actual search REGON and exposes the report REGON length requirement', function (
    ReportType $reportType,
    bool $requiresRegon14,
): void {
    $result = reportTypeSearchResult(
        EntityType::LegalUnit,
        Silo::LegalUnits,
        '012345678',
    );

    expect($reportType->requiresRegon14())->toBe($requiresRegon14)
        ->and($reportType->reportRegon($result))->toBe('012345678')
        ->and($result->regon14)->toBeNull();
})->with([
    'natural person' => [ReportType::NaturalPerson, false],
    'natural person CEIDG' => [ReportType::NaturalPersonCeidg, false],
    'natural person agricultural activity' => [ReportType::NaturalPersonAgro, false],
    'natural person other activity' => [ReportType::NaturalPersonOther, false],
    'natural person deleted before 2014-11-08' => [ReportType::NaturalPersonDeletedBefore20141108, false],
    'natural person local-unit list' => [ReportType::NaturalPersonLocals, false],
    'natural person local unit' => [ReportType::NaturalPersonLocal, true],
    'natural person activity' => [ReportType::NaturalPersonActivity, false],
    'natural person local-unit activity' => [ReportType::NaturalPersonLocalActivity, true],
    'organization' => [ReportType::Organization, false],
    'organization activity' => [ReportType::OrganizationActivity, false],
    'organization local-unit list' => [ReportType::OrganizationLocals, false],
    'organization local unit' => [ReportType::OrganizationLocal, true],
    'organization local unit with NIP' => [ReportType::OrganizationLocalWithNip, true],
    'organization local-unit activity' => [ReportType::OrganizationLocalActivity, true],
    'organization partners' => [ReportType::OrganizationPartners, false],
    'unit type' => [ReportType::UnitType, false],
]);

function reportTypeSearchResult(
    EntityType $type,
    Silo $silo,
    string $regon,
): SearchResult {
    return new SearchResult(
        regon: $regon,
        nip: '0123456789',
        name: 'Fixture entity',
        city: null,
        postalCode: null,
        street: null,
        buildingNumber: null,
        apartmentNumber: null,
        province: null,
        district: null,
        commune: null,
        type: $type,
        regon14: strlen($regon) === 14 ? $regon : null,
        nipStatus: null,
        silo: $silo,
        activityEndDate: null,
        postCity: null,
    );
}
