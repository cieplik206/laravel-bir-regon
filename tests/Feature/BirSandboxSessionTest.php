<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Tests\Support\FakeGusApi;
use cieplik206\BirRegon\Tests\Support\StubBirClient;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\SearchReport;
use GusApi\Type\Response\SearchResponseCompanyData;

it('reuses one authenticated client session across sandbox builders', function (): void {
    $api = new FakeGusApi(searchReports: [makeSandboxSessionSearchReport()]);
    $factory = new StubGusApiFactory($api);
    $sandboxClient = new BirClient($factory, 'sandbox-key', Environment::Sandbox);
    $service = new BirRegonService(new StubBirClient, $sandboxClient);

    $firstResult = $service->sandbox()->forNip('1111111111')->get();
    $secondResult = $service->sandbox()->forNip('2222222222')->get();

    expect($firstResult->regon)->toBe('123456789')
        ->and($secondResult->regon)->toBe('123456789')
        ->and($factory->calls)->toBe([
            ['sandbox-key', Environment::Sandbox],
        ])
        ->and($api->calls)->toBe([
            ['login'],
            ['getByNip', '1111111111'],
            ['getByNip', '2222222222'],
        ]);
});

function makeSandboxSessionSearchReport(): SearchReport
{
    $response = new SearchResponseCompanyData;
    $response->Regon = '123456789';
    $response->Nip = '1111111111';
    $response->Nazwa = 'Test Company';
    $response->Typ = 'P';

    return new SearchReport($response);
}
