# Laravel BIR REGON documentation

Laravel BIR REGON provides a fluent, Laravel-native interface for the Polish
GUS BIR/REGON API. It keeps SOAP 1.2/WS-Addressing over bounded cURL,
authentication, distributed request limiting, and session management inside
the package while exposing typed data objects to the application. Search
results are collections because one identifier can legitimately describe more
than one GUS entity type or silo.

Every public string returned by GUS is untrusted, including company fields,
raw and normalized reports, diagnostics, and service-status messages. Escape
HTML, bind SQL parameters, validate links and email addresses, protect CSV/XLSX
exports from formula injection, and normalize control plus Unicode
format/bidirectional characters and bound length before logging. Mapping does
not sanitize source text.

## Maintenance and support

The package was created for and is used in the maintainer's own projects, then
shared publicly in case it is useful to others. Maintenance is best effort:
there is no commercial support, SLA, guaranteed response time, or commitment
to implement requested changes. Bug reports and focused pull requests are
welcome without a guaranteed response or fix. See the
[support policy](https://github.com/cieplik206/laravel-bir-regon/blob/main/SUPPORT.md)
for details and use the private security-reporting process for suspected
vulnerabilities.

## Getting started

1. [Installation](installation.md)
2. [Configuration](configuration.md)
3. [Basic usage](basic-usage.md)

Upgrading an existing integration? Read the
[2.0 upgrade guide](https://github.com/cieplik206/laravel-bir-regon/blob/main/UPGRADE-2.0.md).

## Searching and reports

- [Batch searches and optional checksums](batch-searches.md)
- [Plural full reports, ambiguity, and bulk reports](reports.md)
- [Strict enums, nullable fields, and raw plus normalized data
  objects](data-objects.md)

## Operations

- [Request limits and retry](rate-limits.md) — weighted per-second GCRA, fixed
  minute and hour windows, bounded pacing, and distributed coordination
- [Service status, diagnostics, and logout](service-status-and-diagnostics.md)
- [Error handling, ambiguity, and rate limits](error-handling.md)
- [Testing](testing.md)
- [Laravel Boost support](laravel-boost.md)

## Customization

- [Extending the package](extending.md)
- [Native SOAP-over-cURL transport decision](architecture/native-soap-transport-decision.html)

If you are looking for a first working example, start with
[Basic usage](basic-usage.md).

[Back to the package README](https://github.com/cieplik206/laravel-bir-regon#readme)
