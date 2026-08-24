# Laravel BIR REGON

A fluent Laravel client for the Polish GUS BIR/REGON SOAP API.

**[Read the full documentation →](https://cieplik206.github.io/laravel-bir-regon/)**

Use a Laravel facade or dependency injection to search businesses by NIP,
REGON, or KRS, retrieve full and bulk reports, and inspect the current GUS
service status. Search items and report payloads are returned as typed
[`spatie/laravel-data`](https://github.com/spatie/laravel-data) objects.

```php
use cieplik206\BirRegon\Facades\BirRegon;

$companies = BirRegon::forNip('1234567890')->get();

foreach ($companies as $company) {
    $company->name;
    $company->regon;
}
```

## Features

- Fluent searches by NIP, REGON, and KRS without discarding additional GUS
  silo results
- Batch searches for up to 20 identifiers
- Native bounded cURL transport with SOAP 1.2 and WS-Addressing for the official
  GUS BIR 1.2 API
- All 17 full report types, including BIR121, and all 6 bulk report types
- Separate production and sandbox clients with reusable sessions and HTTP
  connections
- Strict response enums and normalized full-report DTOs, with the original GUS
  rows retained
- Cache-backed enforcement of the official GUS request limits
- Credential-safe translation of GUS and transport exceptions
- Credential-free serialization tombstones for clients and request builders
- Laravel auto-discovery, facade, and container bindings
- Isolated tests plus an opt-in live sandbox suite

## Requirements

- PHP 8.4 or newer
- Laravel 13
- PHP cURL, DOM, and libxml extensions

The test matrix covers Laravel 13 on PHP 8.4 and 8.5 with Pest 5 and
`pest-plugin-phpstan`. PHP 8.4 is verified against both the lowest and highest
resolvable test toolchains, and a separate runtime check resolves the package
against PHP 8.4.0 and Illuminate 13.0. Applications that still require PHP 8.3
or Laravel 12 should remain on the 1.x package line.

## Installation

Install the package via Composer:

```bash
composer require cieplik206/laravel-bir-regon
```

Add your BIR API key to `.env`:

```dotenv
BIR_API_KEY=your-api-key
```

To reject NIP and REGON values with invalid Polish checksums before any GUS
request, opt in explicitly:

```dotenv
BIR_IDENTIFIER_VALIDATION=checksum
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

Version 2 is intentionally breaking: singular identifier searches now return
collections, closed GUS fields use strict enums, full reports include a typed
normalized DTO alongside their raw rows, and Laravel enables a shared-cache
request limiter by default. Direct `BirClient` construction and the old
GusApi-specific extension points also changed. See
[UPGRADE-2.0.md](UPGRADE-2.0.md) before upgrading from 1.x.

See the
[installation](https://cieplik206.github.io/laravel-bir-regon/installation.html)
and
[configuration](https://cieplik206.github.io/laravel-bir-regon/configuration.html)
guides for all available options.

## Quick start

Search by one identifier with the facade:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$byNip = BirRegon::forNip('1234567890')->get();   // Collection<CompanyData>
$byRegon = BirRegon::forRegon('123456789')->get();
$byKrs = BirRegon::forKrs('0000123456')->get();
```

Even a single NIP, REGON, or KRS can identify more than one GUS record, for
example activity recorded in separate silos. `get()` and `search()` therefore
return every result as an `Illuminate\Support\Collection`. Use `sole()` only
when the application's domain requires exactly one result.

The same API is available through dependency injection:

```php
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Data\CompanyData;
use Illuminate\Support\Collection;

class FindCompany
{
    public function __construct(
        private BirRegonService $birRegon,
    ) {}

    /** @return Collection<int, CompanyData> */
    public function handle(string $nip): Collection
    {
        return $this->birRegon->forNip($nip)->get();
    }
}
```

Search response fields with a closed GUS vocabulary use `EntityType`, `Silo`,
and nullable `NipStatus` enums. `regon14` remains `null` unless GUS actually
returns a 14-digit REGON; the package never derives it by padding another
identifier.

Checksum validation is intentionally optional because it is stricter than the
GUS request contract. Laravel defaults to `BIR_IDENTIFIER_VALIDATION=format`,
which checks exact digit lengths only. Set it to `checksum` to apply the
existing NIP and REGON checksum algorithms automatically to production and
sandbox searches, batches, and report lookups:

```dotenv
BIR_IDENTIFIER_VALIDATION=checksum
```

KRS has no checksum algorithm and remains a strict 10-digit format check in
both modes. A valid checksum does not prove that an identifier exists or that
an entity is active. The client accepts undecorated digit strings and does not
remove `PL`, spaces, or dashes.

Production is the default. Select the isolated GUS test client before building
a sandbox request:

```php
$companies = BirRegon::sandbox()
    ->forNip('7740001454')
    ->get();
```

Production and sandbox keep separate credentials and authenticated sessions.
Multiple builders created from the same service reuse the appropriate session.
Each isolated client also reuses its cURL connection, DNS cache, and TLS session
while clearing request bodies and SID headers between calls.

The Laravel integration enables a cache-backed request limiter by default.
External acquisitions pace second-level debt for no more than one second.
Inside one search/report recovery scope, each internal acquisition after the
first reservation may pace for up to seven seconds; minute and hour exhaustion
always fails fast. `BirRateLimitException::retryAfterSeconds()` can drive queue
backoff. Multi-host applications should use one shared Redis store. See the
[rate-limit guide](https://cieplik206.github.io/laravel-bir-regon/rate-limits.html).

Explicit logout is optional, but available when an application wants to end the
current GUS session before its container scope ends:

```php
BirRegon::logout();
BirRegon::sandbox()->logout();
```

Production and sandbox logout independently. The local SID is always cleared,
including when GUS returns an error.

Queued jobs should store only operation inputs such as a NIP, REGON, or KRS,
then resolve `BirRegonService` in `handle()`. Do not store `BirClient`,
`BirRegonService`, or a request builder on a job. Their serialized form
intentionally omits all state, including credentials. Deserialization produces
an inert tombstone that throws `LogicException` when used.
See [error handling](https://cieplik206.github.io/laravel-bir-regon/error-handling.html#queued-jobs-and-serialization)
for an example.

Every public string received from GUS is untrusted, including `CompanyData`,
raw and normalized report data, `DiagnosticsData::$message`, and
`ServiceStatusData::$message`. Mapping does not sanitize it. Escape HTML, bind
SQL values, allowlist `http`/`https` links, validate `mailto:` addresses,
neutralize CSV/XLSX formula prefixes, and normalize control characters before
logging to prevent log forging. See
[Data objects](https://cieplik206.github.io/laravel-bir-regon/data-objects.html#untrusted-gus-data).

## Documentation

The complete documentation is available on the
[documentation website](https://cieplik206.github.io/laravel-bir-regon/):

- [Installation](https://cieplik206.github.io/laravel-bir-regon/installation.html)
- [Configuration](https://cieplik206.github.io/laravel-bir-regon/configuration.html)
- [Basic usage](https://cieplik206.github.io/laravel-bir-regon/basic-usage.html)
- [Batch searches](https://cieplik206.github.io/laravel-bir-regon/batch-searches.html)
- [Full and bulk reports](https://cieplik206.github.io/laravel-bir-regon/reports.html)
- [Data objects](https://cieplik206.github.io/laravel-bir-regon/data-objects.html)
- [Request limits](https://cieplik206.github.io/laravel-bir-regon/rate-limits.html)
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

- [Statistics Poland (GUS)](https://api.stat.gov.pl/Home/RegonApi) for the BIR
  API, documentation, and public sandbox
- [`spatie/laravel-data`](https://github.com/spatie/laravel-data) for typed data objects

## License

The MIT License. Please see the [license file](LICENSE.md) for more information.
Third-party dependencies retain their respective licenses; see the
[third-party notices](THIRD_PARTY_NOTICES.md).
