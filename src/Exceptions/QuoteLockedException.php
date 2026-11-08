<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

class QuoteLockedException extends LogicException
{
    public static function signed(): self
    {
        return new self('This quote has been signed, so its status, items, discount and tax can no longer be changed. Create a new quote instead.');
    }

    public static function signedCannotBeDeleted(): self
    {
        return new self('This quote has been signed, so it is the record of what the customer agreed to and cannot be deleted.');
    }

    public static function unsigned(): self
    {
        return new self('A quote becomes accepted when the customer signs it. Use the signing link, or accept it on their behalf.');
    }
}
