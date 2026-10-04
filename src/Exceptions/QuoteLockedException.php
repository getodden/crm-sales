<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

/**
 * Thrown when something tries to change the lines, discount or tax of a quote the customer has signed:
 * the signature covers those figures, so they stay as they were.
 */
class QuoteLockedException extends LogicException
{
    public static function signed(): self
    {
        return new self('This quote has been signed, so its items, discount and tax can no longer be changed. Create a new quote instead.');
    }
}
