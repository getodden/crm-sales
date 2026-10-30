<?php

declare(strict_types=1);

namespace Odden\Sales\Enums;

/**
 * Who cancelled a meeting booking: the person who booked it, or someone on the team.
 */
enum MeetingCancelledBy: string
{
    case Invitee = 'invitee';
    case Staff = 'staff';
}
