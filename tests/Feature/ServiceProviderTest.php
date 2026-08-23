<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\GusApiFactory;
use cieplik206\BirRegon\GusApiFactoryInterface;
use cieplik206\BirRegon\Tests\Support\FakeGusApi;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;

it('merges the default package configuration', function (): void {
    expect(config('bir-regon.api_key'))->toBe('')
        ->and(config('bir-regon.sandbox_api_key'))->toBe('abcde12345abcde12345');
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

it('builds the sandbox client with its isolated key and endpoint', function (): void {
    config()->set('bir-regon.api_key', 'production-key');
    config()->set('bir-regon.sandbox_api_key', 'sandbox-key');

    $factory = new StubGusApiFactory(new FakeGusApi);
    app()->instance(GusApiFactoryInterface::class, $factory);

    $status = app(BirRegonService::class)->sandbox()->service()->get();

    expect($status->status)->toBe(1)
        ->and($factory->calls)->toBe([
            ['sandbox-key', Environment::Sandbox],
        ]);
});
