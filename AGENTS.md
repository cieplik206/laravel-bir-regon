# Repository instructions for coding agents

## Project purpose

This repository contains `cieplik206/laravel-bir-regon`, a Laravel package for
the Polish GUS BIR/REGON SOAP API. It provides fluent searches by NIP, REGON,
and KRS, batch searches, full and bulk reports, service diagnostics, and typed
`spatie/laravel-data` responses.

The package targets PHP 8.3 or newer and Laravel 12 or 13. Preserve its public
API, Laravel auto-discovery, and production/sandbox separation unless a
breaking change is explicitly requested.

## Repository map

- `src/` contains the package implementation under the
  `cieplik206\BirRegon` namespace.
- `src/BirClient.php` owns GUS communication, authentication, session reuse,
  recovery, response mapping, and exception translation.
- `src/BirRegonService.php` and the builder classes expose the fluent API.
- `src/Data/`, `src/Enums/`, and `src/Exceptions/` are part of the consumer-facing
  contract. Treat changes to their constructors, values, and behavior as public
  API changes.
- `src/BirRegonServiceProvider.php`, `config/`, and `src/Facades/` contain the
  Laravel integration.
- `tests/Unit/` covers focused value behavior, `tests/Feature/` covers package
  behavior with Testbench and fake GUS clients, and `tests/Integration/` contains
  opt-in tests against the live GUS sandbox.
- `docs/` is the source for the documentation website. `README.md` is the short
  package overview.
- `resources/boost/skills/` contains the Laravel Boost guidance shipped to
  consuming applications. Keep it aligned with public API changes.

## Development workflow

Install dependencies with:

```bash
composer install
```

Use the smallest relevant test while developing, then run the complete local
quality suite before handing off a change:

```bash
vendor/bin/pint --test
composer analyse -- --no-progress
composer test
composer validate --strict --no-check-publish
composer audit
```

Use `composer format` to apply formatting. Review its diff; do not allow a
formatter to change unrelated user work.

The default test suite must remain isolated and must not access the network.
Only run the live GUS suite when a change affects SOAP communication,
authentication, session behavior, reports, or response decoding:

```bash
composer test:sandbox
```

Sandbox availability is external and intermittent, so a passing sandbox test
must not replace deterministic unit or feature coverage.

CI repeats formatting, PHPStan/Larastan, Composer validation and audit, plus the
test suite on Laravel 12 and 13 across PHP 8.3, 8.4, and 8.5. PHP 8.3 covers
both the lowest and highest dependency versions for each Laravel release.

## Implementation conventions

- Every PHP file must use `declare(strict_types=1);` and follow the existing
  Laravel Pint style.
- Preserve the existing namespace casing and PSR-4 layout.
- Prefer typed parameters, return types, enums, and data objects over untyped
  arrays at public boundaries.
- Keep builders small and focused. Network calls, session state, and GUS
  exception translation belong in the client layer.
- Preserve reusable but isolated production and sandbox clients. A change must
  not leak credentials or authenticated sessions between environments.
- Keep transport-specific `gusapi/gusapi` objects out of the package's public
  results where typed package data objects already exist.
- Translate dependency failures into the package exception hierarchy when they
  cross a public boundary. Preserve the previous exception as the cause.
- Do not add speculative abstractions or unrelated refactors to a focused fix.

## Testing rules

- Add or update a Pest test for every behavior change.
- A bug fix should include a regression test that fails for the old behavior
  and passes after the fix.
- Model real dependency behavior in fakes. In particular, the GUS API can
  represent some expired-session failures as empty decoded responses instead
  of exceptions; do not make every fake failure throw if production does not.
- Test both the recovery path and the legitimate non-error path when an empty
  result is valid.
- Assert externally observable behavior and important request/session counts;
  avoid coupling tests to private implementation details.
- Never place production BIR credentials in tests, fixtures, logs, issues, or
  pull requests. Use the public sandbox key or redacted values only.

## Documentation and changelog

Update documentation in the same change whenever public behavior,
configuration, supported versions, exceptions, or usage changes:

- update `README.md` for package-level guidance;
- update the relevant page in `docs/` for detailed behavior;
- update the shipped Laravel Boost skill when its guidance becomes stale;
- add a concise entry to `CHANGELOG.md` under `Unreleased`.

Keep documentation examples executable and consistent with the actual fluent
API. Do not document planned behavior as if it already exists.

## Branches and commits

- Start work from an up-to-date `main` and use a focused topic branch. Coding
  agents should default to `codex/<short-description>` unless the user requests
  another branch name.
- Do not commit directly to `main`, push, force-push, create tags, or publish a
  release unless the user explicitly authorizes that action.
- Before committing, inspect `git status` and the complete staged diff. Stage
  only files belonging to the requested change and preserve unrelated work.
- Keep each commit limited to one coherent change.
- Write commit subjects in imperative English, without a trailing period, and
  keep them concise. Match the repository's established style, for example:
  `Fix recovery for expired GUS sessions`, `Add batch report coverage`, or
  `Document sandbox configuration`.
- Explain the reason and noteworthy trade-offs in the commit body when the
  subject alone is insufficient.
- Respect configured commit signing. Do not disable signing merely to make a
  commit succeed.
- Never rewrite, amend, squash, or delete commits that may belong to the user
  unless explicitly asked.

Do not commit generated or local artifacts such as `vendor/`, test caches,
coverage output, IDE settings, credentials, or `.env` files.

## Pull requests

Prefer a pull request for changes intended for `main`. Keep it reviewable and
focused. A professional PR should contain:

- a concise title describing the user-visible outcome;
- a summary of what changed and why;
- regression or feature-test coverage;
- exact verification commands and results;
- documentation and `Unreleased` changelog updates when applicable;
- notes about compatibility, network behavior, or remaining risks.

Before requesting review:

1. Review the diff for accidental files, secrets, debug output, and unrelated
   formatting.
2. Run the complete local quality suite.
3. Push the topic branch and open the PR against `main`.
4. Wait for all GitHub Actions jobs to pass.
5. Address review feedback with new focused commits unless the maintainer asks
   for a history rewrite.

Do not merge a PR, dismiss review feedback, or bypass failing required checks
without explicit maintainer authorization.

## Releases

This package follows Semantic Versioning and Keep a Changelog. Packagist reads
versions from Git tags, so do not add a `version` field to `composer.json`.

- Use a patch release for backward-compatible bug fixes.
- Use a minor release for backward-compatible features.
- Use a major release for breaking public API changes.

A release should be cut only from `main` after CI passes. Move the relevant
entries out of `Unreleased`, set the release date and comparison links, create
an annotated `vX.Y.Z` tag, push the tag, publish matching GitHub release notes,
and verify that Packagist indexes the tag. Release operations always require
explicit user authorization.

## Security and safe operation

- Follow `SECURITY.md`; vulnerabilities belong in GitHub private vulnerability
  reports, not public issues.
- Never expose API keys, credentials, authentication headers, `.env` contents,
  or sensitive logs.
- Avoid destructive Git commands. Never discard user changes to make the tree
  clean.
- Treat network-dependent commands and publication steps as deliberate actions.
  Report what was run and distinguish deterministic local checks from external
  service verification.
