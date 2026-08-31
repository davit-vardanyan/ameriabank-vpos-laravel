# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The Laravel bridge package for `davit-vardanyan/ameriabank-vpos-php`:
  `composer.json`, autoloading under `DavitVardanyan\AmeriabankVpos\Laravel\`,
  and the MIT license.
- `AmeriabankVposServiceProvider`, registered for package discovery through
  `extra.laravel.providers`.
- `config/ameriabank-vpos.php`, merged in `register()` and publishable under the
  `ameriabank-vpos-config` tag. Keys: `client_id`, `username`, `password`,
  `environment`, `back_url`, `max_attempts` and `logging.{enabled,channel}`,
  each read from an `AMERIABANK_VPOS_*` environment variable. `env()` is called
  in this file and nowhere else in the package, since it returns null once an
  application caches its configuration.
- Five container bindings. `Vpos` is a singleton — it owns the transport and the
  discovered PSR-18 client. `PaymentsClient`, `BindingsClient` and
  `ReportsClient` are bound non-singleton and resolved from that singleton, so
  constructor injection works in a controller and two resolutions still return
  the same object. `VposCallback` is built from the current request's query
  string, so a controller handling the `back_url` can take it as a parameter.
- A `Vpos` facade, aliased through `extra.laravel.aliases`, carrying
  `@method static` annotations for `payments()`, `bindings()`, `reports()`,
  `verify()` and `paymentPageUrl()`. Without them a facade is a static-analysis
  blind spot exactly where the money is. Constructor injection remains the
  documented primary route.
- `ConfigurationException` for the Laravel side of the wiring, implementing the
  core client's `VposExceptionInterface` so one catch still covers both
  packages. Its constructor is private and every message it can emit lives in a
  named factory: an unrecognised `environment` value, a blank `back_url`, a
  `back_url` naming a route that does not exist, a `back_url` naming a route that
  *is* registered but declares required parameters, and a `VposCallback` resolved
  outside a request that carries one. The two route mistakes take two factories
  and are never conflated: reporting a registered route as "neither an absolute
  URL nor the name of a registered route" would be false, and would send the
  reader hunting for a typo in a spelling that is correct.
- `back_url` resolution accepting either an absolute `http`/`https` URL or the
  name of a route, resolved when the value is needed rather than while providers
  register. A blank value, a route name this application has not registered, and
  a route name that is registered but cannot be built without parameters all
  throw, and the two naming a route print it — a route name and a typo of one are
  indistinguishable to look at. The value is never allowed to fall through to an
  empty `BackURL`, which the gateway accepts and then sends the customer nowhere.
  A parameterised route is reported as the configuration mistake it is rather than
  as an unclassified failure: the `BackURL` is where the gateway returns the
  customer, so it has to resolve on its own, and nothing here can supply a route
  parameter.
- `php artisan vpos:check`, which reports the resolved configuration and makes
  **one real HTTP request** to the gateway — a single `GetPaymentId` call. It
  exits 0 only when the credentials are proven valid, 1 only when they are
  proven rejected, and 2 whenever the answer establishes neither. **Anything
  scripting against it must treat 2 as a distinct, non-fatal "cannot tell":**
  `vpos:check && deploy` must not pass on a probe that established nothing, and a
  gateway fault, response code `550`, any other non-rejection response code, an
  unreachable gateway, a reply the client cannot read and a blind success code
  are all 2. A caveat in the output is not a result a pipeline can read; the exit
  code is. An out-of-range `max_attempts` is exit 1, named as the configuration
  mistake it is; **any other refusal the client raises is also exit 1 but names no
  key**, because the command cannot tell what was refused, which setting it came
  from, or whether it came from a setting at all — it prints the client's own
  words and says so, rather than guessing at a key the merchant may never have
  set. No exception escapes the command as a stack trace — one that did would exit
  1 and so publish a rejection the gateway never gave. Every inconclusive run made
  without `--order-id` also names that option, since it is the only thing that can
  turn an inconclusive result into an answer. The
  password is never read into a message, the ClientID and username are truncated
  to four characters, and the `PaymentId` the gateway returns is never printed.
- The probe operation is **`GetPaymentId`**. The decisive reason is not
  credential discrimination: `GetPaymentDetails` never puts the ClientID on the
  wire at all, so a merchant who typos their ClientID is structurally
  undetectable on it under any gateway behaviour. `GetPaymentId` sends the
  ClientID. Secondarily, it is also the only operation ever observed returning a
  genuine credential rejection, and the command reads that rejection in both wire
  forms — the integer `20` and the string `"20"`.
- `--order-id=` on `vpos:check`, naming an order the merchant registered. It is
  the only mode that can prove the credentials valid: for a known order, correct
  credentials have been observed answering a success code and a `PaymentId` while
  a wrong password answered response code 20 — a premise the command cannot
  check, and restates in the verdict. Without the option the command probes a
  sentinel `OrderID` no merchant can own, which detects a rejection and nothing
  else. Every run names the mode it ran in and states plainly that the gateway
  offers no reliable credential check: no operation the package can safely call
  reliably separates valid credentials from invalid ones, and `InitPayment`, the
  only one with an unambiguous rejection, is barred from a diagnostic because it
  registers a real order. No probe has ever reached either production host, so a
  result against `production` has no observational backing, and the command says
  so beside the verdict.
- Quality gate: PHPStan level 10 with Larastan and no baseline, Pint on the
  `laravel` preset with `declare_strict_types`, Rector, Pest 4 under Orchestra
  Testbench, 100% line coverage, and `pest-plugin-mutate` at MSI 100 with the
  floor declared in the `mutate` script as `--min=100`. There is no carve-out
  for a run that generates no mutants: a perfect score over an empty set would
  be green for the wrong reason.
- Architecture expectations via `pest-plugin-arch`, enforcing that everything in
  `src/` is final, declares strict types, and contains no debug calls; and that
  the `Exception`, `Facades` and `Commands` namespaces hold nothing but what
  their names say.
- CI across twelve combinations — PHP 8.3, 8.4 and 8.5 × Laravel 12 and 13 ×
  lowest and highest dependencies. Laravel and Testbench are paired, never
  crossed: Testbench 11 with Laravel 13, Testbench 10 with Laravel 12. Coverage
  and mutation testing run on the PHP 8.3 / Laravel 13 / highest leg.
- `phpunit.xml.dist` pins `zend.exception_ignore_args=0` and
  `zend.exception_string_param_max_len=15`. Without both, any test asserting a
  value is *absent* from a stack trace is vacuous in the direction that passes.
- Guards for five rules the package stated and nothing enforced. The facade's
  `@method static` tags are checked against the client's real signatures by
  reflection — a **stale** tag is worse than a missing one, since PHPStan trusts
  what it is given and would confidently analyse merchant code against a
  signature the client no longer has. Every exception in `src/` must come from a
  named factory rather than a `throw new` at the call site. The environment rule
  now covers every spelling of itself — `env()`, `getenv()`,
  `Illuminate\Support\Env`, and the `$_ENV`/`$_SERVER` superglobals, which no
  architecture expectation can name — rather than the single spelling it pinned
  before. And an inline PHPStan suppression must name the identifier it
  suppresses and carry a reason, with the analysis level, the absence of a
  baseline, and the set of analysed paths asserted alongside it. Every subject
  list is derived at test time from the autoload map, reflection or the
  filesystem; none is written down.
- `config/ameriabank-vpos.php` is analysed by PHPStan at level 10. It ships, it
  is the one place `env()` is permitted, and `env()` returns `mixed`, so every
  value the service provider consumes originated somewhere nothing type-checked.
  Larastan is told where the package's config directory is, since `config_path()`
  resolves to an application path that a package does not have.
- A restored `ConfigurationException` carries a filtered stack trace instead of
  the restore site's. `unserialize()` runs no constructor, so the engine left the
  trace describing whichever worker happened to read the payload — including the
  arguments of its frames. The published trace keeps only `file`, `line`,
  `function`, `class` and `type`, and each is dropped unless its value has the
  type `getTraceAsString()` expects to read back, so a malformed payload degrades
  rather than raising a warning on the first read. Dropping the arguments is also
  what makes a trace holding framework closures serializable at all. Omitting the
  trace had never withheld it: the engine supplied the restore site's either way,
  so the omission *was* the leak.

### Notes

- Requires PHP `^8.3` and Laravel 12 or 13. Laravel 11 and below are not
  supported: Laravel 11's security support ended on 2026-03-11, and advertising
  support for an unpatched framework in a payment package is the wrong signal.
- **The callback parameters the gateway puts on `back_url` are unsigned and
  forgeable.** `Vpos::verify()` is the only route to a payment's outcome. This
  package exposes no way to read a status out of the callback, because there is
  no safe way to read one.
- Two of Laravel's default global middleware rewrite the callback before any
  binding sees it. `TrimStrings` removes the trailing space from the
  `Operation Approved ` diagnostic the core client passes through untouched, and
  `ConvertEmptyStringsToNull` collapses the `description=` versus absent
  distinction the core deliberately preserves. Neither affects `verify()`, which
  reads identifiers only.
- Nothing is validated while the application boots. Configuration is read inside
  container closures, so an application without vPOS credentials still boots,
  migrates and runs its queue workers; a missing credential surfaces on first
  resolution instead.
- An unrecognised `environment` value throws and names itself. There is no
  default that silently selects an environment — a typo pointing a live shop at
  the sandbox would take no money and would only be found at reconciliation.
- Logging is opt-in and off by default; when disabled the client is given a
  `NullLogger` rather than a silenced channel.
- No migrations, models, routes, middleware, Form Requests or JSON Resources.
  This package wires the core client into Laravel and re-implements none of its
  gateway operations.

[Unreleased]: https://github.com/davit-vardanyan/ameriabank-vpos-laravel/commits/main
