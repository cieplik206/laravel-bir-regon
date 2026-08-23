<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;

it('uses the environment values expected by gusapi', function (Environment $environment, string $value): void {
    expect($environment->value)->toBe($value);
})->with([
    'production' => [Environment::Production, 'prod'],
    'sandbox' => [Environment::Sandbox, 'dev'],
]);
