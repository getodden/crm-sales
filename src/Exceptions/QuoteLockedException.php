<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

/**
 * Thrown when something tries to change the status, lines, discount or tax of a quote the customer has signed
 * (the signature covers those figures, so they stay as they were), or to mark a quote accepted without a signature.
 */
class QuoteLockedException extends LogicException
{
    public static function signed(): self
    {
        return new self('This quote has been signed, so its status, items, discount and tax can no longer be changed. Create a new quote instead.');
    }

    public static function unsigned(): self
    {
        return new self('A quote becomes accepted when the customer signs it. Use the signing link, or accept it on their behalf.');
    }
}
