[← Documentation](README.md)

# Request limits

GUS applies request limits to all API operations, including authentication and
`GetValue`. Laravel BIR REGON coordinates that budget locally before opening
the HTTP connection. The Laravel service provider enables
`CacheBirRequestLimiter` by default.

The limiter is a safety mechanism, not a reservation of capacity at GUS. Calls
made with the same key by an application that does not share the limiter state
remain invisible to this package, and GUS may still reject a request.

## Official schedule

The package selects the published GUS BIR limits using the `Europe/Warsaw`
time zone:

| Local time | Requests per second | Requests per minute | Requests per hour |
| --- | ---: | ---: | ---: |
| 06:00–07:59 and 17:00–21:59 | 3 | 150 | 8,000 |
| 08:00–16:59 | 3 | 120 | 6,000 |
| 22:00–05:59 | 4 | 200 | 10,000 |

These values come from the GUS BIR 1.2 documentation published on the
[official REGON API portal](https://api.stat.gov.pl/home/regonapi). Review the
portal when planning high-volume workloads because the service owner may
change its policy.

## What consumes the budget

The native transport reserves quota after it has validated and built the local
request, immediately before each real SOAP call. Login, logout, every
`GetValue`, a full report, and a bulk report each cost one unit. A search costs
one unit for every identifier in its `SearchCriteria`:

- a search for one NIP, REGON, or KRS costs one unit;
- a batch of 20 identifiers costs 20 units even though it uses one SOAP
  exchange;
- an empty fluent batch returns locally and costs nothing.

Authentication and recovery calls are included. For example, a search without
an active session normally costs one login unit plus its identifier count.
`getFullReports()` first performs that search and then spends one additional
unit for every distinct compatible report target it fetches.
Session diagnostics, a replacement login, and a retried operation consume
additional units when the session expires.

The limiter allows one valid batch to create short-term quota debt. A later
operation is paced only within the bounds described below; otherwise it is
rejected until that debt is repaid. Do not run chunks of 20 in a tight loop
without handling backoff.

## Enforcement model

The package applies a conservative local model; it does not claim to reproduce
an undocumented server-side GUS algorithm:

- The per-second budget uses weighted GCRA. Separate unit-cost SOAP operations
  are spaced at `1 / requests-per-second`; they do not receive an initial burst.
  A batch whose cost is at or below the active rate is reserved atomically and
  leaves the corresponding debt for the next operation.
- A batch whose identifier cost is greater than the per-second rate cannot be
  split into several SOAP calls without changing its semantics. The limiter may
  admit one such request when prior second-level debt permits it, then records
  the complete weighted debt. This is an explicit local compromise, not a
  guarantee that GUS will accept the request.
- Minute and hour budgets use fixed calendar windows under the
  `Europe/Warsaw` schedule. The actual epoch boundary distinguishes both copies
  of the repeated daylight-saving hour. These are local fixed windows, not a
  claim that GUS uses fixed or rolling windows internally.

The fixed minute and hour windows fail fast and are never paced. Within a new
or external `acquire()` call, second-level pacing is limited to one second.
`NativeBirGateway::callForRecords()` additionally opens a logical rate-limit
scope. Before its first successful reservation the same one-second limit
applies; after that reservation, each subsequent internal `acquire()` in the
same scope may pace for up to seven seconds. This lets diagnostics, a new login,
and one retry finish after an accepted search or report. A recovery sequence
contains several acquisitions and can therefore sleep for more than seven
seconds in total.

Every pacing sleep happens after releasing the cache lock. Scope management is
explicit through `beginRateLimitScope()` and `endRateLimitScope()`; it is not a
closure callback.

## Laravel configuration

The available environment variables are:

```dotenv
BIR_RATE_LIMIT_ENABLED=true
BIR_RATE_LIMIT_STORE=redis
BIR_RATE_LIMIT_PREFIX=bir-regon:rate-limit
```

| Variable | Default | Purpose |
| --- | --- | --- |
| `BIR_RATE_LIMIT_ENABLED` | `true` | Uses the cache-backed limiter in the Laravel integration |
| `BIR_RATE_LIMIT_STORE` | The application's default cache store | Selects a named Laravel cache store |
| `BIR_RATE_LIMIT_PREFIX` | `bir-regon:rate-limit` | Separates package state from other cache keys |

`CacheBirRequestLimiter` accepts only the exact base
`Illuminate\Cache\Repository`, without tags, decorators, or repository
subclasses, backed by one of these explicitly supported Laravel stores:

- `ArrayStore`
- `DatabaseStore`
- `FileStore`
- `MemcachedStore`
- `RedisStore`

Implementing `LockProvider` alone is not sufficient. `TaggedCache`, repository
decorators and subclasses, DynamoDB, `FailoverStore`, `MemoizedStore`, `NullStore`,
and custom stores fail closed with `BirRateLimitException`. This avoids pairs
of cache and lock semantics that have not been verified together. An
application with another backend must provide its own
`BirRequestLimiterInterface` implementation and pass it to
`NativeSoapTransport`, or replace the transport.

The atomic lock has a 30-second lease. Acquisition waits for contention for at
most one second, verifies ownership around the state write, and fails closed
rather than sending an uncoordinated request. The lock is not held while the
limiter sleeps.

Use one shared Redis store and prefix for queue workers or application
instances on multiple hosts. `ArrayStore` and `FileStore` are local and do not
coordinate separate hosts. A database or Memcached store coordinates only when
every process really uses the same backend. The state identity contains the
environment and a SHA-256 fingerprint of the API key, never the raw credential,
so production, sandbox, and different API keys have independent budgets.

After changing these values in a cached application, rebuild its configuration
cache:

```bash
php artisan config:cache
```

## Bounded pacing and fail-fast backoff

When second-level debt exceeds the applicable one- or seven-second pacing
bound, or a minute/hour window blocks the request, the limiter throws
`BirRateLimitException` before network I/O. A queued job can release itself for
the calculated delay:

```php
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Facades\BirRegon;

try {
    $companies = BirRegon::forNip($nip)->get();
} catch (BirRateLimitException $exception) {
    $this->release($exception->retryAfterSeconds());

    return;
}
```

`quotaWasExceeded()` is `true` when the local model blocks the request.
`retryAfterSeconds()` rounds up the longest active second, minute, or hour
blocker, plus any delay needed after the wall clock moved backwards. It is
always at least one second. When coordination itself is unavailable,
`quotaWasExceeded()` is `false` and `retryAfterSeconds()` is one second. Lock
contention can already have consumed up to one second before that result. The
exception propagates without triggering new session diagnostics.

Disabling `BIR_RATE_LIMIT_ENABLED` makes the Laravel provider use
`UnlimitedBirRequestLimiter`. This is appropriate only when another component
coordinates every caller using the key. Otherwise it removes local protection.

## Direct and custom transports

`NativeSoapTransport` constructed directly defaults to
`UnlimitedBirRequestLimiter`; the cache-backed default is supplied by the
Laravel service provider. Standalone users must pass a
`BirRequestLimiterInterface` implementation explicitly if they need local
coordination.

A replacement `BirGatewayInterface` or `BirSoapTransportInterface` owns its
outbound calls and therefore also owns rate limiting. Use
`UnlimitedBirRequestLimiter` or a recording fake in isolated tests where no
network call is possible, and inject a rejecting fake when testing backoff
behavior.

Continue with [Service status, diagnostics, and logout](service-status-and-diagnostics.md).
