**This is an unofficial package. It is not affiliated with, endorsed by, or supported by Ameriabank.**

# ameriabank-vpos-laravel

Laravel integration for [`davit-vardanyan/ameriabank-vpos-php`](https://github.com/davit-vardanyan/ameriabank-vpos-php),
an unofficial client for the Ameriabank vPOS 3.1 payment gateway.

[![CI](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/ci.yml)
[![Static analysis](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/static.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/static.yml)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> **Status: skeleton. There is no bridge behaviour yet.**
>
> This release establishes the package, its service provider and a green quality
> gate — nothing more. It registers **no container binding**, publishes **no
> configuration file**, provides **no facade**, resolves **no callback**, and
> wraps **no method** of the core client. Nothing has been tagged, so there is no
> stable public API to depend on.
>
> This README deliberately documents no API that does not exist. When bindings,
> configuration and the facade arrive, they will be documented here and recorded
> in [`CHANGELOG.md`](CHANGELOG.md).

## What this package is for

The core client is framework-agnostic by design: PSR-18 transport, constructor
injection, no container, no configuration. That is the right shape for a
library, and the wrong shape for dropping into a Laravel application, where you
would rather read credentials from config, resolve a client from the container,
and let package discovery wire it up.

This package is the bridge that will do that. Today it is only the frame.

## Requirements

- PHP `^8.3`
- Laravel **12** or **13**
- [`davit-vardanyan/ameriabank-vpos-php`](https://github.com/davit-vardanyan/ameriabank-vpos-php) `^1.0.1`

Laravel 11 and below are **not** supported. Laravel 11's security support ended
on 2026-03-11, and advertising support for an unpatched framework in a payment
package is the wrong signal to send. Laravel 12 keeps security support until
2027-02-24.

The core client is PSR-18 based and does not ship an HTTP client. See the core
package's README for choosing one.

## Installation

```bash
composer require davit-vardanyan/ameriabank-vpos-laravel
```

The service provider is registered automatically through Laravel's package
discovery. There is nothing to add to `bootstrap/providers.php` and nothing to
publish.

## Usage

**There is no usage yet.** The service provider registers nothing. Anything you
could write here today would be a description of the core client, not of this
package, and is better read in the core package's own README.

The one public symbol this package currently exposes is a constant naming the
core client version the bridge targets:

```php
use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;

AmeriabankVposServiceProvider::TARGETS_CLIENT; // '^1.0.1'
```

## Credentials

Never commit vPOS credentials. Keep the ClientID, username and password in the
environment, out of version control, and out of logs, exception messages and bug
reports.

Where an example needs a ClientID, this project uses an all-zero GUID —
`00000000-0000-0000-0000-000000000000`. That is a placeholder chosen because it
cannot be a real credential; it is **not** a claim about the format the bank
issues.

If a credential is ever exposed, rotate it with Ameriabank immediately. See
[`SECURITY.md`](SECURITY.md) for how to report a vulnerability privately.

## Development

Clone the repository and install dependencies:

```bash
composer install
```

The quality gate is nine commands:

```bash
composer validate --strict     # composer.json is valid and complete
composer normalize --dry-run   # composer.json is in canonical order
composer stan                  # PHPStan level 10 with Larastan, no baseline
composer cs:check              # Pint
composer rector                # no proposed refactors
composer test                  # Pest 4 under Orchestra Testbench
composer coverage              # writes build/clover.xml
composer coverage:check        # line coverage must be 100%
composer mutate                # mutation score must be 100
```

`composer coverage` must run **before** `composer coverage:check`: the first
writes `build/clover.xml` and the second reads it, so running the check alone
inspects a stale or absent file and can pass for the wrong reason.

`composer bc` (`roave/backward-compatibility-check`) is a development dependency
but is **not** part of the gate and is not run in CI. It diffs the current code
against a previous release tag, and this repository has no tag yet, so a
`composer bc` failure before the first release is expected rather than a
regression.

`composer.lock` is not committed. This is a library, not an application.

### CI

Twelve combinations: PHP 8.3, 8.4 and 8.5 × Laravel 12 and 13 × lowest and
highest dependency resolution. Laravel and Orchestra Testbench are **paired,
never crossed** — Testbench 11 requires Laravel 13 and Testbench 10 requires
Laravel 12, so a crossed pair does not merely violate a convention, it fails to
resolve. Coverage and mutation testing run on the PHP 8.3 / Laravel 13 / highest
leg.

## Relationship to the core package

| | |
|---|---|
| Core client | [`davit-vardanyan/ameriabank-vpos-php`](https://github.com/davit-vardanyan/ameriabank-vpos-php) — framework-agnostic, PSR-18 |
| This package | The Laravel bridge over it |

The two are separate repositories with separate release cycles. Report a defect
in the gateway protocol, the DTOs or the transport against the core package;
report a defect in container wiring, configuration or Laravel integration here.

## License

MIT. See [`LICENSE`](LICENSE).

Ameriabank is a trademark of its owner. Its use here is descriptive only and
does not imply any affiliation or endorsement.
