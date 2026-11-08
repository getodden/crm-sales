<?php

declare(strict_types=1);

namespace Odden\Sales\Enums;

enum CadenceStepOutcome: string
{
    case Advanced = 'advanced';
    case NoLongerActive = 'no_longer_active';
    case EmailStep = 'email_step';
    case AlreadyHandled = 'already_handled';
}
