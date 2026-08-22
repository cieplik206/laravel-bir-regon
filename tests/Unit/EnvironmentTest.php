<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;

it('maps configuration values to an environment', function (?string $value, Environment $expected): void {
    expect(Environment::fromConfig($value))->toBe($expected);
})->with([
    'production short name' => ['prod', Environment::Production],
    'production full name' => ['production', Environment::Production],
    'development short name' => ['dev', Environment::Development],
    'development full name' => ['development', Environment::Development],
    'unknown value' => ['unknown', Environment::Production],
    'missing value' => [null, Environment::Production],
]);
