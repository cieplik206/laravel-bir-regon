# Third-party notices

Laravel BIR REGON depends on third-party open-source software. Each dependency
remains subject to its own license and copyright notices.

This repository does not vendor third-party source code. Composer downloads
dependencies separately and preserves the license files distributed with each
package. Run `composer licenses` to inspect the licenses of the complete
installed dependency graph.

## Runtime dependencies

### gusapi/gusapi

- License: GNU Lesser General Public License v2.1 or later
  (`LGPL-2.1-or-later`)
- Source: <https://github.com/johnzuk/GusApi>
- License text: <https://github.com/johnzuk/GusApi/blob/6.3.2/LICENSE>

Laravel BIR REGON uses `gusapi/gusapi` as a separate Composer dependency and
does not incorporate its source code. The library may be replaced with a
compatible version through Composer. Modifications to `gusapi/gusapi` remain
subject to the LGPL.

### Laravel components

- Packages: `illuminate/contracts`, `illuminate/support`
- License: MIT
- Source: <https://github.com/laravel/framework>
- License text: <https://github.com/laravel/framework/blob/13.x/LICENSE.md>

### spatie/laravel-data

- License: MIT
- Source: <https://github.com/spatie/laravel-data>
- License text: <https://github.com/spatie/laravel-data/blob/main/LICENSE.md>

## Development dependencies

The following direct development dependencies are licensed under the MIT
License and are not required when installing the package with
`composer install --no-dev`:

- `larastan/larastan`
- `laravel/pint`
- `orchestra/testbench`
- `pestphp/pest`
- `pestphp/pest-plugin-laravel`

## No endorsement

The names of third-party projects and their contributors are used only to
identify the software. Their inclusion does not imply endorsement of Laravel
BIR REGON.
