[← Documentation](README.md)

# Extending the package

Laravel BIR REGON registers its client, factory, and fluent service as
singletons in Laravel's service container. Applications may replace the public
contracts without changing the facade or application-level API.

## Replacing the client

Create a class that implements `BirClientInterface`, then replace the binding
in the consuming application's service provider:

```php
<?php

namespace App\Providers;

use App\Bir\CustomBirClient;
use cieplik206\BirRegon\BirClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            BirClientInterface::class,
            CustomBirClient::class,
        );
    }
}
```

The replacement must implement searches, reports, service information,
diagnostics, and environment switching declared by the contract.

Register the replacement before `BirRegonService` or the `BirRegon` facade is
first resolved.

## Replacing the GUS API factory

When only construction of the underlying `GusApi` client needs customization,
replace `GusApiFactoryInterface` and keep the package's `BirClient`:

```php
use App\Bir\CustomGusApiFactory;
use cieplik206\BirRegon\GusApiFactoryInterface;

$this->app->singleton(
    GusApiFactoryInterface::class,
    CustomGusApiFactory::class,
);
```

This extension point is suitable for a controlled test double or custom
construction logic. SOAP transport, authentication, and response mapping
otherwise remain encapsulated by the package.

## Facade and dependency injection

Both access styles resolve `BirRegonService` from the same container:

```php
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Facades\BirRegon;

$service = app(BirRegonService::class);
$company = $service->forNip($nip)->get();

$sameApi = BirRegon::forNip($nip)->get();
```

Choose dependency injection for explicit domain dependencies and the facade
for concise Laravel integration code.
