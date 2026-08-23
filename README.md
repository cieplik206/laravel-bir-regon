# Laravel BIR REGON

A fluent Laravel client for the Polish GUS BIR/REGON SOAP API.

**[Read the full documentation →](https://cieplik206.github.io/laravel-bir-regon/)**

Use a Laravel facade or dependency injection to search businesses by NIP,
REGON, or KRS, retrieve full and bulk reports, and inspect the current GUS
service status. Responses are returned as typed
[`spatie/laravel-data`](https://github.com/spatie/laravel-data) objects.

```php
use cieplik206\BirRegon\Facades\BirRegon;

$company = BirRegon::forNip('1234567890')->get();

$company->name;
$company->regon;
$company->toArray();
```

## Features

- Fluent searches by NIP, REGON, and KRS
- Batch searches for up to 20 identifiers
- All full and bulk report types supported by `gusapi/gusapi`
- Separate production and sandbox clients with reusable sessions
- Typed data objects with array and JSON serialization
- Laravel auto-discovery, facade, and container bindings
- Isolated tests plus an opt-in live sandbox suite

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- PHP SOAP and SimpleXML extensions

The test matrix covers Laravel 12 and 13 on PHP 8.3, 8.4, and 8.5. PHP 8.3 is
also verified against the lowest supported dependency versions for each
Laravel release.

## Installation

Install the package via Composer:

```bash
composer require cieplik206/laravel-bir-regon
```

Add your BIR API key to `.env`:

```dotenv
BIR_API_KEY=your-api-key
```

The official public sandbox key is configured by default. Override it only
when GUS provides a different test key:

```dotenv
BIR_SANDBOX_API_KEY=your-test-key
```

Laravel discovers the package service provider automatically. You may
optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=bir-regon-config
```

See the
[installation](https://cieplik206.github.io/laravel-bir-regon/installation.html)
and
[configuration](https://cieplik206.github.io/laravel-bir-regon/configuration.html)
guides for all available options.

## Quick start

Search for a single business with the facade:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$byNip = BirRegon::forNip('1234567890')->get();
$byRegon = BirRegon::forRegon('123456789')->get();
$byKrs = BirRegon::forKrs('0000123456')->get();
```

The same API is available through dependency injection:

```php
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Data\CompanyData;

class FindCompany
{
    public function __construct(
        private BirRegonService $birRegon,
    ) {}

    public function handle(string $nip): CompanyData
    {
        return $this->birRegon->forNip($nip)->get();
    }
}
```

Production is the default. Select the isolated GUS test client before building
a sandbox request:

```php
$company = BirRegon::sandbox()
    ->forNip('7740001454')
    ->get();
```

Production and sandbox keep separate credentials and authenticated sessions.
Multiple builders created from the same service reuse the appropriate session.

## Documentation

The complete documentation is available on the
[documentation website](https://cieplik206.github.io/laravel-bir-regon/):

- [Installation](https://cieplik206.github.io/laravel-bir-regon/installation.html)
- [Configuration](https://cieplik206.github.io/laravel-bir-regon/configuration.html)
- [Basic usage](https://cieplik206.github.io/laravel-bir-regon/basic-usage.html)
- [Batch searches](https://cieplik206.github.io/laravel-bir-regon/batch-searches.html)
- [Full and bulk reports](https://cieplik206.github.io/laravel-bir-regon/reports.html)
- [Data objects](https://cieplik206.github.io/laravel-bir-regon/data-objects.html)
- [Service status and diagnostics](https://cieplik206.github.io/laravel-bir-regon/service-status-and-diagnostics.html)
- [Error handling](https://cieplik206.github.io/laravel-bir-regon/error-handling.html)
- [Testing](https://cieplik206.github.io/laravel-bir-regon/testing.html)
- [Laravel Boost support](https://cieplik206.github.io/laravel-bir-regon/laravel-boost.html)
- [Extending the package](https://cieplik206.github.io/laravel-bir-regon/extending.html)

AI tools can use the
[`llms.txt` documentation index](https://raw.githubusercontent.com/cieplik206/laravel-bir-regon/main/llms.txt).

## Testing

Run the isolated test suite:

```bash
composer test
```

The package also contains an opt-in integration suite that performs real
requests against the GUS test environment:

```bash
composer test:sandbox
```

See the [testing guide](https://cieplik206.github.io/laravel-bir-regon/testing.html)
for details.

## Laravel Boost support

The package ships the `bir-regon-development` skill for
[Laravel Boost](https://laravel.com/docs/13.x/boost). It teaches supported AI
agents the fluent query API, report workflow, exception hierarchy, and sandbox
testing conventions used by this package.

Install and configure Boost in the consuming Laravel application:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

If Boost was already installed before Laravel BIR REGON, discover the new
package skill with:

```bash
php artisan boost:update --discover
```

Boost detects skills shipped by Composer packages and offers to install them
for the AI agents configured in the application. The integration is optional:
Laravel BIR REGON does not require Boost at runtime.

See the
[Laravel Boost support guide](https://cieplik206.github.io/laravel-bir-regon/laravel-boost.html)
for details.

## Changelog

Please see the [changelog](CHANGELOG.md) for information about recent changes.

## Contributing

Please see [Contributing](CONTRIBUTING.md) for details.

## Security

Please review the [security policy](SECURITY.md) to report vulnerabilities.

## Credits

- [`gusapi/gusapi`](https://github.com/johnzuk/GusApi) for SOAP communication
- [`spatie/laravel-data`](https://github.com/spatie/laravel-data) for typed data objects

## License

The MIT License. Please see the [license file](LICENSE.md) for more information.
Third-party dependencies retain their respective licenses; see the
[third-party notices](THIRD_PARTY_NOTICES.md).
