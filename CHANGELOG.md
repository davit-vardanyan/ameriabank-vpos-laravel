# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton for the Laravel bridge to
  `davit-vardanyan/ameriabank-vpos-php`: `composer.json`, autoloading under
  `DavitVardanyan\AmeriabankVpos\Laravel\`, and the MIT license.
- `AmeriabankVposServiceProvider`, registered for package discovery through
  `extra.laravel.providers`. It registers no binding and publishes no
  configuration; it carries a single constant, `TARGETS_CLIENT`, naming the core
  client version this bridge targets. Bindings, configuration, the facade and
  callback resolution are deliberately not in this release.
- Quality gate: PHPStan level 10 with Larastan and no baseline, Pint on the
  `laravel` preset with `declare_strict_types`, Rector, Pest 4 under Orchestra
  Testbench, 100% line coverage, and `pest-plugin-mutate` at MSI 100.
- Architecture expectations via `pest-plugin-arch`, enforcing that everything in
  `src/` is final, declares strict types, and contains no debug calls.
- CI across twelve combinations — PHP 8.3, 8.4 and 8.5 × Laravel 12 and 13 ×
  lowest and highest dependencies. Laravel and Testbench are paired, never
  crossed: Testbench 11 with Laravel 13, Testbench 10 with Laravel 12. Coverage
  and mutation testing run on the PHP 8.3 / Laravel 13 / highest leg.
- `phpunit.xml.dist` pins `zend.exception_ignore_args=0` and
  `zend.exception_string_param_max_len=15`. Without both, any test asserting a
  value is *absent* from a stack trace is vacuous in the direction that passes.

### Notes

- Requires PHP `^8.3` and Laravel 12 or 13. Laravel 11 and below are not
  supported: Laravel 11's security support ended on 2026-03-11, and advertising
  support for an unpatched framework in a payment package is the wrong signal.

[Unreleased]: https://github.com/davit-vardanyan/ameriabank-vpos-laravel/commits/main
