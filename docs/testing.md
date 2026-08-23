[← Documentation](README.md)

# Testing

The package is tested independently with Pest and Orchestra Testbench. Laravel
12 runs on Testbench 10, while Laravel 13 runs on Testbench 11. The suite does
not require a fresh Laravel application, the PMS application, a database, or
any PMS-specific dependency.

## Install development dependencies

From the package repository:

```bash
composer install
```

## Isolated test suite

Run the default test suite with:

```bash
composer test
```

These tests use local fakes and never perform network requests. The live
`sandbox` test group is explicitly excluded from the default PHPUnit
configuration.

## Code quality

Run static analysis and formatting with:

```bash
composer analyse
composer format
```

Validate the Composer package metadata with:

```bash
composer validate --strict
```

## Compatibility matrix

The isolated suite is designed to cover:

- Laravel 12 on PHP 8.3 with the lowest and highest supported dependencies
- Laravel 12 on PHP 8.4 and 8.5 with the highest supported dependencies
- Laravel 13 on PHP 8.3 with the lowest and highest supported dependencies
- Laravel 13 on PHP 8.4 and 8.5 with the highest supported dependencies

Before a release, also validate Composer metadata, code style, static analysis,
and dependency security advisories. Keep the live GUS sandbox suite separate
from isolated checks because it depends on an external service.

## Verified release workflow

Create releases only from a commit already merged into `main` with a successful
`CI Passed` check. Create and push a signed annotated SemVer tag such as
`v1.2.3`. The protected tag push triggers the `Release` workflow. It verifies
the tag signature, confirms that the tagged commit is reachable from `main`,
and checks the GitHub Actions `CI Passed` result before publishing.

The workflow attaches source archives and `SHA256SUMS` to the GitHub Release.
Repository release immutability freezes the published tag and assets and gives
the release a GitHub provenance attestation. Tag creation and release
publication remain deliberate maintainer operations.

## GUS sandbox tests

The opt-in integration suite sends real requests to the official GUS test
environment:

```bash
composer test:sandbox
```

It verifies:

- public service and authenticated data status
- NIP, REGON, and KRS searches
- batch searches by NIP, KRS, and REGON-9
- a full company report
- session diagnostics after an unsuccessful search

The suite uses the public sandbox key documented by `gusapi/gusapi`. You may
override it for the current command:

```bash
BIR_SANDBOX_API_KEY=your-test-key composer test:sandbox
```

Use a test key only. Never expose a production BIR key in source control, test
output, or a public CI configuration.

The integration suite calls `BirRegon::sandbox()`. It never changes the
production client configuration or sends `BIR_API_KEY` to the test endpoint.

Sandbox tests depend on an external service and mutable test data. They are
useful as an integration check but should not replace the isolated suite.

## Testing consuming applications

Application code can depend on `BirClientInterface` instead of the concrete
client. Replace that binding with a fake in the test application's service
container before resolving `BirRegonService`:

```php
use cieplik206\BirRegon\BirClientInterface;

$this->app->singleton(
    BirClientInterface::class,
    FakeBirClient::class,
);
```

The fake must implement the public client contract. This keeps application
tests deterministic and prevents accidental calls to GUS.

Continue with [Extending the package](extending.md).
