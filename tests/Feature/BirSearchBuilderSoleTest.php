<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirAmbiguousSearchResultException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;
use cieplik206\BirRegon\Tests\Support\StubBirClient;

it('returns the only search result for every singular identifier type with one client call', function (
    Closure $builder,
    array $expectedCall,
): void {
    $company = makeSoleCompanyData();
    $client = new StubBirClient(company: $company);
    $service = new BirRegonService($client);

    $result = $builder($service)->sole();

    expect($result)->toBe($company)
        ->and($client->calls)->toBe([$expectedCall]);
})->with([
    'NIP' => [
        static fn (BirRegonService $service) => $service->forNip('1234567890'),
        ['searchByNip', '1234567890'],
    ],
    'REGON' => [
        static fn (BirRegonService $service) => $service->forRegon('123456789'),
        ['searchByRegon', '123456789'],
    ],
    'KRS' => [
        static fn (BirRegonService $service) => $service->forKrs('0000123456'),
        ['searchByKrs', '0000123456'],
    ],
]);

it('treats same-regon results from different silos as ambiguous without deduplication', function (): void {
    $identifier = '1234567890';
    $firstCompany = makeSoleCompanyData([
        'name' => 'CEIDG activity',
        'silo' => Silo::Ceidg,
    ]);
    $secondCompany = makeSoleCompanyData([
        'name' => 'Agricultural activity',
        'silo' => Silo::Agriculture,
    ]);
    $client = new StubBirClient(companies: [$firstCompany, $secondCompany]);
    $service = new BirRegonService($client);

    try {
        $service->forNip($identifier)->sole();
        throw new LogicException('The ambiguous search did not throw an exception.');
    } catch (BirAmbiguousSearchResultException $exception) {
        $serialized = serialize($exception);
        $restored = unserialize($serialized);

        expect($exception->identifierType)->toBe('NIP')
            ->and($exception->resultCount)->toBe(2)
            ->and($exception->getMessage())->toBe(
                'GUS BIR returned 2 search results for the NIP identifier. Use get() or search() to retrieve every result.',
            )
            ->not->toContain($identifier)
            ->and($exception->getPrevious())->toBeNull()
            ->and(get_object_vars($exception))->not->toHaveKeys(['identifier', 'results'])
            ->and($serialized)->not->toContain($identifier)
            ->and($restored)->toBeInstanceOf(BirAmbiguousSearchResultException::class)
            ->and($restored->identifierType)->toBe('NIP')
            ->and($restored->resultCount)->toBe(2)
            ->and($restored->getMessage())->toBe($exception->getMessage())
            ->and($restored->getPrevious())->toBeNull();
    }

    expect($client->calls)->toBe([['searchByNip', $identifier]]);
});

it('maps an empty result from a custom client to a type-safe not-found exception', function (): void {
    $client = new class extends StubBirClient
    {
        /** @return list<CompanyData> */
        public function searchByNip(#[SensitiveParameter] string $nip): array
        {
            $this->calls[] = ['searchByNip', $nip];

            return [];
        }
    };
    $service = new BirRegonService($client);

    expect(fn () => $service->forNip('1234567890')->sole())
        ->toThrow(
            BirNotFoundException::class,
            'Nie znaleziono podmiotu dla identyfikatora typu NIP.',
        )
        ->and($client->calls)->toBe([['searchByNip', '1234567890']]);
});

it('preserves the native client not-found behavior for an empty gateway response', function (): void {
    $gateway = new FakeBirGateway;
    $service = new BirRegonService(new BirClient($gateway));

    expect(fn () => $service->forKrs('0000123456')->sole())
        ->toThrow(
            BirNotFoundException::class,
            'Nie znaleziono podmiotu dla identyfikatora typu KRS.',
        )
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::krs('0000123456')],
        ]);
});

it('sanitizes unsupported identifier types in ambiguity metadata and messages', function (): void {
    $exception = new BirAmbiguousSearchResultException('secret-identifier-type', 3);

    expect($exception->identifierType)->toBe('UNKNOWN')
        ->and($exception->resultCount)->toBe(3)
        ->and($exception->getMessage())->toBe(
            'GUS BIR returned 3 search results for the UNKNOWN identifier. Use get() or search() to retrieve every result.',
        )
        ->not->toContain('secret-identifier-type')
        ->and($exception->getPrevious())->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeSoleCompanyData(array $overrides = []): CompanyData
{
    return CompanyData::from(array_merge([
        'regon' => '123456789',
        'nip' => '1234567890',
        'name' => 'Test Company',
        'city' => null,
        'postalCode' => null,
        'street' => null,
        'buildingNumber' => null,
        'apartmentNumber' => null,
        'province' => null,
        'district' => null,
        'commune' => null,
        'type' => EntityType::LegalUnit,
        'regon14' => null,
        'nipStatus' => null,
        'silo' => Silo::LegalUnits,
        'activityEndDate' => null,
        'postCity' => null,
    ], $overrides));
}
