# Changelog

All notable changes to Laravel BIR REGON will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/cieplik206/laravel-bir-regon/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/cieplik206/laravel-bir-regon/releases/tag/v1.0.0
