<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;

class CreateMeetingLinkAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): SalesMeetingLink
    {
        $attributes['working_hours'] = MeetingAvailability::normalizeWorkingHours($attributes['working_hours'] ?? null);

        return DB::transaction(fn (): SalesMeetingLink => SalesMeetingLink::create($attributes));
    }
}
