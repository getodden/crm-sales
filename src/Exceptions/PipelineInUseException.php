<?php

declare(strict_types=1);

namespace Odden\Sales\Exceptions;

use LogicException;

/**
 * Thrown when something tries to delete a pipeline or stage that deals or stage history still refer to:
 * deleting it would take those deals, their quotes and their history with it.
 */
class PipelineInUseException extends LogicException
{
    public static function stage(): self
    {
        return new self('This stage has deals or stage history, so it cannot be deleted. Move its deals to another stage first.');
    }

    public static function pipeline(): self
    {
        return new self('This pipeline has deals or stage history, so it cannot be deleted. Deactivate it instead.');
    }
}
