# Contributing

Contributions are welcome. Please keep pull requests focused and include tests
for changed behavior.

## Local development

Fork and clone the repository, then install its dependencies:

```bash
composer install
```

The package is tested with Orchestra Testbench, so a separate Laravel
application is not required.

## Quality checks

Run the local checks before opening a pull request:

```bash
composer format
composer analyse
composer test
composer validate --strict
```

The default suite is isolated and does not access the network.

## Sandbox integration tests

If a change affects SOAP communication, authentication, reports, or response
mapping, also run:

```bash
composer test:sandbox
```

Sandbox tests use an external GUS service and should not be used as the only
proof of behavior. Never put a production API key in a test, issue, or pull
request.

## Pull requests

- Follow the existing namespace and code style.
- Add or update Pest tests for behavioral changes.
- Update the README or relevant page in `docs/` when the public API changes.
- Add a concise entry under `Unreleased` in `CHANGELOG.md`.
- Keep unrelated refactors out of the pull request.
