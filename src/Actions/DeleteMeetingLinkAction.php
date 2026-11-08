<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Exceptions\MeetingLinkInUseException;
use Odden\Sales\Models\SalesMeetingLink;

class DeleteMeetingLinkAction
{
    /**
     * @throws MeetingLinkInUseException
     */
    public function execute(SalesMeetingLink $link): bool
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($link);

        return DB::transaction(fn (): bool => (bool) $link->delete());
    }
}
