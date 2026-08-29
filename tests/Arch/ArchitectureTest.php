<?php

declare(strict_types=1);

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
