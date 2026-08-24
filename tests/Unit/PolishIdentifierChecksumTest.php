<?php

declare(strict_types=1);

use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;

it('validates NIP checksums', function (string $nip): void {
    expect(PolishIdentifierChecksum::isValidNip($nip))->toBeTrue();
})->with([
    'GUS sandbox' => '7740001454',
    'leading zero' => '0123456789',
]);

it('rejects malformed and invalid NIP checksums', function (string $nip): void {
    expect(PolishIdentifierChecksum::isValidNip($nip))->toBeFalse();
})->with([
    'too short' => '774000145',
    'non-digit' => '774000145A',
    'wrong check digit' => '7740001455',
    'modulo eleven remainder ten' => '0000000030',
]);

it('validates REGON-9 checksums including a remainder of ten mapped to zero', function (string $regon): void {
    expect(PolishIdentifierChecksum::isValidRegon9($regon))->toBeTrue()
        ->and(PolishIdentifierChecksum::isValidRegon($regon))->toBeTrue();
})->with([
    'GUS sandbox' => '610188201',
    'modulo eleven remainder ten' => '000000030',
]);

it('rejects malformed and invalid REGON-9 checksums', function (string $regon): void {
    expect(PolishIdentifierChecksum::isValidRegon9($regon))->toBeFalse();
})->with([
    'too short' => '61018820',
    'non-digit' => '61018820A',
    'wrong check digit' => '610188202',
]);

it('validates both checksums contained in REGON-14', function (string $regon): void {
    expect(PolishIdentifierChecksum::isValidRegon14($regon))->toBeTrue()
        ->and(PolishIdentifierChecksum::isValidRegon($regon))->toBeTrue();
})->with([
    'ordinary check digit' => '61018820100003',
    'final remainder ten mapped to zero' => '61018820100050',
]);

it('rejects REGON-14 with an invalid parent REGON even when its final checksum is valid', function (): void {
    expect(PolishIdentifierChecksum::isValidRegon14('12345678900004'))->toBeFalse();
});

it('rejects malformed and invalid REGON-14 checksums', function (string $regon): void {
    expect(PolishIdentifierChecksum::isValidRegon14($regon))->toBeFalse();
})->with([
    'too short' => '6101882010000',
    'non-digit' => '6101882010000A',
    'wrong final check digit' => '61018820100004',
]);

it('rejects unsupported REGON lengths through the general entry point', function (): void {
    expect(PolishIdentifierChecksum::isValidRegon('61018820'))->toBeFalse();
});

it('treats all-zero values as checksum-valid without claiming they exist', function (): void {
    expect(PolishIdentifierChecksum::isValidNip('0000000000'))->toBeTrue()
        ->and(PolishIdentifierChecksum::isValidRegon9('000000000'))->toBeTrue()
        ->and(PolishIdentifierChecksum::isValidRegon14('00000000000000'))->toBeTrue();
});

it('offers throwing assertions for consumer validation', function (): void {
    PolishIdentifierChecksum::assertValidNip('7740001454');
    PolishIdentifierChecksum::assertValidRegon('610188201');
    PolishIdentifierChecksum::assertValidRegon9('610188201');
    PolishIdentifierChecksum::assertValidRegon14('61018820100003');

    expect(fn () => PolishIdentifierChecksum::assertValidNip('7740001455'))
        ->toThrow(BirValidationException::class, 'NIP checksum is invalid.')
        ->and(fn () => PolishIdentifierChecksum::assertValidRegon('610188202'))
        ->toThrow(BirValidationException::class, 'REGON checksum is invalid.')
        ->and(fn () => PolishIdentifierChecksum::assertValidRegon9('610188202'))
        ->toThrow(BirValidationException::class, 'REGON-9 checksum is invalid.')
        ->and(fn () => PolishIdentifierChecksum::assertValidRegon14('61018820100004'))
        ->toThrow(BirValidationException::class, 'REGON-14 checksum is invalid.');
});
