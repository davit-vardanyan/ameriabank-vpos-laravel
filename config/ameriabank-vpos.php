<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ameriabank vPOS
|--------------------------------------------------------------------------
|
| Credentials and settings for the unofficial Ameriabank vPOS 3.1 client.
|
| This file is the only place in the package that calls env(). Once an
| application caches its configuration, env() returns null everywhere else,
| so reading the environment anywhere but here is a correctness bug rather
| than a style preference.
|
| Nothing here is validated at boot. A misconfigured value surfaces the first
| time the client is resolved, so an application with no vPOS credentials can
| still run migrations, queue workers and console commands.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Issued by the bank. All three are required, and a blank one is refused
    | when the client is first resolved — never silently sent to the gateway,
    | which would answer response code 20 without saying which field was wrong.
    |
    */

    'client_id' => env('AMERIABANK_VPOS_CLIENT_ID'),

    'username' => env('AMERIABANK_VPOS_USERNAME'),

    'password' => env('AMERIABANK_VPOS_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Either "test" or "production". An unrecognised value throws and names
    | itself; it is never coerced into a working default. A typo that quietly
    | pointed a live shop at the sandbox would take no money and would only be
    | discovered at reconciliation.
    |
    */

    'environment' => env('AMERIABANK_VPOS_ENVIRONMENT', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Back URL
    |--------------------------------------------------------------------------
    |
    | Where the gateway sends the customer's browser after the payment page.
    | Either an absolute http/https URL or the name of one of your routes,
    | which is resolved through route() when it is needed — not at boot, since
    | routes are not loaded while service providers register.
    |
    | The callback that arrives at this URL is unsigned and forgeable. It is a
    | notification, never a verdict.
    |
    */

    'back_url' => env('AMERIABANK_VPOS_BACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Attempt budget
    |--------------------------------------------------------------------------
    |
    | How many attempts a retryable operation gets, bounded by the client at
    | 1..5. This is not a retry policy: which operations may be retried at all
    | is fixed by the client and is not configurable from here.
    |
    */

    'max_attempts' => (int) env('AMERIABANK_VPOS_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled, request and response records go to the
    | named channel, or to your default channel when none is named. The client
    | redacts credentials, card numbers, the processing IP and the cardholder
    | name before they reach a record, but a payment package should still not
    | write to a log unless it was asked to.
    |
    */

    'logging' => [

        'enabled' => (bool) env('AMERIABANK_VPOS_LOGGING', false),

        'channel' => env('AMERIABANK_VPOS_LOG_CHANNEL'),

    ],

];
