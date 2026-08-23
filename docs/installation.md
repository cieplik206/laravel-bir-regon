[← Documentation](README.md)

# Installation

## Requirements

Laravel BIR REGON requires:

- PHP 8.3 or newer
- Laravel 13
- the PHP SOAP extension
- the PHP SimpleXML extension

The continuous integration matrix tests PHP 8.3, 8.4, and 8.5. PHP 8.3 is
tested with both the lowest and highest supported dependency versions, while
PHP 8.4 and 8.5 are tested with the highest supported versions.

Composer verifies the required PHP extensions through the underlying
`gusapi/gusapi` dependency.

## Install the package

Install the package via Composer:

```bash
composer require cieplik206/laravel-bir-regon
```

Laravel's package discovery registers
`cieplik206\BirRegon\BirRegonServiceProvider` automatically. No changes to
`bootstrap/providers.php` are required.

## Configure the API key

Add your production BIR API key and environment to `.env`:

```dotenv
BIR_API_KEY=your-api-key
BIR_ENVIRONMENT=prod
```

The API key is required for searches, reports, the data-status endpoint, and
diagnostics. Reading the public service status does not require authentication.

Never commit a real API key to the repository.

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
consuming application uses Laravel Boost, discover newly installed package
skills with:

```bash
php artisan boost:update --discover
```

Laravel Boost will offer to install the skill for the configured AI agents.
Third-party package skill discovery requires Laravel Boost 2.2 or newer. The
package does not require Laravel Boost at runtime.

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
