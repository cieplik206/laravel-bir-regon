[← Documentation](README.md)

# Batch searches

The BIR API accepts up to 20 identifiers in a single batch request. Laravel
BIR REGON exposes a separate fluent entry point for every identifier variant.

## Available searches

| Identifier | Method | Expected format |
| --- | --- | --- |
| NIP | `forNips()` | 10 digits |
| KRS | `forKrsNumbers()` | 10 digits |
| REGON-9 | `forRegons9()` | 9 digits |
| REGON-14 | `forRegons14()` | 14 digits |

## Searching by multiple identifiers

```php
use cieplik206\BirRegon\Facades\BirRegon;

$companies = BirRegon::forNips([
    '1234567890',
    '9876543210',
])->get();
```

Each method returns an `Illuminate\Support\Collection` containing
`CompanyData` objects:

```php
$names = $companies
    ->map(fn ($company) => $company->name)
    ->filter();
```

The remaining identifier variants use the same API:

```php
$byKrs = BirRegon::forKrsNumbers([
    '0000123456',
])->get();

$byRegon9 = BirRegon::forRegons9([
    '123456789',
])->get();

$byRegon14 = BirRegon::forRegons14([
    '12345678901234',
])->get();
```

`search()` is available as an alias for `get()` on every batch builder.

## Searching the sandbox

Select the sandbox service before creating a batch builder:

```php
$companies = BirRegon::sandbox()
    ->forNips([
        '7740001454',
    ])
    ->get();
```

## Empty and oversized batches

An empty identifier array returns an empty collection without sending a
request. A batch containing more than 20 identifiers is rejected by the
underlying GUS client and exposed as a `BirException`.

For larger input sets, split them into chunks before calling the API:

```php
use Illuminate\Support\Collection;

$companies = collect($nips)
    ->chunk(20)
    ->flatMap(
        fn (Collection $chunk) => BirRegon::forNips($chunk->values()->all())->get(),
    );
```

Continue with [Full and bulk reports](reports.md).
