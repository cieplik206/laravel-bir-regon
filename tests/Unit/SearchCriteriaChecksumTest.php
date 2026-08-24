<?php

declare(strict_types=1);

use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Protocol\SearchCriteria;

it('keeps checksum validation disabled by default for protocol-compatible searches', function (): void {
    expect(SearchCriteria::nip('1234567890')->value)->toBe('1234567890')
        ->and(SearchCriteria::regon('123456789')->value)->toBe('123456789')
        ->and(SearchCriteria::nips(['1234567890'])->value)->toBe('1234567890')
        ->and(SearchCriteria::regons9(['123456789'])->value)->toBe('123456789')
        ->and(SearchCriteria::regons14(['12345678901234'])->value)->toBe('12345678901234');
});

it('accepts checksum-valid single search criteria when validation is requested', function (): void {
    expect(SearchCriteria::nip('7740001454', validateChecksum: true)->value)->toBe('7740001454')
        ->and(SearchCriteria::regon('610188201', validateChecksum: true)->value)->toBe('610188201')
        ->and(SearchCriteria::regon('61018820100003', validateChecksum: true)->value)->toBe('61018820100003');
});

it('rejects checksum-invalid single search criteria when validation is requested', function (Closure $create): void {
    expect($create)->toThrow(BirValidationException::class);
})->with([
    'NIP' => [static fn (): SearchCriteria => SearchCriteria::nip('7740001455', validateChecksum: true)],
    'REGON-9' => [static fn (): SearchCriteria => SearchCriteria::regon('610188202', validateChecksum: true)],
    'REGON-14' => [static fn (): SearchCriteria => SearchCriteria::regon('61018820100004', validateChecksum: true)],
]);

it('accepts checksum-valid batches when validation is requested', function (): void {
    expect(SearchCriteria::nips(['7740001454'], validateChecksum: true)->value)->toBe('7740001454')
        ->and(SearchCriteria::regons9(['610188201'], validateChecksum: true)->value)->toBe('610188201')
        ->and(SearchCriteria::regons14(['61018820100003'], validateChecksum: true)->value)
        ->toBe('61018820100003');
});

it('rejects a checksum-invalid member of an explicitly validated batch', function (Closure $create): void {
    expect($create)->toThrow(BirValidationException::class);
})->with([
    'NIP batch' => [
        static fn (): SearchCriteria => SearchCriteria::nips(
            ['7740001454', '7740001455'],
            validateChecksum: true,
        ),
    ],
    'REGON-9 batch' => [
        static fn (): SearchCriteria => SearchCriteria::regons9(
            ['610188201', '610188202'],
            validateChecksum: true,
        ),
    ],
    'REGON-14 batch' => [
        static fn (): SearchCriteria => SearchCriteria::regons14(
            ['61018820100003', '61018820100004'],
            validateChecksum: true,
        ),
    ],
]);
