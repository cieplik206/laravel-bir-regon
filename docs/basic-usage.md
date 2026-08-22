[← Documentation](README.md)

# Basic usage

The package exposes the same fluent API through the `BirRegon` facade and the
`BirRegonService` container binding.

## Searching with the facade

Search by NIP, REGON, or KRS:

```php
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Facades\BirRegon;

/** @var CompanyData $byNip */
$byNip = BirRegon::forNip('1234567890')->get();

$byRegon = BirRegon::forRegon('123456789')->get();
$byKrs = BirRegon::forKrs('0000123456')->get();
```

`get()` and `search()` are aliases:

```php
$company = BirRegon::forNip('1234567890')->search();
```

Each search returns a `CompanyData` object:

```php
$company->name;
$company->nip;
$company->regon;
$company->city;

$payload = $company->toArray();
$json = $company->toJson();
```

## Dependency injection

Type-hint `BirRegonService` when an explicit dependency is preferable to a
facade:

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

The service and facade resolve the same singleton from Laravel's service
container.

## Using the GUS test environment

Select the test endpoint for an individual call:

```php
$company = BirRegon::forNip('7740001454')
    ->inDev()
    ->get();
```

For an entire local process or test suite, set `BIR_ENVIRONMENT=dev` instead.

## Next steps

- Search multiple businesses with [Batch searches](batch-searches.md).
- Retrieve detailed data with [Full and bulk reports](reports.md).
- Handle failures with [Error handling](error-handling.md).
