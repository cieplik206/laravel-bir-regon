# Changelog

All notable changes to Laravel BIR REGON will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Add a first-party GUS BIR 1.2 SOAP 1.2/WS-Addressing transport, strict
  SOAP/MIME and nested XML decoders, and deterministic protocol fixtures
- Add public gateway and transport contracts for controlled integrations,
  BIR121 local legal-unit reports, configurable timeouts, a response-size
  limit, and a package user agent
- Add explicit, idempotent logout for production and sandbox sessions
- Add typed full-report normalization for common entity, address, contact,
  registry, legal-form, lifecycle, local-unit, PKD, partner, and unit-type
  fields while retaining every raw GUS row for forward compatibility
- Add opt-in NIP and REGON checksum validation without making checksums a
  prerequisite for the official wire protocol
- Add a cache-backed, distributed Laravel request limiter enabled by default,
  official time-of-day quotas, weighted per-second GCRA, fixed calendar minute
  and hour windows, explicit recovery scopes, bounded pacing, a strict cache
  backend allowlist, and a retry-aware `BirRateLimitException`

### Changed

- Resolve production and sandbox clients as isolated scoped services, validate
  identifiers and bulk-report dates before network access, diagnose ambiguous
  empty responses, and retry an expired session at most once
- Read public diagnostics as one atomic session snapshot, normalize bulk-report
  dates once to the `Europe/Warsaw` calendar day, and classify malformed
  embedded report errors as protocol failures
- Validate full-report compatibility against the returned entity type, silo,
  and REGON length before requesting a report
- Return collections for NIP, REGON, and KRS searches so one identifier can
  expose every matching entity type and silo; provide plural full-report
  methods and reject ambiguous singular full-report selection
- Represent entity type, silo, and non-empty NIP status with strict enums,
  preserve a nullable REGON-14 only when GUS returned it, and reject malformed
  or impossible activity-end dates while accepting the complete `xs:date`
  lexical form, including legal timezone suffixes
- Name the official enum cases `EntityType::LegalUnit`,
  `EntityType::NaturalPerson`, `EntityType::LegalUnitLocalUnit`,
  `EntityType::NaturalPersonLocalUnit`, and `Silo::LegalUnits`; keep the open
  PKD classification version as a forward-compatible nullable string
- Represent natural-person activity-kind values as nullable integer counts,
  preserve empty and unknown-only raw rows without normalized phantom objects,
  and enforce singleton cardinality against the original raw rows
- Replace the PHP SOAP runtime with a bounded cURL sender and require
  `ext-curl`, `ext-dom`, and `ext-libxml`
- Reuse a reset cURL handle within each scoped transport to retain connection,
  DNS, and TLS session caches without retaining request bodies or SID headers
- Apply the same 10 MB default response limit to the outer SOAP/MIME payload
  and nested report XML, including manually constructed gateway graphs
- Preserve compatibility with the historical indented single-part MIME framing
  covered by GusApi 6.3.2 while rejecting ambiguous delimiters or mixed
  indentation
- Avoid deprecated explicit cURL handle cleanup on PHP 8.5 while retaining
  deterministic request-resource cleanup
- Upgrade the development suite to Pest 5, PHPUnit 13, and
  `pest-plugin-phpstan`; test Laravel 13 on PHP 8.4 and 8.5, verify the PHP
  8.4.0/Illuminate 13.0 runtime graph separately, and pin GitHub Actions to
  reviewed commit SHAs

### Fixed

- Stop silently discarding additional GUS rows when a single identifier maps
  to multiple entity types, silos, or distinct full-report REGONs
- Reject unknown response enum values, fabricated REGON-14 fallbacks, and
  malformed or impossible activity-end dates instead of exposing invalid data
- Enforce conservative no-burst spacing for separate requests, allow explicit
  weighted-batch debt, and treat fixed minute/hour windows as a local model
- Pace new acquisitions for at most one second and each later internal
  acquisition in an active recovery scope for up to seven seconds; keep
  minute/hour exhaustion fail-fast and calculate retry from the longest active
  blocker plus clock rollback
- Restrict cache coordination to the exact base Laravel repository with the
  five reviewed stores, use a 30-second lock lease, and bound contention waiting
  to one second before failing closed
- Keep credential-free transport tombstones safe to inspect after
  deserialization instead of letting debug output access uninitialized state
- Reject missing WS-Addressing response actions and `xsi:nil` results for the
  WSDL-non-nillable `GetValue` and logout operations, preserve that protocol
  marker through the native transport, and never misclassify malformed
  diagnostics as an expired session

### Removed

- Remove the runtime `gusapi/gusapi` dependency and its public factory and
  GusApi-specific mapper extension points; see `UPGRADE-2.0.md`
- Remove PHP 8.3 and Laravel 12 support from version 2; the new minimum is PHP
  8.4 with Laravel 13

### Security

- Prevent BIR clients, services, and request builders from persisting API keys
  in queue or cache payloads by restoring their credential-free serialized form
  only as an inert tombstone, while safely discarding credential-bearing legacy
  payload state outside exception traces
- Keep API keys, session IDs, raw SOAP requests, and response bodies out of
  exception graphs and debug output; reject malformed MIME, SOAP faults,
  external entities, oversized payloads, and ambiguous protocol structures
- Keep decoded transport results private behind a redacted `result()` accessor
  and restore serialized transport responses only as inert tombstones
- Hide cache-backend credentials, limiter callback state, and custom transport,
  gateway, and client collaborators from object exporters; translate callback
  failures without retaining their causes, keep credential-bearing clients out
  of fluent-builder exception arguments, and redact the Laravel container
  during resolution failures and corrupted limiter state during validation
  failures
- Harden session renewal, MIME root selection, HTTP framing, mandatory
  WS-Addressing response correlation, operation-specific `xsi:nil`, UTF-16 and
  UTF-32 rejection, HTTP trailer handling, nested report XML, and response
  limits with regression tests
- Document that every public GUS string remains untrusted input requiring HTML
  escaping, parameterized SQL, URL/email validation, CSV/XLSX formula-injection
  protection, and control-character handling against log forging

## [1.1.1] - 2026-08-23

### Fixed

- Redact BIR API keys and session IDs from translated GUS exceptions, prevent
  traces from retaining client-bound callbacks, and preserve diagnostic context
  through a credential-free surrogate cause

### Security

- Require protected, signed, CI-verified release tags and publish immutable
  release assets with SHA-256 checksums

## [1.1.0] - 2026-08-23

### Added

- Laravel 12 support alongside Laravel 13, with CI coverage for both framework
  versions on PHP 8.3, 8.4, and 8.5

## [1.0.2] - 2026-08-23

### Fixed

- Avoid unnecessary session-status requests after locally rejected invalid arguments

## [1.0.1] - 2026-08-23

### Fixed

- Renew expired GUS sessions when bulk or full report endpoints silently return
  empty payloads instead of throwing an exception

## [1.0.0] - 2026-08-23

### Added

- Fluent searches by NIP, REGON, and KRS
- Batch searches for NIP, KRS, REGON-9, and REGON-14
- Full and bulk GUS report support through native enums
- Service status, data status, and session diagnostics
- Separate production and sandbox clients with reusable authenticated sessions
- Typed response objects powered by `spatie/laravel-data`
- Laravel service provider, facade, and container contracts
- Isolated tests and opt-in live GUS sandbox tests
- A discoverable Laravel Boost skill for AI-assisted integrations

[Unreleased]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/cieplik206/laravel-bir-regon/releases/tag/v1.0.0
