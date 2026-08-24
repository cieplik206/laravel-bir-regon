[← Documentation](README.md)

# Laravel Boost support

Laravel BIR REGON ships an optional agent skill for
[Laravel Boost](https://laravel.com/docs/13.x/boost). The skill gives supported
AI coding agents package-specific instructions without loading the complete
documentation into every conversation.

## What the skill covers

The `bir-regon-development` skill describes:

- the fluent NIP, REGON, and KRS search API, including multiple silo results
- batch searches and their identifier limits
- plural and intentionally singular full-report workflows
- strict response enums, raw report rows, and normalized report DTOs
- safe handling of every public GUS string in HTML, SQL, links, email,
  spreadsheets, and logs
- isolated production and sandbox clients with reusable sessions and cURL
  connections
- optional checksum validation and cache-backed request limits
- exception handling, retry backoff, and dependency injection
- isolated tests and opt-in sandbox tests

The source is distributed with the package at:

```text
resources/boost/skills/bir-regon-development/SKILL.md
```

## Install with Laravel Boost

Laravel Boost is a development dependency of the consuming application, not a
runtime dependency of Laravel BIR REGON:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

During installation, select skills and the AI agents used by the project.
Boost detects the package through Composer and offers its skill for
installation.

## Discover the skill in an existing Boost installation

When Laravel BIR REGON is added after Boost has already been configured, scan
the application for newly available package resources:

```bash
php artisan boost:update --discover
```

Use the regular update command later to refresh resources that are already
installed:

```bash
php artisan boost:update
```

## Optional integration

The skill does not affect application execution, API requests, or production
dependencies. Projects that do not use AI coding agents can ignore it. The PHP
package API remains fully documented in this directory.

Continue with [Basic usage](basic-usage.md).
