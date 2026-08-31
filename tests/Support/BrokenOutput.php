<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use RuntimeException;
use Symfony\Component\Console\Output\Output;

/**
 * A console output whose every write fails.
 *
 * `vpos:check` writes two lines before it opens its `try`, and this is what
 * makes that region raise. The shape is not invented for the test: a
 * `StreamOutput` whose stream has gone away throws from `doWrite()` in exactly
 * this position, which is what piping the command into a reader that exits
 * early produces.
 *
 * It is the only provocation available for that region. Everything else the
 * pre-`try` statements do is arithmetic on an option, and nothing there can be
 * made to fail from outside the command.
 *
 * `Output` rather than `StreamOutput` on a closed handle: the abstract base
 * asks for `doWrite()` and nothing else, so the failure is raised at the exact
 * depth a stream failure would be raised at, without the test depending on how
 * a particular PHP build reacts to writing to a closed resource.
 */
final class BrokenOutput extends Output
{
    /**
     * The message this output fails with, so a caller can recognise its own
     * provocation rather than matching a sentence written twice.
     */
    public static function failureMessage(): string
    {
        return 'Unable to write output.';
    }

    protected function doWrite(string $message, bool $newline): void
    {
        throw new RuntimeException(self::failureMessage());
    }
}
