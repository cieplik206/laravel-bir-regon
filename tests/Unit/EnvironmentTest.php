<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;

it('maps each environment to the official GUS BIR endpoint', function (
    Environment $environment,
    string $value,
    string $endpoint,
): void {
    expect($environment->value)->toBe($value)
        ->and($environment->endpoint())->toBe($endpoint);
})->with([
    'production' => [
        Environment::Production,
        'prod',
        'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
    ],
    'sandbox' => [
        Environment::Sandbox,
        'dev',
        'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
    ],
]);
