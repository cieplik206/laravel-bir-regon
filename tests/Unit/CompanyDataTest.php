<?php

declare(strict_types=1);

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Protocol\SearchResult;

it('maps a native search result to Laravel Data objects', function (): void {
    $searchResult = SearchResult::tryFromRecord([
        'Regon' => '123456789',
        'Nip' => '',
        'Nazwa' => 'Test Company',
        'Wojewodztwo' => 'MAZOWIECKIE',
        'Powiat' => 'Warszawa',
        'Gmina' => 'Warszawa',
        'Miejscowosc' => 'Warszawa',
        'KodPocztowy' => '00-001',
        'Ulica' => 'Testowa',
        'NrNieruchomosci' => '1',
        'NrLokalu' => '',
        'Typ' => 'P',
        'StatusNip' => 'Uchylony',
        'SilosID' => '6',
        'DataZakonczeniaDzialalnosci' => '2025-12-31',
        'MiejscowoscPoczty' => 'Warszawa',
    ]);

    if (! $searchResult instanceof SearchResult) {
        throw new LogicException('Expected the valid search record to be decoded.');
    }

    $company = CompanyData::fromSearchResult($searchResult);
    $untrustedName = '<script>alert(1)</script>\'; DROP TABLE companies; -- =CMD()';
    $rawReportData = [[
        'praw_regon9' => '123456789',
        'praw_nazwa' => $untrustedName,
        'future_field' => 'kept verbatim',
    ]];
    $fullReport = FullCompanyReportData::fromSearchResult(
        $searchResult,
        ReportType::Organization,
        $rawReportData,
    );
    $array = $company->toArray();

    expect($company->regon)->toBe('123456789')
        ->and($company->nip)->toBeNull()
        ->and($company->name)->toBe('Test Company')
        ->and($company->apartmentNumber)->toBeNull()
        ->and($company->type)->toBe(EntityType::LegalUnit)
        ->and($company->regon14)->toBeNull()
        ->and($company->nipStatus)->toBe(NipStatus::Revoked)
        ->and($company->silo)->toBe(Silo::LegalUnits)
        ->and($company->activityEndDate)->toBe('2025-12-31')
        ->and($company->postCity)->toBe('Warszawa')
        ->and($array['postalCode'])->toBe('00-001')
        ->and($array['type'])->toBe('P')
        ->and($array['nipStatus'])->toBe('Uchylony')
        ->and($array['silo'])->toBe(6)
        ->and($fullReport->basicData->regon)->toBe('123456789')
        ->and($fullReport->reportType)->toBe(ReportType::Organization)
        ->and($fullReport->reportData)->toBe($rawReportData)
        ->and($fullReport->normalized->entity?->identity->name)->toBe($untrustedName)
        ->and($fullReport->reportData[0]['future_field'])->toBe('kept verbatim');
});

it('preserves empty and unknown-only raw rows without creating phantom normalized entities', function (): void {
    $searchResult = SearchResult::tryFromRecord([
        'Regon' => '123456789',
        'Typ' => 'P',
        'SilosID' => '6',
    ]);

    expect($searchResult)->toBeInstanceOf(SearchResult::class);

    $rawReportData = [['future_field' => 'kept verbatim']];
    $fullReport = FullCompanyReportData::fromSearchResult(
        $searchResult,
        ReportType::Organization,
        $rawReportData,
    );
    $emptyRawReportData = [[]];
    $emptyFullReport = FullCompanyReportData::fromSearchResult(
        $searchResult,
        ReportType::Organization,
        $emptyRawReportData,
    );

    expect($fullReport->reportData)->toBe($rawReportData)
        ->and($fullReport->normalized->entity)->toBeNull()
        ->and($emptyFullReport->reportData)->toBe($emptyRawReportData)
        ->and($emptyFullReport->normalized->entity)->toBeNull();
});
