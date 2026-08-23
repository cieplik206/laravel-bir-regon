[← Documentation](README.md)

# Basic usage

The package exposes the same fluent API through the `BirRegon` facade and the
`BirRegonService` container binding.

## Searching with the facade

Search by NIP, REGON, or KRS:

```php
use cieplik206\BirRegon\Facades\BirRegon;

$byNip = BirRegon::forNip('1234567890')->get();

$byRegon = BirRegon::forRegon('123456789')->get();
$byKrs = BirRegon::forKrs('0000123456')->get();
```

`get()` and `search()` are aliases:

```php
$companies = BirRegon::forNip('1234567890')->search();
```

A single identifier is not necessarily a single GUS entity. The same NIP can,
for example, produce CEIDG, agricultural, and other-activity records from
different silos. Both methods return every row as an
`Illuminate\Support\Collection<int, CompanyData>`:

```php
foreach ($companies as $company) {
    $company->name;
    $company->nip;
    $company->regon;
    $company->silo; // Silo enum
}

$exactlyOne = $companies->sole();
```

Call `sole()` only when zero or multiple rows should be treated as an
application error. Do not use `first()` merely to recreate the old singular
behavior; that can silently discard a valid silo result.
When GUS returns no rows, the builder throws `BirNotFoundException` instead of
returning an empty collection.

## Dependency injection

Type-hint `BirRegonService` when an explicit dependency is preferable to a
facade:

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

The service and facade resolve the same scoped service from Laravel's service
container. Calls made within one Laravel request or worker scope can reuse an
authenticated GUS session; a new scope receives a new service and session.

## Using the GUS test environment

Select the dedicated sandbox service before building the request:

```php
$companies = BirRegon::sandbox()
    ->forNip('7740001454')
    ->get();
```

The sandbox service uses `BIR_SANDBOX_API_KEY` and keeps a session independent
from production. Reuse the returned service for related sandbox calls. Both
environments use the package's native GUS BIR 1.2 transport.

## Ending a session explicitly

GUS sessions are reused only inside the current Laravel container scope and
expire on the service side. Manual logout is therefore optional. When a
workflow should end its session immediately, call:

```php
$loggedOut = BirRegon::logout();
$sandboxLoggedOut = BirRegon::sandbox()->logout();
```

Logout is idempotent when the selected client has no local session. Production
and sandbox are independent, and the package always removes the selected local
SID even if GUS rejects the logout or the transport fails. A later authenticated
operation starts a new session.

The transport keeps the cURL handle for the selected client alive so sequential
calls can reuse the HTTP connection and TLS session. Request-specific bodies
and SID headers are reset after every call; production and sandbox never share
a handle or authenticated session.

## Optional checksum validation

Fluent searches always enforce the lengths and decimal formats required by the
GUS protocol. When input also needs a Polish checksum check, use the stateless
validator before making the request:

```php
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;

if (! PolishIdentifierChecksum::isValidNip($nip)) {
    // Reject the form value without a GUS request.
}

PolishIdentifierChecksum::assertValidRegon9($regon9);
PolishIdentifierChecksum::assertValidRegon14($regon14);
```

The available predicates are `isValidNip()`, `isValidRegon()`,
`isValidRegon9()`, and `isValidRegon14()`; matching `assertValid*()` methods
throw `BirValidationException`. Checksum validation is not performed
implicitly because the GUS operation itself specifies only shape constraints.

## Request limits

Laravel enables the cache-backed GUS request limiter by default. Handle
`BirRateLimitException` using its `retryAfterSeconds()` value instead of
immediately retrying. A new acquisition paces for at most one second. Internal
steps after the first reservation in one search/report recovery scope may each
pace for up to seven seconds, while minute and hour blockers fail fast. See
[Request limits](rate-limits.md) for request weights, supported cache stores,
and queue backoff.

## Next steps

- Search multiple businesses with [Batch searches](batch-searches.md).
- Retrieve detailed data with [Full and bulk reports](reports.md).
- Handle failures with [Error handling](error-handling.md).
