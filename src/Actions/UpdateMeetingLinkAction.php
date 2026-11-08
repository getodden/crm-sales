<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;

class UpdateMeetingLinkAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(SalesMeetingLink $link, array $attributes): SalesMeetingLink
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($link);

        if (array_key_exists('working_hours', $attributes)) {
            $attributes['working_hours'] = MeetingAvailability::normalizeWorkingHours($attributes['working_hours']);
        }

        return DB::transaction(function () use ($link, $attributes): SalesMeetingLink {
            $link->fill($attributes)->save();

            return $link;
        });
    }
}
