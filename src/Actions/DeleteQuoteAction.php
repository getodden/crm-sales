<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Exceptions\QuoteLockedException;
use Odden\Sales\Models\Quote;

class DeleteQuoteAction
{
    /**
     * @throws QuoteLockedException
     */
    public function execute(Quote $quote): bool
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($quote);

        if ($quote->status->isAccepted()) {
            throw QuoteLockedException::signedCannotBeDeleted();
        }

        return DB::transaction(fn (): bool => (bool) $quote->delete());
    }
}
