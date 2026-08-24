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

The same list semantics apply to `forNip()`, `forRegon()`, and `forKrs()`.
Although those builders accept one identifier, GUS may return several records
for it, for example when a natural person's activities belong to different
silos. No result is discarded. Search fields with a closed GUS vocabulary are
typed as `EntityType`, `Silo`, and nullable `NipStatus` enums on each
`CompanyData` object.

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

The sandbox dataset may be stale, incomplete, artificial, or anonymized. Use
batch results there to test integration behavior, not as evidence of the
current production registry state.

## Empty and oversized batches

An empty identifier array returns an empty collection without sending a
request. Every value must be a string. A batch containing a non-string value,
more than 20 identifiers, or an identifier with an invalid length or non-digit
character is rejected locally with `BirValidationException` before login or any
network request.

Length and digit validation follows the GUS request contract. To reject an
invalid checksum in every NIP and REGON batch before gateway access, opt in for
both production and sandbox clients:

```dotenv
BIR_IDENTIFIER_VALIDATION=checksum
```

The default remains `format` for compatibility with synthetic fixtures and the
wire contract. In `checksum` mode REGON-14 validation checks both its embedded
REGON-9 checksum and its final checksum. KRS has no checksum and remains a
10-digit format check. Neither mode removes prefixes, spaces, or dashes, and a
valid checksum does not prove registry existence.

For larger input sets, split them into chunks before calling the API:

```php
use Illuminate\Support\Collection;

$companies = collect($nips)
    ->chunk(20)
    ->flatMap(
        fn (Collection $chunk) => BirRegon::forNips($chunk->values()->all())->get(),
    );
```

One GUS batch SOAP exchange consumes one request unit for every identifier, not
one unit for the whole array. The default limiter may therefore accept a chunk
and leave weighted per-second debt. A new high-level acquisition paces for at
most one second; a later internal recovery acquisition after an accepted
request may pace for up to seven seconds. Larger debt and every minute/hour
blocker fail fast. Schedule chunks using
`BirRateLimitException::retryAfterSeconds()` rather than sending them in a tight
loop. See [Request limits](rate-limits.md).

Continue with [Full and bulk reports](reports.md).
