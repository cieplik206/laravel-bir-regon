<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\GusApiFactory;
use cieplik206\BirRegon\GusApiFactoryInterface;

it('merges the default package configuration', function (): void {
    expect(config('bir-regon.api_key'))->toBe('')
        ->and(config('bir-regon.environment'))->toBe('prod');
});

it('registers the client and service as singletons', function (): void {
    expect(app(BirClientInterface::class))
        ->toBeInstanceOf(BirClient::class)
        ->toBe(app(BirClientInterface::class))
        ->and(app(BirRegonService::class))
        ->toBeInstanceOf(BirRegonService::class)
        ->toBe(app(BirRegonService::class))
        ->and(app(GusApiFactoryInterface::class))
        ->toBeInstanceOf(GusApiFactory::class)
        ->toBe(app(GusApiFactoryInterface::class));
});
