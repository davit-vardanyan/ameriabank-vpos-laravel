<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;

/*
 * Each expectation is passed to arch() as a closure rather than chained off its
 * return value.
 *
 * arch() returns Pest\PendingCalls\TestCall, which resolves expect() through
 * __call(). PHPStan reports magic methods from level 7 upwards, so the chained
 * form ("arch(...)->expect(...)") fails static analysis at level 10 with
 * method.notFound and leaves every subsequent call typed as mixed. Inside a
 * closure, expect() returns Pest\Expectation, whose arch expectations are
 * declared methods, so the analysis is exact. The expectations themselves are
 * unchanged.
 */

arch('everything in src is final', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel')
        ->classes()
        ->toBeFinal();
});

arch('src declares strict types', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel')
        ->toUseStrictTypes();
});

arch('nothing debugs in production', function (): void {
    expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
        ->not->toBeUsed();
});

/*
 * The environment rule that used to live here now lives in
 * tests/Arch/EnvironmentAccessTest.php. It was widened from the single `env`
 * spelling to every spelling of the same read, and the superglobal half of it
 * needs a tokenised sweep that no arch() expectation can express, so the whole
 * rule moved rather than being held in two halves in two files.
 */

arch('the exception namespace holds nothing but exceptions', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Exception')
        ->toImplement(Throwable::class)
        ->toHaveSuffix('Exception');
});

arch('the facade namespace holds nothing but facades', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Facades')
        ->toExtend(Facade::class);
});

arch('the command namespace holds nothing but artisan commands', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Commands')
        ->toExtend(Command::class)
        ->toHaveSuffix('Command');
});
