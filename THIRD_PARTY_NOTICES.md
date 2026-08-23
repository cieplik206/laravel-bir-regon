# Third-party notices

Laravel BIR REGON depends on third-party open-source software. Each dependency
remains subject to its own license and copyright notices.

This repository does not vendor third-party source code. Composer downloads
dependencies separately and preserves the license files distributed with each
package. Run `composer licenses` to inspect the licenses of the complete
installed dependency graph.

## Runtime dependencies

### Laravel components

- Packages: `illuminate/cache`, `illuminate/contracts`, `illuminate/support`
- License: MIT
- Source: <https://github.com/laravel/framework>
- License text: <https://github.com/laravel/framework/blob/13.x/LICENSE.md>

### spatie/laravel-data

- License: MIT
- Source: <https://github.com/spatie/laravel-data>
- License text: <https://github.com/spatie/laravel-data/blob/main/LICENSE.md>

The GUS BIR 1.2 SOAP protocol is implemented directly by this package. No
third-party GUS client source code is included or installed at runtime.

## Development dependencies

The following direct development dependencies are licensed under the MIT
License and are not required when installing the package with
`composer install --no-dev`:

- `larastan/larastan`
- `laravel/pint`
- `orchestra/testbench`
- `pestphp/pest`
- `pestphp/pest-plugin-laravel`
- `pestphp/pest-plugin-phpstan`

## No endorsement

The names of third-party projects and their contributors are used only to
identify the software. Their inclusion does not imply endorsement of Laravel
BIR REGON.
