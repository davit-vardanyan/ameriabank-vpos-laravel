**This is an unofficial package. It is not affiliated with, endorsed by, or supported by Ameriabank.**

# ameriabank-vpos-laravel

Laravel integration for [`davit-vardanyan/ameriabank-vpos-php`](https://github.com/davit-vardanyan/ameriabank-vpos-php),
an unofficial client for the Ameriabank vPOS 3.1 payment gateway.

[![CI](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/ci.yml)
[![Static analysis](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/static.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-laravel/actions/workflows/static.yml)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> **Nothing has been tagged yet**, so there is no stable public API to depend on.
> Everything documented below exists and is covered by tests; none of it is
> promised across a version that does not exist.

## What this package is for

The core client is framework-agnostic by design: PSR-18 transport, constructor
injection, no container, no configuration. That is the right shape for a
library, and the wrong shape for dropping into a Laravel application, where you
would rather read credentials from config, resolve a client from the container,
and let package discovery wire it up.

This package is that bridge, and only that. It wires the core client into
Laravel; it does not wrap, re-expose or re-implement a single gateway operation.
Everything you call is the core client's own API, documented in [its
README](https://github.com/davit-vardanyan/ameriabank-vpos-php).

There are no migrations, no models, no routes, no middleware and no views. A
bridge wires a client; it does not scaffold a checkout.

## Requirements

- PHP `^8.3`
- Laravel **12** or **13**
- [`davit-vardanyan/ameriabank-vpos-php`](https://github.com/davit-vardanyan/ameriabank-vpos-php) `^1.0.1`

Laravel 11 and below are **not** supported. Laravel 11's security support ended
on 2026-03-11, and advertising support for an unpatched framework in a payment
package is the wrong signal to send. Laravel 12 keeps security support until
2027-02-24.

## Installation

```bash
composer require davit-vardanyan/ameriabank-vpos-laravel
```

The service provider and the `Vpos` facade alias are registered automatically
through Laravel's package discovery. There is nothing to add to
`bootstrap/providers.php`.

### The HTTP client

The core client is PSR-18 based and ships no HTTP client of its own. If your
application already has a PSR-18 implementation installed, the core discovers it
through `php-http/discovery`; if it finds none, the first resolution throws the
core's `ConfigurationException` rather than failing later and less clearly.

### Choosing the client your card data goes through

This package looks in three places, in this order, and stops at the first one
that answers:

| | Where it looks | Who else uses it |
|---|---|---|
| 1 | the container key `ameriabank-vpos.http-client` | nothing — this package only |
| 2 | the container binding for `Psr\Http\Client\ClientInterface` | **your whole application** |
| 3 | nowhere — the core runs `php-http/discovery` | n/a |

**Tier 2 is application-wide, and it is shared with everything else.** Laravel's
`bound()` answers true for a binding, an instance or an alias, so any package,
provider or test that binds `Psr\Http\Client\ClientInterface` hands its client
to this one as well — and the vPOS credential payload goes out through it. That
is fine when the binding is a plain client. It is not fine when it carries a
base URI, default headers, an auth middleware or a proxy for some other API,
because none of that was meant for the bank.

Tier 1 is how you say which client is meant for the bank without touching the
shared one:

```php
use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

$this->app->singleton(
    AmeriabankVposServiceProvider::HTTP_CLIENT_KEY,
    static fn (): ClientInterface => new Client(['timeout' => 30]),
);
```

The key is a **container key, not a configuration key.** It is not read from
`config/ameriabank-vpos.php` and does not appear there: configuration holds
values that survive `config:cache`, and an HTTP client is an object that does
not. Note also that the string contains a dot, so had it been a configuration
path it would have read as `http-client` *nested under* `ameriabank-vpos` — a
different thing entirely, and one this package never reads. Bind it in a
service provider. Cite the constant rather than retyping the string, so that a
typo is an error where you wrote it instead of a binding that is quietly never
found.

Anything bound under tier 1 that is not a `Psr\Http\Client\ClientInterface` is
refused when the client is first resolved, naming the key and the type it found.
It is not skipped: falling through to tier 2 would send payment traffic to the
client you had just said you did not want.

Tier 2 still works exactly as before — bind `Psr\Http\Client\ClientInterface`
and this package uses it — and it remains the simplest way for a test to
substitute a fake.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ameriabank-vpos-config
```

Publishing is optional. The packaged defaults are merged in `register()`, so an
application that sets the environment variables below works without publishing
anything.

```dotenv
AMERIABANK_VPOS_CLIENT_ID=00000000-0000-0000-0000-000000000000
AMERIABANK_VPOS_USERNAME=example-username
AMERIABANK_VPOS_PASSWORD=example-password
AMERIABANK_VPOS_ENVIRONMENT=test
AMERIABANK_VPOS_BACK_URL=checkout.vpos.back
AMERIABANK_VPOS_MAX_ATTEMPTS=3
AMERIABANK_VPOS_LOGGING=false
AMERIABANK_VPOS_LOG_CHANNEL=
```

| Key | Environment variable | Notes |
|---|---|---|
| `client_id` | `AMERIABANK_VPOS_CLIENT_ID` | Issued by the bank, per environment |
| `username` | `AMERIABANK_VPOS_USERNAME` | Issued by the bank, per environment |
| `password` | `AMERIABANK_VPOS_PASSWORD` | Issued by the bank, per environment |
| `environment` | `AMERIABANK_VPOS_ENVIRONMENT` | `test` or `production`. Anything else throws |
| `back_url` | `AMERIABANK_VPOS_BACK_URL` | An absolute `http`/`https` URL, or the name of one of your routes |
| `max_attempts` | `AMERIABANK_VPOS_MAX_ATTEMPTS` | Attempt budget for retryable operations, `1`–`5` |
| `logging.enabled` | `AMERIABANK_VPOS_LOGGING` | Off by default |
| `logging.channel` | `AMERIABANK_VPOS_LOG_CHANNEL` | A named log channel, or blank for your default |

### Nothing is validated while the application boots

Every configuration value is read inside a container closure, so an application
with no vPOS credentials still boots, still migrates and still runs its queue
workers. A missing credential surfaces the first time something asks for the
client — in the request that needed it, naming the field that was blank.

This is deliberate. Validating in `boot()` would mean a container that cannot
run `php artisan migrate` until it has been given payment credentials it is
never going to use.

### The environment is never guessed

`environment` accepts `test` or `production`. Any other value — including a typo,
including blank — throws a `ConfigurationException` that names what was
configured. There is no default that silently selects an environment.

A typo that quietly pointed a live shop at the sandbox would take no money and
would only be discovered at reconciliation, weeks later. That is the failure this
refusal exists to prevent, and it is worth an exception on a misspelt value.

### `back_url` takes a URL or a route name

If the configured value carries an `http` or `https` scheme it is used as it
stands, untrimmed and un-normalised. Anything else is treated as a route name and
resolved through Laravel's URL generator, which is the better answer: a route
name survives a domain change, a path rename and a move behind a load balancer.

A route name the application has not registered throws, and the message names the
value — a route name and a typo of one look identical, so the value is the only
useful thing the message can say. A blank `back_url` throws too. It is never
allowed to fall through to an empty `BackURL`, because the gateway accepts an
empty one and then sends the paying customer nowhere.

A route name that *is* registered but whose route declares required parameters —
`/checkout/{order}/vpos/back`, say — throws as well, with its own message rather
than the one above: the name is not misspelt, the route is the wrong one to point
at. Nothing supplies those parameters here, because the `BackURL` is the address
the gateway returns the customer to and it has to resolve on its own. Register a
route that takes none, or configure an absolute URL.

The scheme test is case-sensitive on purpose. `HTTPS://…` is read as a route
name and produces a message naming the value, which says more than quietly
accepting a spelling nothing else in the application uses.

Resolution happens when the value is needed, never while providers register —
routes are not loaded yet at that point.

### Build the `BackURL` you send with the resolver

`BackUrlResolver` is public API. **`app(BackUrlResolver::class)->resolve()` is
the supported way to build the `backUrl` argument for `InitPaymentRequest`** —
or type-hint the class in a constructor, which resolves the same binding and is
what the controller example below does.

The core's `InitPaymentRequest` takes `backUrl` as a required constructor
argument, so passing `route('checkout.vpos.back')` there instead leaves
`ameriabank-vpos.back_url` read by no code path a payment executes: the key is
then inert for real traffic while `vpos:check` still refuses to run without it,
and the two values can drift apart without anything noticing. A config naming
one route and a controller passing another gives you a `vpos:check` reporting a
`BackURL` no payment will ever carry.

Resolving both through the same class is what keeps them the same value. It
does not make the gateway agree with either — the resolver checks that the
value is a URL or a route this application has registered, and nothing more.

### Logging is off unless you turn it on

With `logging.enabled` off, the client is given a `NullLogger`: nothing is
formatted, filtered or handed to a handler at all. With it on, records go to the
named channel, or to your default channel when no name is given.

The core client redacts credentials, card numbers, the processing IP and the
cardholder name before anything reaches a record. A payment package should still
not write to a log unless it was asked to, so the default is off.

## What gets bound

| Binding | Shape |
|---|---|
| `DavitVardanyan\AmeriabankVpos\Vpos` | singleton |
| `DavitVardanyan\AmeriabankVpos\Client\PaymentsClient` | resolved from that singleton |
| `DavitVardanyan\AmeriabankVpos\Client\BindingsClient` | resolved from that singleton |
| `DavitVardanyan\AmeriabankVpos\Client\ReportsClient` | resolved from that singleton |
| `DavitVardanyan\AmeriabankVpos\Callback\VposCallback` | built from the current request's query string |
| `DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver` | rebuilt on each resolution |

`Vpos` is a singleton because it owns the transport and the discovered PSR-18
client, and an application wants one of those.

The three operation clients are **not** registered as singletons — and they are
not rebuilt either. Each resolution asks the one `Vpos` instance for the handle
it built in its own constructor, so resolving `PaymentsClient` twice returns the
same object. The container does not share them; the client already does. Binding
them as singletons would add a second layer of caching and one more thing to
reason about when a test swaps one.

`BackUrlResolver` is not a singleton either, for a different reason: it owns
nothing worth sharing, and it reads `back_url` from whichever configuration
repository the container holds when you call `resolve()`. A cached instance
would hold the repository it was built with, so an application that replaces
its configuration — a long-lived worker reloading it, a test rebinding it —
could go on resolving a `BackURL` from a configuration no longer in force.

## Usage

### Constructor injection is the documented route

Type-hint the client you need. This is what the individual bindings exist for,
and it is the shape that stays testable without reaching for the container.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Http\RedirectResponse;

final class StartPaymentController
{
    public function __construct(
        private readonly PaymentsClient $payments,
        private readonly Vpos $vpos,
        private readonly BackUrlResolver $backUrl,
    ) {}

    public function __invoke(): RedirectResponse
    {
        $init = $this->payments->init(new InitPaymentRequest(
            amount: Amount::fromMinorUnits(1000, Currency::AMD),  // 10.00 AMD
            orderId: 1749,                                        // your own order number
            backUrl: $this->backUrl->resolve(),                   // your configured back_url
            description: 'Order 1749',
            opaque: 'basket-1749',                                // echoed back to you untouched
            timeout: 900,                                         // payment page valid for 15 minutes
        ));

        return redirect()->away(
            $this->vpos->paymentPageUrl($init->paymentId ?? '', Language::Armenian),
        );
    }
}
```

`$this->backUrl->resolve()` returns the configured `back_url` — the absolute URL
as it stands, or the named route resolved through Laravel's URL generator — and
throws a `ConfigurationException` naming the value rather than handing the
gateway a blank `BackURL`. It is the same value `vpos:check` reports, which is
the point of resolving it here rather than calling `route()` again. Where you
are not in a constructor, `app(BackUrlResolver::class)->resolve()` is the same
binding.

`Amount` holds an integer minor-unit count and a `Currency`; there is no
constructor taking a float. `paymentPageUrl()` refuses a blank `PaymentID`
rather than building a broken page — which matters, because a failed
`InitPayment` answers with an empty-string `PaymentID` and not with null.

### The facade is the convenience

```php
use DavitVardanyan\AmeriabankVpos\Laravel\Facades\Vpos;

Vpos::payments()->details($paymentId);
Vpos::verify($callback);
Vpos::paymentPageUrl($paymentId);
```

The facade resolves the same singleton the container does, so a test that swaps
the binding swaps what the facade returns as well. It carries `@method static`
annotations for `payments()`, `bindings()`, `reports()`, `verify()` and
`paymentPageUrl()`, so static analysis and editors see real types through it
rather than `mixed`.

Use it where reaching for the container costs more than it returns. Constructor
injection is still the recommendation.

## The callback: unsigned, forgeable, and not an answer

**The parameters the gateway puts on your `back_url` are unsigned. There is no
HMAC, no signature and no shared secret anywhere in that callback, so anyone who
can type a URL can send you one that looks like a successful payment for an
order nobody paid for.**

**`Vpos::verify()` is the only route to a payment's outcome.** Treat the callback
as a notification that *something* happened, take its identifiers as lookup keys,
and ask the gateway what actually occurred. This package deliberately gives you
no way to read a status out of the callback, because there is no safe way to read
one.

`VposCallback` is bound to the current request, so a controller handling the
`back_url` can simply take it as a parameter:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Http\RedirectResponse;

final class VposCallbackController
{
    public function __construct(private readonly Vpos $vpos) {}

    public function __invoke(VposCallback $callback): RedirectResponse
    {
        try {
            // The round trip that actually answers. Never the query string.
            $details = $this->vpos->verify($callback);
        } catch (VposExceptionInterface $failure) {
            // An unusable callback, an OrderID that is not the callback's, or a
            // gateway that would not answer. The payment is unconfirmed:
            // reconcile it later and release nothing now.
            report($failure);

            return redirect()->route('checkout.pending');
        }

        $details->orderId;            // ?string — the order the gateway says this payment is for
        $details->orderStatus;        // ?OrderStatus — null when the raw value is one the client does not know
        $details->approvedAmountRaw;  // ?string — the authorised total, to compare against your own
        $details->trxnDescription;    // ?string — the Description you submitted
        $details->description;        // ?string — the processor's own wording, not yours

        return redirect()->route('checkout.done');
    }
}
```

Resolving `VposCallback` anywhere the gateway did not redirect to — a console
command, a queued job, an ordinary page — throws a `ConfigurationException`
saying so, keeping the core client's own message (which names the missing
parameter) as its cause. `VposCallback::fromQuery()` remains available where you
want to build one explicitly.

### Two things Laravel does to the callback before you see it

Both are the framework's default global middleware, not this package, and neither
is discoverable until it bites.

**`TrimStrings` rewrites the gateway's text.** The core client records
`description` arriving as `Operation Approved ` — with a trailing space,
byte-identical across both successful callbacks on record — and passes it through
untouched. In a default Laravel application the trailing space is gone before any
binding sees the query, so a merchant comparing that exact string will not find
it. Log the diagnostics; do not match on them. If you need what the gateway
actually sent, exempt your callback route from `TrimStrings`.

**`ConvertEmptyStringsToNull` collapses `description=` into an absent
`description`.** The core deliberately keeps those two apart: the gateway sending
an empty diagnostic is not the same event as the gateway omitting it, and it
preserves the difference as `''` versus `null`. Under the default middleware
stack the distinction is gone before this package can see it. Exempt the route if
that signal matters to you.

Neither trap affects `verify()`. It reads identifiers, and the middleware does
not touch those.

## `php artisan vpos:check`

```bash
# Preferred. Asks about an order you registered yourself.
php artisan vpos:check --order-id=1749

# Blind. Detects a rejection and nothing else.
php artisan vpos:check
```

It prints the resolved environment, the gateway base URL, a truncated ClientID
and username, whether a password is set, and the resolved `BackURL` — and then
**makes one real HTTP request** to the gateway: a single `GetPaymentId` call for
one `OrderID`. A configuration that will not resolve is refused before anything
is sent.

### The gateway offers no reliable credential check

State this first, because everything below is shaped by it. **No Ameriabank vPOS
operation this package can safely call reliably distinguishes valid credentials
from invalid ones.** On the one endpoint where the question has been studied, a
wrong password has been observed producing the same gateway fault envelope that
correct credentials produced on that same payment, and the same response code
`550` that correct credentials produced on another — so neither reply separates
the two, and neither can be read as a pass.

`InitPayment` is the only operation with an unambiguous credential rejection, and
it is **barred from a diagnostic because it registers a real order**. A command
that checks your credentials by creating an order is not a check.

**No probe has ever reached either production host.** Every observation this
command rests on comes from the sandbox, so a `vpos:check` result against
`production` has no observational backing at all.

The command prints both caveats itself rather than leaving them in this file: the
standing one on every run, including the run that rejects a malformed
`--order-id` without sending anything, and the production one beside the verdict
whenever the resolved environment is `production`.

### The two modes

**`--order-id=<an order you registered>` — prefer this one.** It lands in the one
cell where a paired experiment exists: for an order the merchant owns, correct
credentials answered a success code and a `PaymentId`, and a wrong password
answered response code 20. A success code here is genuine evidence — the gateway
authenticated this ClientID, username and password and looked that order up under
them.

That rests on a premise the command cannot check: that the `OrderID` you pass is
one **you** registered, under **this** ClientID. Pass an arbitrary number and you
are back in the blind cell below, holding an exit 0. The command says so in the
verdict, and the answer is to pass an order you own.

**Blind (no option) — rejection-detection only.** The probe asks about a sentinel
`OrderID` no merchant can own; it is negative, and merchants number orders from
an ascending counter. Response code 20 is still **read as** a rejection, and the
gateway's own message is printed with it — that is an inference rather than an
observation, since only a known `OrderID` has ever been observed being answered
20, and it is the safe direction for a diagnostic to take. A success code proves
**nothing**, because what the gateway answers for an `OrderID` it does not know
has never been observed under *either* credential state — there is nothing to
compare the reply against.

Use `--order-id` whenever you have an order number to hand. It is the only mode
that can return a positive answer at all, and every inconclusive row of a blind
run says so on the line that reports it — the reminder is printed beside the
verdict rather than only in the mode note above it, because that line is the one
that gets read.

### What each answer establishes

| Mode | Gateway answer | What it proves | Exit |
|---|---|---|---|
| `--order-id` | a success code | the credentials **are valid** | 0 |
| `--order-id` | response code 20 | the credentials **are rejected** | 1 |
| blind | a success code | **nothing** — inconclusive | 2 |
| either | response code 20, as the integer `20` or the string `"20"` | the credentials **are rejected** | 1 |
| either | a gateway fault envelope | nothing — on the one endpoint where it was studied, a wrong password produced the same fault as correct credentials | 2 |
| either | response code `550` | nothing — `550` is overloaded, and on that same endpoint a wrong password produced it too | 2 |
| either | any other response code | nothing; the code and the gateway's own message are reported | 2 |
| either | the gateway could not be reached | nothing; nothing arrived | 2 |
| either | a reply this client cannot read | nothing | 2 |
| either | any other failure, named by its class | nothing; the run stopped before it could establish anything | 2 |
| either | something vPOS needs would not resolve — a setting, an out-of-range `max_attempts`, or the package-scoped HTTP client binding | that the setup is wrong, and which key or binding is at fault | 1 |
| either | the client refused something else | nothing about *what* was refused, or whether it was configured at all; the client's own refusal is printed, and no key is named or guessed | 1 |
| neither | `--order-id` was given something that is not an integer | nothing; nothing was sent | 2 |

**Exit 0 only when the credentials are proven valid. Exit 1 only when they are
proven rejected. Everything else is exit 2.**

Exit 2 is a separate code rather than exit 0 with a caveat in the output, because
**a CI pipeline reads the exit code, not the prose**. `vpos:check && deploy` must
not pass on a probe that established nothing: a merchant with a typo'd password
would see a green pipeline and deploy. A caveat only a human reads is not a
result a script can act on.

### Why the probe is `GetPaymentId`

The decisive reason is not how well the candidates discriminate a bad credential.
It is that **`GetPaymentDetails` never puts the ClientID on the wire at all** —
its request declares that it does not require one — so a merchant who has typed
their ClientID wrongly is *structurally undetectable* on it, under any gateway
behaviour rather than merely under the behaviours anyone has observed.
`GetPaymentId` sends the ClientID.

Secondarily, `GetPaymentId` is also the only operation ever observed returning a
genuine credential rejection, and this command reads that rejection in both wire
forms: the integer `20` the core client classifies, and the string `"20"` this
endpoint has actually been seen to send.

### What is never printed

Nothing secret, and nothing belonging to a real order. The password is reported
only as `(set)`, `(not set)` or `(not a string)`, and is never read into a
message; the ClientID and username are truncated to their first four characters
— or reported as `(not set)` or `(not a string)` when there is nothing to
truncate — which is enough to tell two credential sets apart in a screenshot and
not enough to use. `(not a string)` says the key is set to something that is not
a string, and says nothing further about it: like `(set)`, it discloses that the
key holds a value and not what that value is. **The `PaymentId` the gateway
returns is never printed, in either mode** — it identifies a real payment, and
this output goes into terminals and CI logs. A
reply the client cannot parse is not echoed either: a raw response body is
unvalidated remote content.

## Credentials

Never commit vPOS credentials. Keep the ClientID, username and password in the
environment, out of version control, and out of logs, exception messages and bug
reports. The bank issues a separate set per environment, and a set that works on
the sandbox is rejected in production.

Where an example needs a ClientID, this project uses an all-zero GUID —
`00000000-0000-0000-0000-000000000000`. That is a placeholder chosen because it
cannot be a real credential; it is **not** a claim about the format the bank
issues.

### Stack traces, and what an error reporter can reach

Logs, exception messages and bug reports are three places a credential must not
appear. A stack trace is a fourth, and it does not behave like the other three,
because it has two forms that disclose different amounts.

**The string form is clean.** `getTraceAsString()` renders an object argument as
`Object(Illuminate\Config\Repository)` — it names the class and never walks into
it. That is the form Laravel's default log formatter writes to
`storage/logs/laravel.log`, so a refusal that is merely logged carries no
credential. Measured on PHP 8.3.28 and 8.5.7, under both
`zend.exception_ignore_args` settings.

**The array form is not.** `getTrace()` frame arguments are the live objects, so
an error reporter that walks the trace itself and serialises what it finds there
reads whatever those objects hold. Sentry and Flare can both be configured to do
exactly that, and an error reporter is a realistic thing for a merchant taking
payments to have.

This package keeps the frames it declares clear of it. No method here that can
raise a refusal takes the configuration repository — or the container, which
reaches it in one hop — as an argument. The reader that resolves a key holds the
repository on the instance and takes only the key, and `Exception::getTrace()`
produces no `object` key at all, so the instance holding it is not in the trace
either. Two constructors do accept the repository, and neither of them throws.

**What no package can control is the frames the framework builds — and one of
those is in this package's own traces.** The container hands itself to the
closure a service provider registers, whatever that closure declares, so a
refusal raised while one of the bindings above resolves carries a
`AmeriabankVposServiceProvider::{closure}` frame with an
`Illuminate\Foundation\Application` argument in it. That frame is the
framework's rather than this package's, and no parameter list removes it. A
container reaches the configuration repository, so a reporter walking a trace
far enough and deep enough can still arrive at your credentials — through that
frame, and from an exception raised anywhere in the application, not only from
this package.

Two settings close that, and neither belongs to this package:

- `zend.exception_ignore_args`, which PHP's own `php.ini-production` turns
  **on**, removes `args` from every frame and so closes it for every package at
  once;
- otherwise it is your reporter's own scrubbing configuration — Sentry's
  `before_send` and `send_default_pii`, Flare's censoring options. Confirm the
  result on a test event rather than assuming it.

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

`composer mutate` runs `pest --mutate --path=src --min=100`. The floor lives in
the script rather than in a flag a contributor can pass or forget, and there is
no carve-out for a run that produced no mutants — a perfect score over an empty
set is green for the wrong reason, which is the direction that hides defects.
Every mutant generated from `src/` is killed.

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
