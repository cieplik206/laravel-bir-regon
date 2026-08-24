<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\IdentifierValidationMode;

it('exposes whether the configured mode validates checksums', function (): void {
    expect(IdentifierValidationMode::FormatOnly->validatesChecksum())->toBeFalse()
        ->and(IdentifierValidationMode::FormatAndChecksum->validatesChecksum())->toBeTrue()
        ->and(IdentifierValidationMode::from('format'))->toBe(IdentifierValidationMode::FormatOnly)
        ->and(IdentifierValidationMode::from('checksum'))
        ->toBe(IdentifierValidationMode::FormatAndChecksum);
});
