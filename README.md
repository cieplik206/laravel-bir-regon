# Laravel BIR REGON

A fluent Laravel client for the Polish GUS BIR/REGON SOAP API.

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
- Production and GUS test-environment support
- Typed data objects with array and JSON serialization
- Laravel auto-discovery, facade, and container bindings
- Isolated tests plus an opt-in live sandbox suite

## Requirements

- PHP 8.3 or newer
- Laravel 13
- PHP SOAP and SimpleXML extensions

## Installation

Install the package via Composer:

```bash
composer require cieplik206/laravel-bir-regon
```

Add your BIR API key to `.env`:

```dotenv
BIR_API_KEY=your-api-key
BIR_ENVIRONMENT=prod
```

Laravel discovers the package service provider automatically. You may
optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=bir-regon-config
```

See the [installation](docs/installation.md) and
[configuration](docs/configuration.md) guides for all available options.

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

Every request builder can explicitly select the production or test endpoint:

```php
$company = BirRegon::forNip('1234567890')
    ->inDev()
    ->get();
```

## Documentation

The complete documentation is available in the [`docs`](docs/README.md)
directory:

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Basic usage](docs/basic-usage.md)
- [Batch searches](docs/batch-searches.md)
- [Full and bulk reports](docs/reports.md)
- [Data objects](docs/data-objects.md)
- [Service status and diagnostics](docs/service-status-and-diagnostics.md)
- [Error handling](docs/error-handling.md)
- [Testing](docs/testing.md)
- [Extending the package](docs/extending.md)

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

See [Testing](docs/testing.md) for details.

## AI-assisted development

The package ships a Laravel Boost skill with its fluent API conventions,
report workflow, exception hierarchy, and sandbox guidance. Applications using
Laravel Boost can discover it after installing the package:

```bash
php artisan boost:update --discover
```

Third-party package skill discovery requires Laravel Boost 2.2 or newer. The
skill is optional and does not add Laravel Boost as a package dependency.

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
