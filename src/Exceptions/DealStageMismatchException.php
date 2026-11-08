<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

class DealStageMismatchException extends LogicException
{
    public static function notOpenStageOfPipeline(): self
    {
        return new self('A new deal must start in an open stage of its own pipeline.');
    }
}
