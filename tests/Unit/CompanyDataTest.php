<?php

declare(strict_types=1);

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use GusApi\SearchReport;
use GusApi\Type\Response\SearchResponseCompanyData;

it('maps a GUS search result to Laravel Data objects', function (): void {
    $response = new SearchResponseCompanyData;
    $response->Regon = '123456789';
    $response->Nip = '';
    $response->Nazwa = 'Test Company';
    $response->Wojewodztwo = 'MAZOWIECKIE';
    $response->Powiat = 'Warszawa';
    $response->Gmina = 'Warszawa';
    $response->Miejscowosc = 'Warszawa';
    $response->KodPocztowy = '00-001';
    $response->Ulica = 'Testowa';
    $response->NrNieruchomosci = '1';
    $response->NrLokalu = '';
    $response->Typ = 'P';
    $response->StatusNip = 'Aktywny';
    $response->SilosID = '6';
    $response->DataZakonczeniaDzialalnosci = '2025-12-31';
    $response->MiejscowoscPoczty = 'Warszawa';

    $searchReport = new SearchReport($response);
    $company = CompanyData::fromGusApiResult($searchReport);
    $fullReport = FullCompanyReportData::fromGusApiReport(
        $searchReport,
        [['Nazwa' => 'Test Company']],
    );

    expect($company)
        ->toBeInstanceOf(CompanyData::class)
        ->and($company->regon)->toBe('123456789')
        ->and($company->nip)->toBeNull()
        ->and($company->name)->toBe('Test Company')
        ->and($company->apartmentNumber)->toBeNull()
        ->and($company->type)->toBe('p')
        ->and($company->regon14)->toBe('12345678900000')
        ->and($company->nipStatus)->toBe('Aktywny')
        ->and($company->silo)->toBe(6)
        ->and($company->activityEndDate)->toBe('2025-12-31')
        ->and($company->postCity)->toBe('Warszawa')
        ->and($company->toArray()['postalCode'])->toBe('00-001')
        ->and($fullReport->basicData->regon)->toBe('123456789')
        ->and($fullReport->reportData)->toBe([['Nazwa' => 'Test Company']]);
});
