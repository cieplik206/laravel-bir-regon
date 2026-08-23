[← Documentation](README.md)

# Installation

## Requirements

Laravel BIR REGON requires:

- PHP 8.4 or newer
- Laravel 13
- the PHP DOM extension (`ext-dom`)
- the PHP cURL extension (`ext-curl`)
- the PHP libxml extension (`ext-libxml`)

Composer installs `illuminate/cache`, `illuminate/contracts`, and
`illuminate/support` 13.x as runtime components. With the default request
limiter enabled, use the exact base Laravel cache repository with an
`ArrayStore`, `DatabaseStore`, `FileStore`, `MemcachedStore`, or `RedisStore`.
Other repositories and stores fail closed even when they expose an atomic-lock
API.

The Pest 5 matrix covers Laravel 13 on PHP 8.4 and 8.5. PHP 8.4 uses both the
lowest and highest resolvable test toolchains, while PHP 8.5 uses the highest
versions. A separate runtime-only Composer check resolves PHP 8.4.0 with
`illuminate/cache`, `illuminate/contracts`, and `illuminate/support` 13.0.
Applications that need PHP 8.3 or Laravel 12 must stay on the 1.x package line.

Composer verifies these extensions directly. Version 2 uses the package's
native, bounded cURL transport and strict XML decoders; it neither requires
PHP's SOAP extension nor installs a separate GUS API client library.

The native transport retains one cURL handle per sender instance so
libcurl can reuse its HTTPS connection, DNS cache, and TLS session. Request
bodies, callbacks, and SID headers are reset between calls, and production and
sandbox never share a handle.

## Install the package

Install the package via Composer:

```bash
composer require cieplik206/laravel-bir-regon
```

Laravel's package discovery registers
`cieplik206\BirRegon\BirRegonServiceProvider` automatically. No changes to
`bootstrap/providers.php` are required.

## Configure the API key

Add your production BIR API key to `.env`:

```dotenv
BIR_API_KEY=your-api-key
```

The API key is required for searches, reports, the data-status endpoint, and
diagnostics. Reading the public service status does not require authentication.

Never commit a real API key to the repository.

The package includes the public key for the official GUS sandbox. If GUS
provides a different test key, configure it separately:

```dotenv
BIR_SANDBOX_API_KEY=your-test-key
```

The sandbox key is never used for production requests, and the production key
is never sent to the sandbox endpoint.

## Configure shared request limiting

Request limiting is enabled automatically by the Laravel service provider.
Queue workers or application instances on several hosts should point
`BIR_RATE_LIMIT_STORE` at the same shared Redis store:

```dotenv
BIR_RATE_LIMIT_STORE=redis
```

Install and configure the Redis client required by the consuming Laravel
application; this package does not choose one. `ArrayStore` and `FileStore` are
local and cannot coordinate separate hosts. Tagged caches, repository
decorators/subclasses, DynamoDB, `FailoverStore`, `MemoizedStore`, `NullStore`, and
custom stores are intentionally unsupported; provide a custom limiter when one
of those backends is required. The lock lease is 30 seconds and contention
waiting is bounded to one second. See
[Request limits](rate-limits.md) for the official schedules, batch weighting,
failure behavior, and all limiter variables.

## Publish the configuration

Publishing is optional. The package defaults work directly from environment
variables, but a published file makes the available settings visible in the
application:

```bash
php artisan vendor:publish --tag=bir-regon-config
```

This creates `config/bir-regon.php`.

## Laravel Boost skill

The package includes an optional `bir-regon-development` agent skill. If the
consuming application does not yet use Laravel Boost, install and configure it
first:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

If Boost was installed before Laravel BIR REGON, discover newly installed
package skills with:

```bash
php artisan boost:update --discover
```

Laravel Boost will offer to install the skill for the configured AI agents. The
package does not require Laravel Boost at runtime. See
[Laravel Boost support](laravel-boost.md) for the skill's scope and update
workflow.

## Manual provider registration

This is only necessary when package discovery is disabled in the consuming
application. Add the provider to `bootstrap/providers.php`:

```php
<?php

use cieplik206\BirRegon\BirRegonServiceProvider;

return [
    App\Providers\AppServiceProvider::class,
    BirRegonServiceProvider::class,
];
```

Continue with [Configuration](configuration.md).

Upgrading from 1.x? Follow [Upgrade guide for 2.0](../UPGRADE-2.0.md).
