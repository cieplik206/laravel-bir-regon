<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\EntityType;

it('classifies every entity type into exactly one entity family', function (
    EntityType $type,
    bool $isNaturalPersonFamily,
    bool $isLegalUnitFamily,
): void {
    expect($type->isNaturalPersonFamily())->toBe($isNaturalPersonFamily)
        ->and($type->isLegalUnitFamily())->toBe($isLegalUnitFamily)
        ->and($type->isNaturalPersonFamily() xor $type->isLegalUnitFamily())->toBeTrue();
})->with([
    'natural person' => [EntityType::NaturalPerson, true, false],
    'natural-person local unit' => [EntityType::NaturalPersonLocalUnit, true, false],
    'legal unit' => [EntityType::LegalUnit, false, true],
    'legal-unit local unit' => [EntityType::LegalUnitLocalUnit, false, true],
]);
